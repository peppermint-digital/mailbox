<?php

namespace Peppermint\Mailbox\Stores;

use DateTimeImmutable;
use DateTimeInterface;
use Peppermint\Mailbox\Contracts\Schlummerspeicher;
use Peppermint\Mailbox\Http\HandlesSnoozes;
use Peppermint\Mailbox\Models\MailSnooze;
use Peppermint\Mailbox\Zustand\Person;
use Peppermint\Mailbox\Zustand\Schlummer;
use Peppermint\Mailbox\Zustand\Schlummerliste;

/**
 * Geschlummertes in der eigenen Tabelle.
 *
 * Das Verhalten, das bis zum 19.10.2026 direkt in
 * {@see HandlesSnoozes} stand — unveraendert
 * hierher gezogen, damit die Umstellung auf den Vertrag nichts anderes TUT.
 */
class LokalerSchlummerspeicher implements Schlummerspeicher
{
    /**
     * @param  (callable(int): ?string)|null  $adresse  Die Mailadresse zur oertlichen Kennung.
     */
    public function __construct(private $adresse = null) {}

    public function ruhende(int $konto, Person $person): Schlummerliste
    {
        if ($person->nummer === null) {
            // Die eigene Tabelle fuehrt Nummern. Ohne eine kann hier nichts
            // gefunden werden — und „nichts gefunden" waere die falsche
            // Antwort, weil es nicht heisst, dass nichts ruht.
            return Schlummerliste::unerreichbar();
        }

        $postfach = $this->postfach($konto);

        $zeilen = MailSnooze::query()
            ->where('email_account_id', $konto)
            ->where('user_id', $person->nummer)
            ->where('snooze_until', '>', now())
            ->get()
            ->map(fn (MailSnooze $s): ?Schlummer => $s->thread_id === null ? null : new Schlummer(
                postfach: $postfach,
                person: $person,
                kette: (string) $s->thread_id,
                nachricht: (string) $s->message_id,
                bis: $s->snooze_until?->toDateTimeImmutable() ?? new DateTimeImmutable,
            ))
            ->filter()
            ->values()
            ->all();

        return Schlummerliste::mit($zeilen);
    }

    public function weglegen(int $konto, Person $person, string $kette, string $nachricht, DateTimeInterface $bis): bool
    {
        if ($person->nummer === null) {
            return false;
        }

        MailSnooze::updateOrCreate(
            [
                'email_account_id' => $konto,
                'user_id' => $person->nummer,
                'thread_id' => $kette,
            ],
            [
                'message_id' => $nachricht,
                /*
                 * Nach UTC umgerechnet, bevor es in die Spalte geht.
                 *
                 * Sonst geht die Zone verloren, und zwar lautlos: Eloquent
                 * formatiert den uebergebenen Zeitpunkt in SEINER Zone, ohne
                 * umzurechnen. Aus „09:00+02:00" wird die Zeichenkette
                 * „09:00", gelesen wird sie als 09:00 UTC — die Nachricht
                 * kommt zwei Stunden zu spaet zurueck.
                 *
                 * Gefunden am 19.10.2026 von einem Test, der den AUGENBLICK
                 * verglichen hat statt der Schreibweise. Vorher stand die
                 * Warnung davor nur im Docblock.
                 *
                 * `@<Zeitstempel>` ist per Definition UTC — dafuer braucht es
                 * weder Carbon noch die Zeitzone der Anwendung.
                 */
                'snooze_until' => (new DateTimeImmutable('@'.$bis->getTimestamp()))->format('Y-m-d H:i:s'),
            ],
        );

        return true;
    }

    public function hervorholen(int $konto, Person $person, ?string $kette, string $nachricht): bool
    {
        if ($person->nummer === null) {
            return false;
        }

        MailSnooze::query()
            ->where('email_account_id', $konto)
            ->where('user_id', $person->nummer)
            ->where(function ($frage) use ($kette, $nachricht): void {
                // Die Suche nach der Nachricht bleibt neben der Kette stehen,
                // damit auch Altbestand ohne Kettenkennung verschwindet.
                $frage->where('message_id', $nachricht);

                if ($kette !== null) {
                    $frage->orWhere('thread_id', $kette);
                }
            })
            ->delete();

        return true;
    }

    private function postfach(int $konto): string
    {
        if ($this->adresse === null) {
            return '';
        }

        return (string) (($this->adresse)($konto) ?? '');
    }
}
