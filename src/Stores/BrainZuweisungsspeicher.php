<?php

namespace Peppermint\Mailbox\Stores;

use Illuminate\Support\Facades\Log;
use Peppermint\Mailbox\Contracts\Zuweisungsspeicher;
use Peppermint\Mailbox\Zustand\Person;
use Peppermint\Mailbox\Zustand\Zuweisung;
use Peppermint\Mailbox\Zustand\Zuweisungsliste;

/**
 * Zuweisungen aus der Mitte — eine Zustaendigkeit fuer alle Systeme.
 *
 * ## Gefragt wird nach der ADRESSE
 *
 * Dieselbe Mailbox hat in jedem System eine andere Nummer. Wer mit der
 * oertlichen Nummer fragt, fragt die Mitte nach IHRER Nummer drei — und die
 * gehoert einem anderen Postfach. Das faellt nicht als Fehler auf, sondern als
 * fremde Zustaendigkeit an der eigenen Mail.
 *
 * Ohne Adresse wird deshalb gar nicht gefragt. Das ist streng und richtig: Ein
 * Postfach, dessen Adresse dieses System nicht kennt, ist eines, das es nicht
 * eindeutig benennen kann.
 *
 * ## Warum hier NICHT zwischengespeichert wird
 *
 * Bei den Postfaechern und beim Verlauf gibt es einen Zwischenspeicher, und er
 * ist dort richtig: Verbindungsdaten aendern sich selten, ein Verlauf waechst
 * langsam.
 *
 * „Wer kuemmert sich gerade" ist das Gegenteil. Es aendert sich genau dann,
 * wenn jemand handelt, und der Zweck der Angabe ist, dass nicht zwei Leute
 * dasselbe anfangen. Eine Minute alt heisst hier: zwei Leute fangen dasselbe
 * an. Der Zwischenspeicher wuerde die Funktion abschaffen, die er beschleunigt.
 *
 * Teuer ist das nicht: Die Oberflaeche holt die Liste EINMAL je geoeffnetem
 * Postfach, nicht je Zeile (#7690).
 *
 * ## Personen stehen dort als ADRESSE
 *
 * Und das ist der Punkt, an dem dieser Umbau beim ersten Entwurf falsch war:
 * Die oertliche Benutzer-Nummer mitzuschreiben ist bequem und zerstoert die
 * Aussage. Jedes Produkt fuehrt seine eigene Nutzertabelle — die Nummer zeigt
 * im naechsten System auf jemand anderen, ohne Fehler und ohne Hinweis.
 *
 * ## Eine unerreichbare Mitte ist hier KEIN stiller Rueckfall
 *
 * Anders als beim Verlauf, wo ein Ausfall nur eine Lesehilfe kostet. Hier
 * waere eine leere Antwort die Aussage „niemand ist zustaendig" — und die ist
 * falsch, nicht fehlend. Deshalb traegt {@see Zuweisungsliste} mit, ob
 * jemand zu sprechen war, und das Schreiben antwortet mit `null` statt mit
 * einem erfundenen Erfolg.
 */
class BrainZuweisungsspeicher implements Zuweisungsspeicher
{
    /**
     * @param  callable(string, array<string, mixed>): ?array  $ruf  Liefert die
     *         dekodierte Antwort des Werkzeugs — die Huelle `{ok, data}` —, oder
     *         null, wenn die Mitte nicht erreichbar war.
     * @param  callable(int): ?string  $adresse  Die Mailadresse zur oertlichen Kennung.
     */
    public function __construct(
        private $ruf,
        private $adresse,
    ) {}

    public function schreibbar(): bool
    {
        // Anders als die Kontozeile: Eine Zustaendigkeit wird dort vergeben,
        // wo gearbeitet wird. Die Mitte ist hier Ablage, nicht Hoheit — sonst
        // muesste man zum Zuweisen das System wechseln, was genau der Grund
        // fuer diesen Umbau war.
        return true;
    }

    public function offene(int $konto): Zuweisungsliste
    {
        $postfach = $this->postfach($konto);

        if ($postfach === null) {
            return Zuweisungsliste::unerreichbar();
        }

        $daten = $this->frage('mail.assignments.list', ['mailbox' => $postfach]);

        if ($daten === null) {
            return Zuweisungsliste::unerreichbar();
        }

        $zeilen = [];

        foreach ((array) ($daten['assignments'] ?? []) as $roh) {
            if (! is_array($roh)) {
                continue;
            }

            $zuweisung = Zuweisung::aus($roh, $konto, $postfach);

            if ($zuweisung !== null) {
                $zeilen[] = $zuweisung;
            }
        }

        return Zuweisungsliste::mit($zeilen);
    }

