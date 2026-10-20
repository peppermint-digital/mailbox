<?php

namespace Peppermint\Mailbox\Zustand;

/**
 * Wer sich um diese Unterhaltung kuemmert — als Aussage, nicht als Tabellenzeile.
 *
 * ## Warum ein Wertobjekt und nicht weiter das Model
 *
 * Bis zum 19.10.2026 reichte das Paket {@see \Peppermint\Mailbox\Models\MailAssignment}
 * durch seine Haken. Das ging, solange die Zuweisung eine lokale Zeile war.
 * Liegt sie in der Mitte, gibt es hier keine Zeile und keinen Schluessel —
 * und ein Haken, der einen braucht, faellt aus.
 *
 * Gefunden am echten Fall: Der Projekt-Manager machte in `afterAssign()` ein
 * `EmailAssignment::find($zuweisung->getKey())`, um mit SEINEM Model zu
 * benachrichtigen. Ohne Zeile ist der Schluessel `null`, der Fund `null`, und
 * die Methode steigt still aus. Ergebnis: Jemand bekommt Arbeit zugewiesen und
 * erfaehrt nie davon — wovor der Docblock desselben Hakens ausdruecklich warnt.
 *
 * Deshalb traegt dieses Objekt alles, was ein Produkt zum Benachrichtigen
 * braucht, und keine Datenbank-Kennung.
 *
 * ## Zwei Kennungen fuer das Postfach, zwei fuer den Menschen
 *
 * `konto` ist die LOKALE Nummer des Postfachs, `postfach` seine Adresse.
 * Dasselbe bei der Person: {@see Person} traegt Adresse und, wenn hier
 * bekannt, die oertliche Nummer.
 *
 * Nebeneinander ist das keine Doppelung. Die eine Angabe ist hier gueltig, die
 * andere ueberall — und was in die Mitte geschrieben wird, wird ueber die
 * zweite geschrieben. Eine Nummer quer ueber Systemgrenzen funktioniert so
 * lange zufaellig, wie die Tabellen uebereinstimmen, und zeigt danach auf
 * jemand anderen.
 */
final class Zuweisung
{
    public const OFFEN = 'open';

    public const ERLEDIGT = 'done';

    public function __construct(
        public readonly int $konto,
        public readonly string $postfach,
        public readonly string $nachricht,
        public readonly ?string $kette,
        public readonly Person $an,
        public readonly ?Person $von = null,
        public readonly string $status = self::OFFEN,
    ) {}

    /**
     * Woran die Zuweisung haengt — Kette, sonst Nachricht.
     *
     * Dieselbe Wahl wie in {@see \Peppermint\Mailbox\Werkzeuge\Vorgang::anker()},
     * und absichtlich dieselbe Formulierung: Eine Zuweisung und ein Verb am
     * selben Vorgang muessen am selben Faden haengen. Liefen die beiden
     * auseinander, haenge die Zuweisung an der einen Kette und das Ticket an
     * der anderen — ohne dass etwas bricht.
     */
    public function anker(): string
    {
        return $this->kette ?? $this->nachricht;
    }

    /**
     * Dieselbe Zuweisung mit oertlich aufgeloesten Personen.
     *
     * Die Mitte liefert Adressen; wer das ist, weiss nur das Produkt. Diese
     * Zuordnung macht {@see \Peppermint\Mailbox\Http\HandlesAssignments}, weil
     * dort die Liste der zulaessigen Personen liegt — die einzige Stelle, die
     * beides kennt.
     */
    public function mitPersonen(Person $an, ?Person $von): self
    {
        return new self($this->konto, $this->postfach, $this->nachricht, $this->kette, $an, $von, $this->status);
    }

    /**
     * Wie eine Zuweisung in die Mitte geschrieben und von dort gelesen wird.
     *
     * **Ohne jede Nummer.** Weder die des Postfachs noch die der Person: Beide
     * haben dort keine Bedeutung, und beide waeren die Falle, vor der die
     * Vertragsdoku warnt.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'mailbox' => $this->postfach,
            'message_id' => $this->nachricht,
            'thread_id' => $this->kette,
            'assigned_to' => $this->an->adresse,
            'assigned_by' => $this->von?->adresse,
            'status' => $this->status,
        ];
    }

    /**
     * Aus einer Antwort der Mitte — die oertliche Nummer kommt von hier.
     *
     * @param  array<string, mixed>  $roh
     */
    public static function aus(array $roh, int $konto, string $postfach): ?self
    {
        $nachricht = (string) ($roh['message_id'] ?? '');
        $an = Person::aus((string) ($roh['assigned_to'] ?? ''));

        if ($nachricht === '' || $an === null) {
            // Eine Zuweisung ohne Nachricht haengt an nichts, eine ohne Person
            // weist niemandem etwas zu. Beides durchzulassen heisst, ein
            // Abzeichen ohne Inhalt in die Liste zu stellen.
            return null;
        }

        $kette = $roh['thread_id'] ?? null;

        return new self(
            konto: $konto,
            postfach: $postfach,
            nachricht: $nachricht,
            kette: is_string($kette) && $kette !== '' ? $kette : null,
            an: $an,
            von: Person::aus((string) ($roh['assigned_by'] ?? '')),
            status: (string) ($roh['status'] ?? self::OFFEN),
        );
    }
}
