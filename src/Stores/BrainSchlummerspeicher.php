<?php

namespace Peppermint\Mailbox\Stores;

use DateTimeInterface;
use Illuminate\Support\Facades\Log;
use Peppermint\Mailbox\Contracts\Schlummerspeicher;
use Peppermint\Mailbox\Zustand\Person;
use Peppermint\Mailbox\Zustand\Schlummer;
use Peppermint\Mailbox\Zustand\Schlummerliste;

/**
 * Geschlummertes aus der Mitte — weggelegt ist weggelegt, in jedem System.
 *
 * ## Zwei Adressen, keine Nummer
 *
 * Postfach UND Person reisen als Adresse. Bei der Person ist das sogar noch
 * wichtiger als bei der Zustaendigkeit: Ein Schlummer gehoert genau einem
 * Menschen, und eine verwechselte Nummer wuerde die Nachricht beim FALSCHEN
 * verstecken. Das faellt dem Betroffenen nie auf — ihm fehlt eine Mail, von
 * der er nichts weiss.
 *
 * ## Die Zeit mit Zeitzone
 *
 * „Bis morgen 9 Uhr" ohne Zone ist die Ortszeit des schreibenden Systems. Die
 * Nachricht kaeme anderswo zwei Stunden zu frueh oder zu spaet zurueck — und
 * weil niemand die Uhr vergleicht, sucht man den Fehler im Schlummern.
 *
 * ## Ein Ausfall laesst auftauchen, nicht verschwinden
 *
 * Die Richtung ist Absicht. Wer bei einem Ausfall etwas ausblendet, versteckt
 * eine Mail, die jemand sehen muss; wer etwas auftauchen laesst, aergert nur.
 * Die Liste sagt trotzdem, dass sie keine Antwort ist — sonst haelt der
 * Mensch davor das Schlummern fuer kaputt.
 */
class BrainSchlummerspeicher implements Schlummerspeicher
{
    /**
     * @param  callable(string, array<string, mixed>): ?array  $ruf
     * @param  callable(int): ?string  $adresse
     */
    public function __construct(
        private $ruf,
        private $adresse,
    ) {}

    public function ruhende(int $konto, Person $person): Schlummerliste
    {
        $postfach = $this->postfach($konto);

        if ($postfach === null || $person->adresse === '') {
            return Schlummerliste::unerreichbar();
        }

        $daten = $this->frage('mail.snoozes.list', [
            'mailbox' => $postfach,
            'person' => $person->adresse,
        ]);

        if ($daten === null) {
            return Schlummerliste::unerreichbar();
        }

        $zeilen = [];

        foreach ((array) ($daten['snoozes'] ?? []) as $roh) {
            if (! is_array($roh)) {
                continue;
            }

            $schlummer = Schlummer::aus($roh, $postfach, $person);

            // Nur was noch ruht. Die Mitte darf abgelaufene Zeilen
            // mitschicken — sie aufzuheben ist ihre Sache, sie zu zeigen
            // waere unsere.
            if ($schlummer !== null && ! $schlummer->faellig()) {
                $zeilen[] = $schlummer;
            }
        }

        return Schlummerliste::mit($zeilen);
    }

    public function weglegen(int $konto, Person $person, string $kette, string $nachricht, DateTimeInterface $bis): bool
    {
        $postfach = $this->postfach($konto);

        if ($postfach === null || $person->adresse === '') {
            return false;
        }

        return $this->frage('mail.snoozes.set', (new Schlummer(
            postfach: $postfach,
            person: $person,
            kette: $kette,
            nachricht: $nachricht,
            bis: $bis,
        ))->toArray()) !== null;
    }

    public function hervorholen(int $konto, Person $person, ?string $kette, string $nachricht): bool
    {
        $postfach = $this->postfach($konto);

        if ($postfach === null || $person->adresse === '') {
            return false;
        }

        return $this->frage('mail.snoozes.clear', array_filter([
            'mailbox' => $postfach,
            'person' => $person->adresse,
            'thread_id' => $kette,
            'message_id' => $nachricht,
        ], fn ($wert): bool => $wert !== null)) !== null;
    }

    /**
     * @param  array<string, mixed>  $argumente
     * @return array<string, mixed>|null `null` heisst: niemand war zu sprechen.
     */
    private function frage(string $faehigkeit, array $argumente): ?array
    {
        try {
            $antwort = ($this->ruf)($faehigkeit, $argumente);
        } catch (\Throwable $e) {
            Log::warning('Geschlummertes konnte nicht mit AI Brain abgeglichen werden: '.$e->getMessage());

            return null;
        }

        if (! is_array($antwort) || ($antwort['ok'] ?? null) === false) {
            return null;
        }

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
