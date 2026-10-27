<?php

namespace Peppermint\Mailbox\Werkzeuge;

use Peppermint\Mailbox\Http\HandlesAssignments;

/**
 * Die Mail, an der ein Werkzeug ansetzt — so benannt, dass JEDES System sie
 * wiedererkennt.
 *
 * ## Warum keine Konto-ID darin steht
 *
 * Das ist die erste Falle in `docs/INTEGRATION.md`, und sie hat schon Zeit
 * gekostet: Eine Kennung quer ueber Systemgrenzen funktioniert so lange
 * zufaellig, wie die Nummern uebereinstimmen, und liefert danach die falsche
 * Unterhaltung — ohne Fehlermeldung. Deshalb reist hier die **Adresse** des
 * Postfachs mit, nicht seine Nummer.
 *
 * Dieselbe Regel steht im verbindlichen Wiki: „Ueber Systemgrenzen hinweg
 * zaehlt, was eine Sache IST, nicht die Nummer, die sie HIER hat."
 *
 * ## Drei Angaben, und jede hat eine Aufgabe
 *
 * - `postfach` — welches Postfach. Die Adresse, klein geschrieben.
 * - `kette` — welche Unterhaltung. Ein Werkzeug setzt an der KETTE an, nicht
 *   an der einzelnen Nachricht: Sonst haengt das Ticket an der Anfrage und die
 *   Antwort darauf steht wieder ohne Ticket da.
 * - `nachricht` — welche Nachricht genau. Gebraucht, wenn die Kette nicht
 *   bestimmbar ist (eine Mail ohne `References` ist ihre eigene Kette), und
 *   fuer alles, was an genau diesem Dokument haengt — ein Anhang zum Beispiel.
 *
 * `betreff` und `absender` sind fuer die Gegenstelle. Ein Werkzeug drueben
 * legt damit etwas an, das einen Namen hat („Anfrage Bauvorhaben Meier")
 * statt einer Kennung. Sie sind beschreibend und duerfen fehlen — nie darf
 * eine Zuordnung darauf aufbauen.
 */
final class Vorgang
{
    /**
     * @param  array<string, mixed>  $zusatz  Was das fragende System noch mitgibt. Reist unveraendert mit.
     */
    public function __construct(
        public readonly string $postfach,
        public readonly ?string $kette,
        public readonly string $nachricht,
        public readonly ?string $betreff = null,
        public readonly ?string $absender = null,
        public readonly array $zusatz = [],
    ) {}

    /**
     * Aus dem, was die Oberflaeche mitschickt.
     *
     * Die Kette wird NICHT hier aus den Kopfzeilen errechnet. Das tut
     * {@see HandlesAssignments::threadKeyFor()} — mit
     * dem Wissen des Produkts, das mehr sein kann als das des Pakets (der
     * Projekt-Manager fuehrt ein Kopfzeilen-Verzeichnis und findet die Wurzel
     * auch ohne `In-Reply-To`). Zwei Stellen, die dieselbe Entscheidung
     * treffen, sind zwei Regeln, die heute uebereinstimmen.
     *
     * @param  array<string, mixed>  $daten
     */
    public static function aus(array $daten): self
    {
        return new self(
            postfach: mb_strtolower(trim((string) ($daten['mailbox'] ?? ''))),
            kette: self::wortOderNull($daten['thread_id'] ?? null),
            nachricht: (string) ($daten['message_id'] ?? ''),
            betreff: self::wortOderNull($daten['subject'] ?? null),
            absender: self::wortOderNull($daten['from'] ?? null),
            zusatz: is_array($daten['extra'] ?? null) ? $daten['extra'] : [],
        );
    }

    /**
     * Woran eine Zuordnung haengt — Kette, sonst Nachricht.
     *
     * Die eine Stelle, an der diese Wahl getroffen wird. Sie stand vorher an
     * drei Stellen im Paket („Kette, sonst Nachricht"), und genau solche
     * Wiederholungen sind laut Memory #4416 der Weg zu einem Zustand, den
     * keine Seite kennt.
     */
    public function anker(): string
    {
        return $this->kette ?? $this->nachricht;
    }

    public function vollstaendig(): bool
    {
        return $this->postfach !== '' && $this->nachricht !== '';
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'mailbox' => $this->postfach,
            'thread_id' => $this->kette,
            'message_id' => $this->nachricht,
            'subject' => $this->betreff,
            'from' => $this->absender,
            'extra' => $this->zusatz,
        ];
    }

    private static function wortOderNull(mixed $wert): ?string
    {
        if (! is_string($wert)) {
            return null;
        }

        $wert = trim($wert);

        return $wert === '' ? null : $wert;
    }
}
