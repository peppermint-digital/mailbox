<?php

namespace Peppermint\Mailbox\Zustand;

use DateTimeImmutable;
use DateTimeInterface;

/**
 * „Nicht jetzt — erinnere mich wieder ab dann."
 *
 * ## Pro Person, und das ist der Unterschied zur Zuweisung
 *
 * Wer in einem Gruppenpostfach etwas fuer ALLE verschwinden liesse, nimmt der
 * Kollegin eine Nachricht weg, von der sie nichts weiss. Geschlummert wird
 * fuer sich selbst — deshalb traegt dieses Objekt eine {@see Person}, und
 * deshalb ist sie Teil des Schluessels und nicht bloss eine Notiz.
 *
 * ## Warum auch das in die Mitte gehoert
 *
 * Dieselbe Probe wie bei der Zustaendigkeit: Beschreibt es die Sache oder den
 * Umgang des Produkts damit? „Ich habe diese Unterhaltung weggelegt" ist eine
 * Aussage ueber mich und die Unterhaltung — in jedem System dieselbe. Wer im
 * CRM etwas wegliegt und es im Manager wieder vorfindet, hat es nicht
 * weggelegt, sondern nur ein Fenster geschlossen.
 *
 * ## Es gibt keinen Hintergrundlauf
 *
 * Nichts holt eine Nachricht zurueck. Eine geschlummerte Kette wird beim
 * Anzeigen uebersprungen, solange `bis` in der Zukunft liegt — danach steht
 * sie wieder da, ohne dass jemand etwas tun muss.
 *
 * Ein Lauf, der zur faelligen Zeit Zeilen umschreibt, koennte ausfallen, und
 * dann bliebe die Nachricht fuer immer verschwunden. Ein Vergleich mit der Uhr
 * kann das nicht.
 */
final class Schlummer
{
    public function __construct(
        public readonly string $postfach,
        public readonly Person $person,
        public readonly string $kette,
        public readonly string $nachricht,
        public readonly DateTimeInterface $bis,
    ) {}

    public function faellig(?DateTimeInterface $jetzt = null): bool
    {
        return $this->bis <= ($jetzt ?? new DateTimeImmutable);
    }

    /**
     * Wie ein Schlummer in die Mitte geschrieben und von dort gelesen wird.
     *
     * Adressen, keine Nummern — bei Postfach UND Person. Siehe {@see Person}:
     * Nutzer 5 ist in jedem System jemand anders.
     *
     * Die Zeit geht als ISO-8601 MIT Zeitzone hinaus. Ohne sie waere „bis 9
     * Uhr" die Ortszeit des schreibenden Systems, und die Nachricht kaeme
     * anderswo zwei Stunden zu frueh oder zu spaet zurueck.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'mailbox' => $this->postfach,
            'person' => $this->person->adresse,
            'thread_id' => $this->kette,
            'message_id' => $this->nachricht,
            'until' => $this->bis->format(DateTimeInterface::ATOM),
        ];
    }

    /**
     * @param  array<string, mixed>  $roh
     */
    public static function aus(array $roh, string $postfach, Person $person): ?self
    {
        $kette = (string) ($roh['thread_id'] ?? '');
        $bis = (string) ($roh['until'] ?? '');

        if ($kette === '' || $bis === '') {
            // Ohne Kette haengt der Schlummer an nichts, ohne Zeit hoert er
            // nie auf. Beides durchzulassen heisst, eine Nachricht fuer immer
            // zu verstecken.
            return null;
        }

        try {
            $zeit = new DateTimeImmutable($bis);
        } catch (\Throwable) {
            // Eine unlesbare Zeit als „jetzt" zu deuten brachte die Nachricht
            // sofort zurueck, als „nie" versteckte sie sie fuer immer. Beides
            // waere geraten — also gilt die Zeile als nicht vorhanden.
            return null;
        }

        return new self(
            postfach: $postfach,
            person: $person,
            kette: $kette,
            nachricht: (string) ($roh['message_id'] ?? ''),
            bis: $zeit,
        );
    }
}
