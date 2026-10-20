<?php

namespace Peppermint\Mailbox\Http;

use DateTimeImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Peppermint\Mailbox\Contracts\Schlummerspeicher;
use Peppermint\Mailbox\Zustand\Person;
use Peppermint\Mailbox\Zustand\Schlummer;

/**
 * „Nicht jetzt — erinnere mich wieder ab dann."
 *
 * ## Pro Person
 *
 * Das ist der Unterschied zur Zuweisung, und er ist wichtig: Wer in einem
 * Gruppenpostfach etwas fuer ALLE verschwinden liesse, nimmt der Kollegin
 * eine Nachricht weg, von der sie nichts weiss. Geschlummert wird fuer sich
 * selbst.
 *
 * ## Kein Hintergrundlauf
 *
 * Nichts holt eine Nachricht zurueck. Eine geschlummerte Kette wird beim
 * Anzeigen uebersprungen, solange die Zeit in der Zukunft liegt — danach
 * steht sie wieder da, ohne dass jemand etwas tun muss.
 *
 * Ein Lauf, der zur faelligen Zeit Zeilen umschreibt, koennte ausfallen, und
 * dann bliebe die Nachricht fuer immer verschwunden. Ein Vergleich mit der Uhr
 * kann das nicht.
 *
 * ## Ohne Kettenkennung wird nicht geschlummert
 *
 * Eine einzelne Nachricht zu verstecken hiesse: Die naechste Antwort in
 * derselben Sache taucht sofort wieder auf, und der Mensch davor haelt das
 * Schlummern fuer kaputt. Lieber eine ehrliche Absage.
 *
 * ## Seit 19.10.2026: dieselbe Kette wie bei allem anderen
 *
 * Dieser Weg rechnete die Kette bis dahin SELBST aus (`ThreadKey::fromHeaders`)
 * und ging damit am Kopfzeilen-Verzeichnis des Projekt-Managers vorbei, das
 * {@see HandlesAssignments} benutzt. Dieselbe Unterhaltung hatte dort also je
 * Funktion einen anderen Faden: Die Zustaendigkeit haftete an der Wurzel, der
 * Schlummer an einer Teilkette — und eine weggelegte Mail kam mit der
 * naechsten Antwort wieder, waehrend die Zustaendigkeit blieb.
 *
 * Jetzt fragen beide {@see BestimmtDieKette::threadKeyFor()}. Zwei Stellen,
 * die dieselbe Entscheidung treffen, muessen dieselbe Funktion rufen.
 */
trait HandlesSnoozes
{
    use BestimmtDieKette;

    abstract protected function currentUserId(): ?int;

    /**
     * Der Riegel vor jedem Weg — standardmaessig offen.
     *
     * Dieselbe Begruendung wie bei {@see HandlesAssignments}: Diese Wege
     * brauchen kein Postfach, nur den Speicher, und gingen damit an einer
     * Schranke vorbei, die in `mailboxFor()` sitzt.
     */
    protected function guardSnoozes(int $account): void {}

    /**
     * Wer wegliegt — mit Adresse, nicht nur mit Nummer.
     *
     * Ein Schlummer gehoert genau einem Menschen, und ueber Systemgrenzen ist
     * die Nummer nicht derselbe Mensch. Eine verwechselte Nummer wuerde die
     * Nachricht beim FALSCHEN verstecken — und dem faellt es nie auf, ihm
     * fehlt eine Mail, von der er nichts weiss.
     *
     * Standardmaessig aus der Anmeldung. Ein Produkt mit einem zweiten
     * Anmeldeweg ueberschreibt das — der Acting-User der Bridge kennt nur
     * einen Guard, und dieselbe Falle hat dort schon zugeschlagen.
     */
    protected function schlummerPerson(): ?Person
    {
        $person = auth()->user();
        $adresse = $person?->getAttribute('email');
        $adresse = is_string($adresse) ? mb_strtolower(trim($adresse)) : '';
        $nummer = $this->currentUserId();

        if ($nummer === null && $adresse === '') {
            return null;
        }

        /*
         * Eine Person OHNE Adresse ist hier zulaessig — und das ist Absicht.
         *
         * Der lokale Speicher braucht nur die Nummer; verlangte dieser Weg
         * eine Adresse, koennte eine eigenstaendige Installation ohne
         * gepflegte Mailadressen gar nicht mehr schlummern. Ob die Angabe
         * reicht, weiss der Speicher — die Mitte lehnt ohne Adresse ab, und
         * das wird dann als Fehlschlag gemeldet statt hier vorweggenommen.
         */
        return new Person(
            adresse: $adresse,
            nummer: $nummer,
            name: (string) ($person?->getAttribute('name') ?? ''),
        );
    }

