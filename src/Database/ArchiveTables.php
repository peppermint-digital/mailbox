<?php

namespace Peppermint\Mailbox\Database;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Die Ablage: eine Nachricht, ihre Orte, ihr Gespraechstext.
 *
 * Drei Tabellen, weil drei verschiedene Dinge gespeichert werden — und die
 * Trennung IST die Loesung fuer das Problem, an dem eine einzige Tabelle
 * scheitert.
 *
 * ## Die Kennung ist die Message-ID, nicht der Ort
 *
 * Fast jedes Mailsystem zeigt auf `(Ordner, UID)`. Das ist keine Kennung,
 * sondern eine Ortsangabe, und sie ist aus drei Gruenden fluechtig:
 *
 * - Wer eine Nachricht **verschiebt**, bekommt im Zielordner eine NEUE UID.
 *   Der Verweis zeigt ins Leere, obwohl die Nachricht noch existiert.
 * - Wer sie **loescht**, hinterlaesst dasselbe Loch.
 * - Aendert der Server die **UIDVALIDITY** eines Ordners — nach einem Umzug,
 *   einer Wiederherstellung, manchmal ohne erkennbaren Anlass —, sind ALLE
 *   UIDs dieses Ordners auf einen Schlag ungueltig. Nicht ein Verweis, jeder.
 *
 * Deshalb: Die Nachricht wird ueber `message_id` + Konto identifiziert. Der
 * Ort ist eine Eigenschaft, die sich aendern darf, und steht in einer eigenen
 * Tabelle mit eigener Zeile je Stelle, an der die Nachricht gesehen wurde.
 *
 * Eine Aufgabe, ein Beleg, eine Zuweisung zeigt damit auf etwas, das nicht
 * verschwinden kann.
 *
 * ## Loeschen wird protokolliert, nicht weitergereicht
 *
 * Verschwindet eine Nachricht aus dem Postfach, bekommt der Ort ein `gone_at`
 * und die Nachricht ein `missing_since`. Die Zeile bleibt, die Rohfassung
 * bleibt, der Gespraechstext bleibt.
 *
 * Das ist der Zweck und nicht ein Nebeneffekt: Eine Ablage, die Loeschungen im
 * Postfach nachvollzieht, ist keine Ablage. Wer im Streitfall die
 * unangenehme Nachricht loescht und sie damit auch hier entfernt, hat die
 * ganze Uebung entwertet.
 *
 * Ein ausdruecklicher Loeschweg — etwa fuer eine berechtigte Aufforderung nach
 * DSGVO — bleibt davon unberuehrt. Der Unterschied ist das Ganze: Loeschen als
 * ENTSCHEIDUNG ja, Loeschen als NEBENWIRKUNG eines Klicks nein.
 *
 * ## Warum der Gespraechstext gespeichert und nicht gerechnet wird
 *
 * {@see \Peppermint\Mailbox\Content\Gespraechstext} ist Heuristik. Sie ist
 * teuer genug, um sie nicht bei jedem Blick zu wiederholen — und vor allem
 * verbessert sie sich. Deshalb steht neben dem Ergebnis, mit WELCHER Fassung
 * es entstanden ist: Wird die Erkennung besser, laesst sich gezielt
 * nacharbeiten, statt alles neu zu rechnen oder alles alt zu lassen.
 */
class ArchiveTables
{
    public static function create(): void
    {
        self::messages();
        self::locations();
        self::bodies();
        self::folderStates();
    }

    public static function drop(): void
    {
        // Rueckwaerts: Die Orte und der Text haengen an der Nachricht.
        Schema::dropIfExists(self::name('folder_states', 'mail_folder_states'));
        Schema::dropIfExists(self::name('bodies', 'mail_bodies'));
        Schema::dropIfExists(self::name('locations', 'mail_locations'));
        Schema::dropIfExists(self::name('messages', 'mail_messages'));
    }

