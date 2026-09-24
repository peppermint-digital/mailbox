<?php

namespace Peppermint\Mailbox\Stores;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Peppermint\Mailbox\Contracts\Verlaufsspeicher;

/**
 * Der Verlauf aus der Mitte — fuer jedes System, das selbst nicht archiviert.
 *
 * ## Warum ueberhaupt ein Zwischenspeicher
 *
 * Aus demselben Grund wie bei den Postfaechern, aber mit anderer Frist. Die
 * Verfuegbarkeits-Frage stellt die Oberflaeche bei JEDER geoeffneten
 * Nachricht — ohne Zwischenspeicher waere das ein Rundruf in die Mitte pro
 * Klick, nur um meist „nein" zu hoeren.
 *
 * Der Verlauf selbst wird kuerzer gehalten: Er waechst, wenn eine Antwort
 * kommt, und eine Unterhaltung, die sich gerade entwickelt, soll nicht
 * minutenlang alt aussehen.
 *
 * ## Gefragt wird nach der ADRESSE, nicht nach der Nummer
 *
 * Dieselbe Mailbox hat in jedem System eine andere Kennung — die Adresse ist,
 * was sie IST. Wer mit der oertlichen Nummer fragt, fragt die Mitte nach IHRER
 * Nummer drei, und die gehoert einem anderen Postfach.
 *
 * Das faellt nicht als Fehler auf, sondern als falscher Inhalt: Ein
 * Kettenschluessel ist eine Message-ID und weltweit eindeutig — passt er
 * zufaellig auch im fremden Postfach, kommt dessen Unterhaltung zurueck.
 *
 * Die Nummer geht weiter mit, fuer Systeme, deren Kennungen ohnehin die der
 * Mitte sind. Die Mitte bevorzugt die Adresse, wenn sie da ist.
 *
 * ## Eine unerreichbare Mitte ist kein Fehler
 *
 * Dann gibt es eben keinen Verlauf — der Knopf erscheint nicht, die normale
 * Ansicht steht da. Wer hier wuerfe, naehme wegen einer Lesehilfe die ganze
 * Nachrichtenansicht mit.
 */
class BrainVerlaufsspeicher implements Verlaufsspeicher
{
    /**
     * @param  callable(string, array<string, mixed>): ?array  $fetch  Liefert die
     *         dekodierte Antwort des Werkzeugs — die Huelle `{ok, data}`, wie
     *         sie drueben ueber die Leitung geht —, oder null, wenn die Mitte
     *         nicht erreichbar war.
     * @param  (callable(int): ?string)|null  $adresse  Die Mailadresse zur
     *         oertlichen Kennung. Ohne sie fragt dieser Speicher mit einer
     *         Nummer, die nur HIER gilt — siehe unten.
     */
    public function __construct(
        private $fetch,
        private readonly int $verfuegbarkeitTtl = 300,
        private readonly int $verlaufTtl = 60,
        private $adresse = null,
    ) {}

    public function verfuegbar(int $account, string $thread): bool
    {
        if ($thread === '') {
            return false;
        }

        return (bool) Cache::remember(
            $this->schluessel('verfuegbar', $account, $thread),
            $this->verfuegbarkeitTtl,
            fn (): bool => (bool) ($this->frage($account, $thread, nurVerfuegbarkeit: true)['available'] ?? false),
        );
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function verlauf(int $account, string $thread): array
    {
        if ($thread === '') {
            return [];
        }

        return Cache::remember(
            $this->schluessel('verlauf', $account, $thread),
            $this->verlaufTtl,
            fn (): array => (array) ($this->frage($account, $thread)['entries'] ?? []),
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function frage(int $account, string $thread, bool $nurVerfuegbarkeit = false): array
    {
        try {
            $antwort = ($this->fetch)('mail.conversation', array_filter([
                'account_id' => $account,
                'mailbox' => $this->adresse === null ? null : ($this->adresse)($account),
                'thread' => $thread,
                'only_availability' => $nurVerfuegbarkeit,
            ], fn ($wert): bool => $wert !== null));

            // Die Nutzlast steckt in `data` — dieselbe Huelle wie bei den
            // Postfaechern. Sie zu uebersehen faellt nicht auf: Dann ist
            // `available` eben nie gesetzt, der Knopf erscheint nirgends, und
            // das sieht aus wie „es gibt noch keine Verlaeufe".
            return is_array($antwort) ? (array) ($antwort['data'] ?? []) : [];
        } catch (\Throwable $e) {
            // Eine unerreichbare Mitte ist die Lage, fuer die es den Rueckfall
            // gibt — keine Ausnahme, die jemand behandeln muesste.
            Log::warning('Gespraechsverlauf konnte nicht aus AI Brain gelesen werden: '.$e->getMessage());

            return [];
        }
    }

    private function schluessel(string $was, int $account, string $thread): string
    {
        // Der Kettenschluessel darf fast jedes Zeichen enthalten — als Teil
        // eines Cache-Schluessels waere das je nach Treiber ein Problem.
        return "mailbox.verlauf.{$was}.{$account}.".sha1($thread);
    }
}