    public function fuer(int $konto, string $nachricht, ?string $kette): ?Zuweisung
    {
        $postfach = $this->postfach($konto);

        if ($postfach === null) {
            return null;
        }

        $daten = $this->frage('mail.assignments.get', array_filter([
            'mailbox' => $postfach,
            'message_id' => $nachricht,
            'thread_id' => $kette,
        ], fn ($wert): bool => $wert !== null));

        if ($daten === null || ! is_array($daten['assignment'] ?? null)) {
            return null;
        }

        return Zuweisung::aus($daten['assignment'], $konto, $postfach);
    }

    public function zuweisen(int $konto, string $nachricht, ?string $kette, Person $an, ?Person $von): ?Zuweisung
    {
        $postfach = $this->postfach($konto);

        if ($postfach === null) {
            return null;
        }

        $daten = $this->frage('mail.assignments.set', array_filter([
            'mailbox' => $postfach,
            'message_id' => $nachricht,
            'thread_id' => $kette,
            // Adressen, keine Nummern. Nutzer 5 im CRM und Nutzer 5 im
            // Manager sind zwei verschiedene Menschen; eine Nummer hier
            // hinzuschreiben hiesse, die Zustaendigkeit beim naechsten
            // Systemwechsel an jemand anderen zu haengen.
            'assigned_to' => $an->adresse,
            'assigned_by' => $von?->adresse,
        ], fn ($wert): bool => $wert !== null));

        if ($daten === null) {
            return null;
        }

        // Die Mitte antwortet mit der geschriebenen Zeile. Hier den eigenen
        // Entwurf zurueckzugeben waere bequem und falsch: Dann stuende in der
        // Oberflaeche, was man SCHICKEN wollte, und nicht, was ANKAM.
        return is_array($daten['assignment'] ?? null)
            ? Zuweisung::aus($daten['assignment'], $konto, $postfach)
            : null;
    }

    public function aufheben(int $konto, string $nachricht, ?string $kette): bool
    {
        $postfach = $this->postfach($konto);

        if ($postfach === null) {
            return false;
        }

        return $this->frage('mail.assignments.clear', array_filter([
            'mailbox' => $postfach,
            'message_id' => $nachricht,
            'thread_id' => $kette,
        ], fn ($wert): bool => $wert !== null)) !== null;
    }

    /**
     * @param  array<string, mixed>  $argumente
     * @return array<string, mixed>|null  `null` heisst: niemand war zu sprechen.
     */
    private function frage(string $faehigkeit, array $argumente): ?array
    {
        try {
            $antwort = ($this->ruf)($faehigkeit, $argumente);
        } catch (\Throwable $e) {
            Log::warning('Zuweisungen konnten nicht mit AI Brain abgeglichen werden: '.$e->getMessage());

            return null;
        }

        if (! is_array($antwort)) {
            return null;
        }

        if (($antwort['ok'] ?? null) === false) {
            // Die Mitte hat geantwortet und abgelehnt. Das ist etwas anderes
            // als „nicht erreichbar", fuehrt hier aber zum selben Ergebnis:
            // kein erfundener Erfolg. Der Grund steht im Log.
            Log::warning('AI Brain hat den Zuweisungs-Vorgang abgelehnt.', [
                'capability' => $faehigkeit,
                'error' => $antwort['error'] ?? $antwort['message'] ?? null,
            ]);

            return null;
        }

        // Die Nutzlast steckt in `data` — dieselbe Huelle wie bei den
        // Postfaechern. Sie zu uebersehen faellt nicht auf: Dann ist die Liste
        // eben immer leer, und das sieht aus wie „niemand zustaendig".
        $daten = $antwort['data'] ?? null;

        return is_array($daten) ? $daten : $antwort;
    }

    private function postfach(int $konto): ?string
    {
        $adresse = ($this->adresse)($konto);

        if (! is_string($adresse) || trim($adresse) === '') {
            return null;
        }

        return mb_strtolower(trim($adresse));
    }
}