    private static function messages(): void
    {
        $name = self::name('messages', 'mail_messages');

        if (Schema::hasTable($name)) {
            return;
        }

        Schema::create($name, function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('email_account_id')->index();

            /*
             * Die Kennung. Laenger als die ueblichen 255 Zeichen, weil RFC 5322
             * keine Grenze setzt und es Absender gibt, die das ausnutzen —
             * eine abgeschnittene Message-ID waere eine falsche Kennung, und
             * das faellt erst auf, wenn zwei Nachrichten sich eine teilen.
             */
            $t->string('message_id', 500);

            /*
             * Der Schluessel fuer den Riegel unten — SHA-256 ueber die
             * Message-ID.
             *
             * Warum nicht direkt auf `message_id`: MySQL kann einen Index nur
             * bis zu einer bestimmten Schluessellaenge anlegen, und 500 Zeichen
             * in utf8mb4 sprengen sie. Der naheliegende Ausweg waere ein
             * Praefix-Index ueber die ersten 191 Zeichen — der macht aus zwei
             * verschiedenen Nachrichten mit gleichem Anfang aber eine einzige,
             * und das faellt niemandem auf.
             *
             * Die Spalte kuerzer zu machen waere derselbe Fehler eine Ebene
             * frueher: RFC 5322 setzt keine Laengengrenze.
             */
            $t->char('message_id_hash', 64);

            // Die Unterhaltung. Null bei Nachrichten, deren Kopfzeilen keine
            // hergeben — die sind dann ihre eigene Wurzel.
            $t->string('thread_key', 500)->nullable()->index();

            /*
             * Ist DIESE Nachricht der Anfang der Kette?
             *
             * Ausgerechnet und gespeichert, nicht beim Lesen verglichen. Der
             * naheliegende Vergleich „Kettenschluessel == Message-ID" geht
             * naemlich daneben: Der Schluessel ist normalisiert (`abc@x`), die
             * Message-ID nicht (`<abc@x>`). Er waere nie wahr — und der
             * Gespraechsverlauf saehe nicht kaputt aus, sondern wie „es gibt
             * eben noch keine vollstaendige Kette".
             */
            $t->boolean('is_root')->default(false)->index();

            $t->string('subject', 1000)->nullable();
            $t->string('from_email', 320)->nullable()->index();
            $t->string('from_name', 255)->nullable();

            // Empfaenger als JSON statt als eigene Tabelle: Sie werden
            // angezeigt und gesucht, nie einzeln verknuepft.
            $t->json('recipients')->nullable();

            $t->timestamp('sent_at')->nullable()->index();
            $t->unsignedInteger('size_bytes')->nullable();
            $t->boolean('has_attachments')->default(false);
            $t->unsignedSmallInteger('attachment_count')->default(0);

            /*
             * Die Rohfassung liegt im Dateisystem, nicht hier.
             *
             * Ein Anhang von zwanzig Megabyte in der Datenbank macht jede
             * Sicherung und jede Abfrage teurer, ohne dass jemals darin
             * gesucht wuerde. Gespeichert wird der Pfad RELATIV zur Wurzel der
             * Ablage — sonst haengt der gesamte Bestand an einem
             * Verzeichnisnamen.
             */
            $t->string('raw_path', 500)->nullable();

            /*
             * Der Hash ueber die Rohbytes, beim Eingang gebildet.
             *
             * Er ist der Grund, warum die Rohfassung Byte fuer Byte gespeichert
             * wird: Fast jede Geschaeftsmail traegt eine DKIM-Signatur, die
             * beweist, dass genau diese Bytes die Domain des Absenders
             * verlassen haben. Sie ueberlebt keine Umwandlung — kein
             * Umkodieren, kein Neu-Zusammensetzen. Eine aufbereitete Fassung
             * ist als Beleg wertlos.
             */
            $t->string('raw_sha256', 64)->nullable()->index();

            // Woher sie kam: abgeholt, vom Server mitgeschnitten, importiert.
            $t->string('source', 16)->default('poll');
            $t->timestamp('captured_at')->nullable();

            /*
             * Wann sie zuletzt im Postfach gesehen wurde — und seit wann nicht
             * mehr. `missing_since` ist eine Feststellung, keine Loeschung.
             */
            $t->timestamp('last_seen_at')->nullable();
            $t->timestamp('missing_since')->nullable()->index();

            $t->timestamps();

            /*
             * Eine Nachricht je Postfach genau einmal.
             *
             * Ohne den Riegel legt jeder zweite Abruf dieselbe Nachricht neu
             * an, und die Ablage waechst, ohne vollstaendiger zu werden.
             */
            $t->unique(['email_account_id', 'message_id_hash'], 'mail_messages_account_message_unique');
        });
    }

    private static function locations(): void
    {
        $name = self::name('locations', 'mail_locations');

        if (Schema::hasTable($name)) {
            return;
        }

        Schema::create($name, function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('mail_message_id')->index();
            $t->unsignedBigInteger('email_account_id')->index();

            $t->string('folder', 500);
            $t->unsignedBigInteger('uid');

            /*
             * Die Gueltigkeitsnummer des Ordners.
             *
             * Sie mitzufuehren ist der Unterschied zwischen „diese UID zeigt
             * woanders hin" und „diese UID ist Muell". Aendert der Server sie,
             * sind alle UIDs des Ordners ungueltig — ohne diese Spalte merkt
             * man das nie und greift jahrelang auf falsche Nachrichten zu.
             */
            $t->unsignedBigInteger('uidvalidity')->nullable();

            $t->timestamp('seen_at')->nullable();
            $t->timestamp('gone_at')->nullable()->index();
            $t->timestamps();

            // Dieselbe Nachricht am selben Ort nur einmal. Zieht sie um und
            // kommt zurueck, ist die alte Zeile bereits mit `gone_at`
            // geschlossen — die Geschichte bleibt lesbar.
            $t->unique(['mail_message_id', 'folder', 'uid'], 'mail_locations_place_unique');
        });
    }

    private static function bodies(): void
    {
        $name = self::name('bodies', 'mail_bodies');

        if (Schema::hasTable($name)) {
            return;
        }

        Schema::create($name, function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('mail_message_id')->unique();

            // Was die Person geschrieben hat — und daneben das, was die
            // Aufbereitung als Beiwerk erkannt hat. Getrennt gespeichert,
            // damit eine danebengegriffene Regel sichtbar bleibt statt Inhalt
            // zu verschlucken.
            $t->longText('content')->nullable();
            $t->longText('quote')->nullable();
            $t->longText('signature')->nullable();
            $t->longText('footer')->nullable();

            /*
             * Mit welcher Fassung der Erkennung das entstanden ist.
             *
             * Die Heuristik wird besser. Ohne diese Spalte hat man danach nur
             * die Wahl zwischen „alles neu rechnen" und „alles alt lassen";
             * mit ihr laesst sich gezielt nacharbeiten, was eine aeltere
             * Fassung gesehen hat.
             */
            $t->string('parser_version', 16)->nullable()->index();
            $t->timestamp('parsed_at')->nullable();
            $t->timestamps();
        });
    }

    /**
     * Was ein Ordner beim letzten Blick fuer ein Zustand hatte.
     *
     * ## Wozu
     *
     * Sieben Postfaecher haben zusammen 220 Ordner. Ein stuendlicher Lauf, der
     * in jedem davon die Kennungen auflistet, macht 220 Auflistungen die
     * Stunde — fuer eine Handvoll neuer Nachrichten. O365 drosselt pro
     * Postfach ueber alle Verbindungen; der Lauf wuerde den Mailbrowser
     * ausbremsen, den er eigentlich schneller machen soll.
     *
     * `STATUS` dagegen ist eine Zeile: Gueltigkeitsnummer, naechste Kennung,
     * Anzahl. Sind alle drei wie beim letzten Mal, hat sich in diesem Ordner
     * nichts getan — weder etwas dazugekommen noch etwas verschwunden. Dann
     * braucht ihn niemand zu oeffnen.
     *
     * ## Warum alle drei Werte
     *
     * `uidnext` allein wuerde Loeschungen uebersehen: Wer eine Nachricht
     * entfernt, aendert die naechste Kennung nicht. `messages` allein
     * uebersaehe „eine geloescht, eine gekommen". Und ohne `uidvalidity`
     * bliebe ein Ordner nach einem Serverumzug fuer unveraendert gehalten,
     * obwohl jede gespeicherte Kennung ungueltig geworden ist.
     */
    private static function folderStates(): void
    {
        $name = self::name('folder_states', 'mail_folder_states');

        if (Schema::hasTable($name)) {
            return;
        }

        Schema::create($name, function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('email_account_id')->index();
            $t->string('folder', 500);

            $t->unsignedBigInteger('uidvalidity')->nullable();
            $t->unsignedBigInteger('uidnext')->nullable();
            $t->unsignedInteger('messages')->nullable();

            /*
             * Der Stichtag: die juengste Kennung beim ersten Blick.
             *
             * Ab hier wird aufgenommen, davor nicht. Ohne diese Spalte waere
             * beim ersten Lauf jede vorhandene Nachricht „neu", und ein
             * gewachsenes Postfach kaeme vollstaendig herein — genau das, was
             * nicht gewollt ist.
             *
             * `null` heisst „Ordner war leer": Dann ist alles, was kommt, neu.
             */
            $t->string('since_handle', 191)->nullable();

            $t->timestamp('checked_at')->nullable();
            $t->timestamps();

            $t->unique(['email_account_id', 'folder'], 'mail_folder_states_unique');
        });
    }

    private static function name(string $schluessel, string $vorgabe): string
    {
        return (string) config("mailbox.tables.{$schluessel}", $vorgabe);
    }
}
