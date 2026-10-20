<?php

namespace Peppermint\Mailbox\Zustand;

/**
 * Die offenen Zuweisungen eines Postfachs — und ob die Liste vollstaendig ist.
 *
 * ## Warum nicht einfach ein Array
 *
 * Weil „keine Zuweisungen" und „ich konnte nicht nachsehen" dasselbe Array
 * ergeben, und das ist die teuerste Verwechslung in diesem ganzen Umbau.
 *
 * Die Regel steht im verbindlichen Wiki als Falle Nr. 2: **Leere Antwort ist
 * nicht leerer Bestand.** Sie hat dort schon einmal 36 Eintraege gekostet.
 * Hier waere die Folge subtiler und damit schlimmer: Jede Mail saehe
 * unzugewiesen aus, zwei Leute fingen dieselbe Sache an, und niemand bekaeme
 * eine Fehlermeldung.
 *
 * Also traegt die Antwort mit, ob sie eine Antwort ist. Die Oberflaeche sagt
 * dann „Zustaendigkeiten nicht erreichbar" statt „niemand zustaendig".
 */
final class Zuweisungsliste
{
    /**
     * @param  list<Zuweisung>  $zuweisungen
     * @param  bool  $erreichbar  War der Speicher ueberhaupt zu sprechen?
     */
    private function __construct(
        public readonly array $zuweisungen,
        public readonly bool $erreichbar,
    ) {}

    /** @param list<Zuweisung> $zuweisungen */
    public static function mit(array $zuweisungen): self
    {
        return new self($zuweisungen, true);
    }

    /**
     * Niemand war zu sprechen.
     *
     * Bewusst eine leere Liste UND `erreichbar: false` — nicht etwa der letzte
     * bekannte Stand. Einen alten Stand auszugeben, ohne ihn zu kennzeichnen,
     * waere die zweite Form derselben Luege.
     */
    public static function unerreichbar(): self
    {
        return new self([], false);
    }

    public function leer(): bool
    {
        return $this->zuweisungen === [];
    }
}
