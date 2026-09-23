<?php

namespace Peppermint\Mailbox\Archive;

use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Support\Facades\Storage;

/**
 * Die Vorgabe-Ablage: eine Laravel-Platte.
 *
 * Welche, sagt `mailbox.archive.disk`. Ein Produkt, das seine Mails woanders
 * hinlegen will — Objektspeicher, ein Archivsystem, ein WORM-Volume —,
 * schreibt eine eigene Umsetzung von {@see Ablage} und bindet sie.
 */
class PlattenAblage implements Ablage
{
    public function __construct(private readonly ?Filesystem $platte = null) {}

    public function ablegen(string $pfad, string $bytes): void
    {
        // Nicht ueberschreiben. Dieselbe Nachricht ein zweites Mal zu holen ist
        // normal; sie neu zu schreiben waere eine Aenderung an etwas, das
        // unveraendert bleiben soll.
        if ($this->platte()->exists($pfad)) {
            return;
        }

        $this->platte()->put($pfad, $bytes);
    }

    public function vorhanden(string $pfad): bool
    {
        return $this->platte()->exists($pfad);
    }

    public function lesen(string $pfad): ?string
    {
        return $this->platte()->exists($pfad) ? $this->platte()->get($pfad) : null;
    }

    private function platte(): Filesystem
    {
        return $this->platte ?? Storage::disk((string) config('mailbox.archive.disk', 'local'));
    }
}
