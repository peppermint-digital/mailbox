<?php

namespace Peppermint\Mailbox\Zustand;

/**
 * Was fuer diese Person gerade schlummert — und ob die Liste vollstaendig ist.
 *
 * ## Warum hier die umgekehrte Vorsicht gilt
 *
 * Bei der Zustaendigkeit ist eine leere Liste die gefaehrliche Antwort: Jede
 * Mail saehe frei aus, zwei Leute fingen dieselbe Sache an.
 *
 * Beim Schlummern faellt der Ausfall in die andere Richtung, und er ist
 * harmloser: Eine Liste, die nicht geholt werden konnte, laesst
 * weggelegte Nachrichten wieder auftauchen. Das ist aergerlich, aber es
 * versteckt nichts — der schlimmere Fehler waere, bei einem Ausfall etwas
 * auszublenden, das jemand sehen muss.
 *
 * Trotzdem traegt die Antwort mit, ob sie eine ist: Wer eine Mail wegliegt und
 * sie sofort wieder vorfindet, haelt das Schlummern fuer kaputt. Mit der
 * Angabe kann die Oberflaeche sagen, dass sie gerade nicht nachsehen konnte.
 */
final class Schlummerliste
{
    /** @param list<Schlummer> $schlummer */
    private function __construct(
        public readonly array $schlummer,
        public readonly bool $erreichbar,
    ) {}

    /** @param list<Schlummer> $schlummer */
    public static function mit(array $schlummer): self
    {
        return new self($schlummer, true);
    }

    public static function unerreichbar(): self
    {
        return new self([], false);
    }

    /** @return list<string> Die Ketten, die gerade ruhen. */
    public function ketten(): array
    {
        return array_values(array_map(fn (Schlummer $s): string => $s->kette, $this->schlummer));
    }
}
