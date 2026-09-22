<?php

namespace Peppermint\Mailbox\Sending;

/**
 * Eine Nachricht, die hinausgehen soll — noch ohne Transport, ohne Netz.
 *
 * ## Warum ein Wertobjekt und keine zehn Parameter
 *
 * Der Sendedienst des Projekt-Managers nahm zehn: `$to`, `$cc`, `$bcc`,
 * `$subject`, `$bodyHtml`, `$attachments`, `$rawAttachments`, `$bodyText`,
 * `$inlineImages` — in dieser Reihenfolge. Wer einen davon vergisst oder
 * vertauscht, merkt es nicht beim Uebersetzen, sondern an einer Mail ohne
 * Anhang.
 *
 * ## Der Klartext-Zweig ist kein Beiwerk
 *
 * Eine Mail ohne Textteil ist bei Spamfiltern ein schwaches Signal, und
 * Vorschau-Erzeuger sowie Clients ohne HTML-Darstellung lesen ihn. Deshalb
 * steht er hier gleichberechtigt neben dem HTML.
 */
class Outgoing
{
    /**
     * @param  list<array{email: string, name?: string|null}>  $to
     * @param  list<array{email: string, name?: string|null}>  $cc
     * @param  list<array{email: string, name?: string|null}>  $bcc
     * @param  list<array{path: string, filename: string, mime?: string|null}>  $attachments  Dateien auf der Platte
     * @param  list<array{contents: string, filename: string, mime?: string|null}>  $rawAttachments  Bytes im Speicher
     * @param  list<array{contents: string, cid: string, mime?: string|null}>  $inlineImages  vom HTML als `cid:` referenziert
     * @param  array<string, string>  $headers  zusaetzliche Kopfzeilen, etwa `In-Reply-To`
     */
    public function __construct(
        public readonly string $subject,
        public readonly string $html,
        public readonly array $to,
        public readonly array $cc = [],
        public readonly array $bcc = [],
        public readonly ?string $text = null,
        public readonly array $attachments = [],
        public readonly array $rawAttachments = [],
        public readonly array $inlineImages = [],
        public readonly array $headers = [],
    ) {}

    /**
     * Dieselbe Nachricht mit zusaetzlichen Kopfzeilen.
     *
     * Fuer Antworten: `In-Reply-To` und `References` entscheiden, ob die
     * Nachricht beim Empfaenger in der richtigen Unterhaltung landet — ohne
     * sie beginnt jede Antwort einen neuen Strang.
     */
    public function withHeaders(array $headers): self
    {
        return new self(
            $this->subject,
            $this->html,
            $this->to,
            $this->cc,
            $this->bcc,
            $this->text,
            $this->attachments,
            $this->rawAttachments,
            $this->inlineImages,
            [...$this->headers, ...$headers],
        );
    }
}
