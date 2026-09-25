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
        /*
         * Verglichen wird der INHALT, nicht die Anwesenheit.
         *
         * Hier stand zuerst „liegt schon etwas da, dann nichts tun" — mit der
         * richtigen Begruendung, dass dieselbe Nachricht ein zweites Mal zu
         * holen normal ist und ein Archiv nichts umschreiben soll.
         *
         * Am 24.09.2026 zeigte die Pruefsummenprobe, was diese Regel anrichtet:
         * Eine Nachricht lag mit 1894 Bytes auf der Platte, waehrend die Zeile
         * 41029 nannte. Ein frueherer, unvollstaendiger Abruf hatte die Datei
         * geschrieben; der spaetere vollstaendige fand sie vor und verwarf sich
         * selbst. Die Regel bewahrte damit ausgerechnet die kaputte Fassung.
         *
         * Gleiche Bytes → nichts zu tun (und kein Schreibvorgang auf einem
         * Archiv, das sich nicht aendern soll). ANDERE Bytes → die Datei stimmt
         * nicht mit dem ueberein, was die Datenbank ueber sie behauptet, und
         * das ist kein Zustand, den man konservieren will.
         */
        if ($this->platte()->exists($pfad)) {
            $vorhanden = (string) $this->platte()->get($pfad);

            if (hash('sha256', $vorhanden) === hash('sha256', $bytes)) {
                return;
            }
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

    public function entfernen(string $pfad): bool
    {
        if (! $this->platte()->exists($pfad)) {
            return false;
        }

        return $this->platte()->delete($pfad);
    }

    private function platte(): Filesystem
    {
        return $this->platte ?? Storage::disk((string) config('mailbox.archive.disk', 'local'));
    }
}
