<?php

namespace Peppermint\Mailbox\Index;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Was in einer Kennung steht — ohne den Server zu fragen (#6119).
 *
 * Der ganze Grund steht in der Migration `create_mailbox_headers_table`:
 * Exchange arbeitet rund 12,7 ms je Nachricht, unabhängig davon, wie wenig man
 * abfragt. Eine Feldauswahl bringt gemessen nichts; nicht zu fragen bringt
 * alles.
 *
 * ## Die Invariante
 *
 * > Dieses Verzeichnis beantwortet „was steht in UID 46807", **nie** „welche
 * > Mails liegen in diesem Ordner".
 *
 * Wer es anders benutzt, baut genau den stillen Fehler, den es vermeiden soll.
 * Die Belegung kommt vom Server, immer.
 *
 * ## Zwei Schreibwege, und das ist Absicht
 *
 * Ein Kopf-Abruf weiß nichts über Anhänge, ein Struktur-Abruf nichts über
 * nichts anderes. Mit EINEM Schreibweg würde der häufigere (Kopf) die teuer
 * erkaufte Anhangzahl des seltenen (Struktur) auf NULL zurücksetzen — und beim
 * nächsten Aufruf wäre sie wieder zu holen. Der Zwischenspeicher würde sich
 * selbst leeren, und zwar unauffällig: Es wäre nur langsam, nicht falsch.
 *
 * ## Wenn die Tabelle fehlt
 *
 * Dann tut dieses Verzeichnis nichts und sagt es einmal ins Log. Ein Paket-
 * Release liegt immer VOR der Migration der vier Produkte; in dem Fenster
 * dazwischen muss die Mailliste laufen, nicht abstürzen. Sie läuft dann auf dem
 * lebenden Weg — also so wie vorher.
 */
final class Kopfzeilenverzeichnis
{
    public const TABELLE = 'mailbox_headers';

    /**
     * Einmal je AUFRUF: Scheitert die erste Abfrage, wird nicht weiter gefragt.
     *
     * Kein `Schema::hasTable` — das wäre eine Abfrage auf dem heißen Weg.
     *
     * ## Warum der Riegel NICHT statisch ist
     *
     * Er war es, und ein Test hat den Entwurfsfehler gefunden: Ein statischer
     * Riegel lebt so lange wie der Prozess. Unter Octane heißt das, dass ein
     * einsekündiger Schluckauf der Datenbank das Verzeichnis für die ganze
     * Lebensdauer eines Arbeiters abschaltet — die Listen wären danach
     * dauerhaft langsam, und niemand käme auf die Ursache.
     *
     * So lebt er genau so lange wie dieses Objekt, also einen Aufruf. Was er
     * verhindern soll, verhindert er dabei vollständig: Beim ersten Fehlschlag
     * fällt er, und die drei bis vier weiteren Zugriffe derselben Seite finden
     * ihn geschlossen. Eine fehlende Tabelle kostet also EINE vergebliche
     * Abfrage und EINE Zeile im Log je Seitenaufruf, nicht vier.
     */
    private bool $abgeschaltet = false;

    /**
     * Ist das Verzeichnis eingeschaltet?
     *
     * ## Warum der Schalter hier liegt und nicht im Aufrufer
     *
     * Abgeschaltet gibt dieses Verzeichnis nichts heraus und merkt sich nichts
     * — die Liste verhaelt sich dann wie bei einem Ordner, den sie zum ersten
     * Mal sieht, und das ist genau der Weg von vorher. So gibt es EINEN
     * Codeweg statt zweier.
     *
     * Zwei Wege waeren der teurere Entwurf: Der ausgeschaltete wird nie
     * benutzt und faellt deshalb unbemerkt auseinander — bis ihn jemand
     * braucht, und zwar immer dann, wenn gerade etwas anderes brennt.
     */
    private function an(): bool
    {
        return ! $this->abgeschaltet && (bool) config('mailbox.header_index', true);
    }

    /**
     * Die bekannten Zeilen zu diesen Kennungen.
     *
     * @param  list<int|string>  $kennungen
     * @return array<string, array<string, mixed>> Kennung (als Zeichenkette) => Zeile
     */
    public function bekannt(int $konto, string $ordner, ?int $gueltigkeit, array $kennungen): array
    {
        if ($kennungen === [] || ! $this->an()) {
            return [];
        }

        try {
            $zeilen = DB::table(self::TABELLE)
                ->where('account_id', $konto)
                ->where('folder', $ordner)
                ->where(function ($abfrage) use ($gueltigkeit): void {
                    /*
                     * Eine andere Gültigkeit heißt: aus dem Ordner ist über
                     * Nacht ein anderer geworden, die Kennungen zeigen ins
                     * Leere. Solche Zeilen werden nicht gelesen — und vom
                     * Aufrufer weggeräumt, sobald er den Wechsel bemerkt.
                     */
                    $abfrage->where('uidvalidity', $gueltigkeit)
                        ->orWhereNull('uidvalidity');
                })
                ->whereIn('uid', array_map('strval', $kennungen))
                ->get();
        } catch (\Throwable $fehler) {
            $this->abschalten('Lesen', $fehler);

            return [];
        }

        $nach = [];

        foreach ($zeilen as $zeile) {
            $satz = (array) $zeile;

            /*
             * `references` ist in MySQL ein reserviertes Wort, die Spalte heisst
             * deshalb `mail_references` — wie im Verzeichnis des Managers. Der
             * Aufrufer soll davon nichts wissen: Er spricht die Drahtform.
             */
            $satz['references'] = $satz['mail_references'] ?? null;
            unset($satz['mail_references']);

            $nach[(string) $zeile->uid] = $satz;
        }

        return $nach;
    }

    /**
     * Kopfzeilen merken — ohne die Anhangzahl anzufassen.
     *
     * @param  list<array<string, mixed>>  $zeilen  je mit `uid`
     */
    public function merkenKopf(int $konto, string $ordner, ?int $gueltigkeit, array $zeilen): void
    {
        $this->schreiben($konto, $ordner, $gueltigkeit, array_map(
            static function (array $zeile): array {
                if (array_key_exists('references', $zeile)) {
                    $zeile['mail_references'] = $zeile['references'];
                    unset($zeile['references']);
                }

                return $zeile;
            },
            $zeilen,
        ), [
            'message_id', 'subject', 'from_address', 'from_name', 'date',
            'in_reply_to', 'mail_references', 'updated_at',
        ]);
    }

    /**
     * Die Anhangzahl merken — ohne die Kopfzeilen anzufassen.
     *
     * @param  array<int|string, int>  $zahlen  Kennung => Anzahl
     */
    public function merkenAnhangzahl(int $konto, string $ordner, ?int $gueltigkeit, array $zahlen): void
    {
        $zeilen = [];

        foreach ($zahlen as $kennung => $anzahl) {
            $zeilen[] = ['uid' => $kennung, 'attachment_count' => $anzahl];
        }

        $this->schreiben($konto, $ordner, $gueltigkeit, $zeilen, ['attachment_count', 'updated_at']);
    }

    /**
     * Die verzeichneten Kennungen eines Ordners — nur die Kennungen.
     *
     * Für den Abgleich mit der Belegung des Servers. Eine Spalte, keine
     * Zeilen: Ein Ordner kann tausende verzeichnete Nachrichten haben, und
     * gebraucht wird hier nur die Nummer.
     *
     * @return list<string>
     */
    public function kennungen(int $konto, string $ordner, ?int $gueltigkeit): array
    {
        if (! $this->an() || $gueltigkeit === null) {
            return [];
        }

        try {
            return DB::table(self::TABELLE)
                ->where('account_id', $konto)
                ->where('folder', $ordner)
                ->where('uidvalidity', $gueltigkeit)
                ->pluck('uid')
                ->map(fn ($uid): string => (string) $uid)
                ->all();
        } catch (\Throwable $fehler) {
            $this->abschalten('Kennungen lesen', $fehler);

            return [];
        }
    }

    /**
     * Karteileichen wegräumen — genannte Kennungen, nicht eine Regel.
     *
     * ## Warum der Aufrufer die Toten nennt und nicht dieses Verzeichnis
     *
     * Weil nur er weiß, für welchen BEREICH seine Liste maßgeblich ist. Das
     * Fenster der Listenseite umfasst die hundert jüngsten Kennungen; was
     * darunter liegt, ist nicht tot, sondern nur außerhalb. Eine Regel „lösche,
     * was nicht in dieser Liste steht" würde bei jedem Blick in einen großen
     * Ordner das Verzeichnis aller älteren Nachrichten leeren.
     *
     * Gemessen am 21.10.2026 an `department@`: 65 verzeichnete Zeilen für 30
     * vorhandene Nachrichten. Gelesen werden die 35 Toten nie — die Belegung
     * kommt vom Server —, aber sie wachsen für immer.
     *
     * @param  list<int|string>  $tote
     */
    public function vergessenKennungen(int $konto, string $ordner, array $tote): int
    {
        if ($tote === [] || ! $this->an()) {
            return 0;
        }

        $weg = 0;

        try {
            // In Bloecken: Die Liste der Toten ist im Regelfall kurz, kann nach
            // einer Aufraeumaktion im Postfach aber hunderte Nummern umfassen.
            foreach (array_chunk(array_map('strval', $tote), 500) as $block) {
                $weg += DB::table(self::TABELLE)
                    ->where('account_id', $konto)
                    ->where('folder', $ordner)
                    ->whereIn('uid', $block)
                    ->delete();
            }
        } catch (\Throwable $fehler) {
            $this->abschalten('Karteileichen entfernen', $fehler);
        }

        return $weg;
    }

    /**
     * Zeilen einer ANDEREN Gültigkeit wegräumen.
     *
     * Wechselt die `UIDVALIDITY`, zeigen alle verzeichneten Kennungen dieses
     * Ordners ins Leere. `bekannt()` gibt sie schon nicht mehr heraus — ohne
     * dieses Aufräumen blieben sie aber für immer liegen. Aufgerufen wird es
     * nur, wenn das Fenster GAR keine bekannte Zeile hatte; im Normalfall
     * passiert hier nichts.
     */
    public function aufraeumen(int $konto, string $ordner, ?int $gueltigkeit): int
    {
        if (! $this->an() || $gueltigkeit === null) {
            return 0;
        }

        try {
            return DB::table(self::TABELLE)
                ->where('account_id', $konto)
                ->where('folder', $ordner)
                ->whereNotNull('uidvalidity')
                ->where('uidvalidity', '!=', $gueltigkeit)
                ->delete();
        } catch (\Throwable $fehler) {
            $this->abschalten('Aufraeumen', $fehler);

            return 0;
        }
    }

    /**
     * Alles zu einem Ordner vergessen — nach einem Wechsel der Gültigkeit.
     */
    public function vergessen(int $konto, string $ordner): int
    {
        if (! $this->an()) {
            return 0;
        }

        try {
            return DB::table(self::TABELLE)
                ->where('account_id', $konto)
                ->where('folder', $ordner)
                ->delete();
        } catch (\Throwable $fehler) {
            $this->abschalten('Vergessen', $fehler);

            return 0;
        }
    }

    /**
     * @param  list<array<string, mixed>>  $zeilen
     * @param  list<string>  $aktualisieren
     */
    private function schreiben(int $konto, string $ordner, ?int $gueltigkeit, array $zeilen, array $aktualisieren): void
    {
        if ($zeilen === [] || ! $this->an()) {
            return;
        }

        $jetzt = now();

        $satz = array_map(static fn (array $zeile): array => array_merge([
            'account_id' => $konto,
            'folder' => $ordner,
            'uidvalidity' => $gueltigkeit,
            'message_id' => null,
            'subject' => null,
            'from_address' => null,
            'from_name' => null,
            'date' => null,
            'attachment_count' => null,
            'in_reply_to' => null,
            'mail_references' => null,
            'created_at' => $jetzt,
            'updated_at' => $jetzt,
        ], $zeile, ['uid' => (string) $zeile['uid']]), $zeilen);

        try {
            /*
             * In Blöcken, weil ein Fenster hundert Zeilen hat und jede Zeile
             * dreizehn Platzhalter mitbringt. Bei tausend Zeilen wäre das
             * dreizehntausend Parameter in einer Anweisung — mehr als manche
             * Treiber annehmen.
             */
            foreach (array_chunk($satz, 100) as $block) {
                DB::table(self::TABELLE)->upsert($block, ['account_id', 'folder', 'uid'], $aktualisieren);
            }
        } catch (\Throwable $fehler) {
            $this->abschalten('Schreiben', $fehler);
        }
    }

    private function abschalten(string $was, \Throwable $fehler): void
    {
        $this->abgeschaltet = true;

        Log::warning(
            "Mailbox: Kopfzeilen-Verzeichnis abgeschaltet ({$was}) — die Liste laeuft auf dem lebenden Weg weiter. "
            .$fehler->getMessage()
        );
    }
}