    /**
     * Die Ketten, die fuer diese Person gerade schlummern.
     */
    public function snoozes(int $account): JsonResponse
    {
        $this->guardSnoozes($account);

        $person = $this->schlummerPerson();

        if ($person === null) {
            return response()->json(['snoozes' => [], 'reachable' => false]);
        }

        $liste = app(Schlummerspeicher::class)->ruhende($account, $person);

        return response()->json([
            'snoozes' => array_map(fn (Schlummer $s): array => [
                'thread_id' => $s->kette,
                'message_id' => $s->nachricht,
                'until' => $s->bis->format(DateTimeImmutable::ATOM),
            ], $liste->schlummer),
            // Wer eine Mail wegliegt und sie sofort wieder vorfindet, haelt
            // das Schlummern fuer kaputt. Mit dieser Angabe kann die
            // Oberflaeche sagen, dass sie gerade nicht nachsehen konnte.
            'reachable' => $liste->erreichbar,
        ]);
    }

    public function snooze(Request $request, int $account): JsonResponse
    {
        $this->guardSnoozes($account);

        $daten = $request->validate([
            'message_id' => ['required', 'string', 'max:255'],
            'until' => ['required', 'date', 'after:now'],
            'in_reply_to' => ['nullable', 'string', 'max:998'],
            'references' => ['nullable', 'string'],
        ]);

        $person = $this->schlummerPerson();

        if ($person === null) {
            return response()->json(['message' => 'Nicht angemeldet.'], 401);
        }

        $kette = $this->threadKeyFor($account, $daten);

        if ($kette === null) {
            return response()->json([
                'message' => 'Zu dieser Nachricht lässt sich keine Konversation bestimmen — sie kann nicht schlummern.',
            ], 422);
        }

        $gelungen = app(Schlummerspeicher::class)->weglegen(
            $account,
            $person,
            $kette,
            (string) $daten['message_id'],
            new DateTimeImmutable((string) $daten['until']),
        );

        if (! $gelungen) {
            // Nicht geschrieben, also nicht gemeldet: Die Mail bleibt stehen,
            // und das soll man erfahren, statt sie beim naechsten Laden
            // verwundert wiederzufinden.
            return response()->json([
                'message' => 'Das Weglegen konnte nicht gespeichert werden. Bitte später erneut versuchen.',
            ], 503);
        }

        return response()->json(['success' => true]);
    }

    public function unsnooze(Request $request, int $account): JsonResponse
    {
        $this->guardSnoozes($account);

        $daten = $request->validate([
            'message_id' => ['required', 'string', 'max:255'],
            'in_reply_to' => ['nullable', 'string', 'max:998'],
            'references' => ['nullable', 'string'],
        ]);

        $person = $this->schlummerPerson();

        if ($person === null) {
            return response()->json(['message' => 'Nicht angemeldet.'], 401);
        }

        $gelungen = app(Schlummerspeicher::class)->hervorholen(
            $account,
            $person,
            $this->threadKeyFor($account, $daten),
            (string) $daten['message_id'],
        );

        if (! $gelungen) {
            /*
             * Der Fall, der wirklich weh tut: Wer etwas hervorholt und es
             * bleibt weg, sucht eine Nachricht, die er sich selbst versteckt
             * hat — und sucht sie im Postfach, nicht im Schlummern.
             */
            return response()->json([
                'message' => 'Die Nachricht konnte nicht zurückgeholt werden. Bitte später erneut versuchen.',
            ], 503);
        }

        return response()->json(['success' => true]);
    }
}
