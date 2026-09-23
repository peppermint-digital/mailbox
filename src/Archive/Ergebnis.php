<?php

namespace Peppermint\Mailbox\Archive;

/**
 * Was ein Erfassungslauf in einem Ordner getan und gesehen hat.
 *
 * ## Warum das ein Ergebnis ist und keine Log-Zeile
 *
 * „Lueckenlosigkeit" ist kein Gefuehl. Wer spaeter belegen soll, dass nichts
 * fehlt, braucht Zahlen: wie viele Kennungen der Ordner hatte, welche
 * Gueltigkeitsnummer galt, bis zu welcher Kennung geschaut wurde, wie viele
 * aufgenommen wurden — und was NICHT geklappt hat.
 *
 * Besonders das Letzte. Ein Lauf, der zehn Nachrichten aufnimmt und bei dreien
 * scheitert, ohne das zu sagen, ist schlimmer als einer, der ganz abbricht:
 * Er sieht erfolgreich aus.
 */
class Ergebnis
{
    public int $aufgenommen = 0;

    public int $verschwunden = 0;

    public int $ausDemPostfach = 0;

    public int $entwischt = 0;

    public int $offen = 0;

    /** @var list<array{uid: string, grund: string}> */
    public array $fehler = [];

    /** @var list<string> */
    public array $unvollstaendig = [];

    public ?int $umbruchVon = null;

    public ?int $umbruchNach = null;

    public bool $ordnerFehlt = false;

    public bool $uebersprungen = false;

    public function __construct(
        public readonly string $ordner,
        public readonly ?int $uidvalidity = null,
        public readonly ?int $uidnext = null,
    ) {}

    public static function ordnerFehlt(string $ordner): self
    {
        $e = new self($ordner);
        $e->ordnerFehlt = true;

        return $e;
    }

    /** Der Ordner sah aus wie beim letzten Mal — nicht geoeffnet. */
    public function unveraendert(): void
    {
        $this->uebersprungen = true;
    }

    public function aufgenommen(): void
    {
        $this->aufgenommen++;
    }

    public function verschwunden(): void
    {
        $this->verschwunden++;
    }

    public function ausDemPostfach(): void
    {
        $this->ausDemPostfach++;
    }

    /** Zwischen Auflisten und Holen weg — ein geteiltes Postfach, kein Fehler. */
    public function entwischt(): void
    {
        $this->entwischt++;
    }

    /** Wie viele beim naechsten Lauf noch drankommen. */
    public function offen(int $anzahl): void
    {
        $this->offen = $anzahl;
    }

    public function fehler(string $uid, string $grund): void
    {
        $this->fehler[] = ['uid' => $uid, 'grund' => $grund];
    }

    public function unvollstaendig(string $messageId): void
    {
        $this->unvollstaendig[] = $messageId;
    }

    public function umbruch(?int $von, ?int $nach): void
    {
        $this->umbruchVon = $von;
        $this->umbruchNach = $nach;
    }

    /**
     * Ist der Lauf sauber durchgelaufen?
     *
     * Ausdruecklich NICHT „hat er etwas getan". Ein Ordner, in dem nichts neu
     * war, ist ein erfolgreicher Lauf.
     */
    public function sauber(): bool
    {
        return ! $this->ordnerFehlt && $this->fehler === [] && $this->unvollstaendig === [];
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'folder' => $this->ordner,
            'uidvalidity' => $this->uidvalidity,
            'uidnext' => $this->uidnext,
            'captured' => $this->aufgenommen,
            'vanished' => $this->verschwunden,
            'left_mailbox' => $this->ausDemPostfach,
            'slipped_away' => $this->entwischt,
            'pending' => $this->offen,
            'errors' => $this->fehler,
            'incomplete' => $this->unvollstaendig,
            'uidvalidity_break' => $this->umbruchVon !== null
                ? ['from' => $this->umbruchVon, 'to' => $this->umbruchNach]
                : null,
            'folder_missing' => $this->ordnerFehlt,
            'skipped' => $this->uebersprungen,
            'clean' => $this->sauber(),
        ];
    }
}
