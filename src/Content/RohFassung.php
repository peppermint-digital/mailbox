<?php

namespace Peppermint\Mailbox\Content;

/**
 * Die Rohbytes einer Nachricht — und die Frage, ob sie vollstaendig sind.
 *
 * ## Warum das eine eigene Klasse ist
 *
 * Die Rechnung ist drei Zeilen lang und stand zuerst mitten in der
 * IMAP-Sitzung. Dort ist sie ohne echten Server nicht pruefbar — und sie ist
 * genau die Aussage, auf die sich spaeter jemand beruft. Eine Behauptung, die
 * niemand messen kann, gehoert nicht an die unzugaenglichste Stelle im Haus.
 *
 * ## Zusammengesetzt ist nicht dasselbe wie geholt
 *
 * IMAP gibt die Quelle in zwei Stuecken heraus: `BODY[HEADER]` und
 * `BODY[TEXT]`. Der Kopfteil endet laut RFC 3501 mit der Leerzeile, die Kopf
 * und Rumpf trennt — aneinandergehaengt ergeben beide wieder die Nachricht.
 *
 * Fast immer. `RFC822.SIZE` sagt, wie gross sie auf dem Server ist; weicht die
 * Laenge ab, fehlt etwas oder ist etwas doppelt. Beides macht die
 * DKIM-Signatur wertlos, und beides faellt sonst nie auf — eine Kopie, die
 * niemand anzweifelt, wird auch nicht geprueft.
 */
final class RohFassung
{
    private function __construct(
        public readonly string $bytes,
        public readonly ?int $gemeldeteGroesse,
        public readonly bool $vollstaendig,
    ) {}

    /**
     * Aus Kopf und Rumpf, wie IMAP sie herausgibt.
     */
    public static function ausTeilen(string $kopf, string $rumpf, ?int $gemeldet): self
    {
        $bytes = $kopf.$rumpf;

        return new self(
            $bytes,
            $gemeldet,
            // Ohne Groessenangabe gibt es nichts zu pruefen. Dann ist die
            // ehrliche Antwort „unbestaetigt" und nicht „vollstaendig".
            $gemeldet !== null && strlen($bytes) === $gemeldet,
        );
    }

    /**
     * Aus einem Stueck, wie JMAP sie herausgibt.
     *
     * Hier wurde nichts zusammengesetzt, also kann auch nichts danebengehen.
     */
    public static function amStueck(string $bytes, ?int $gemeldet = null): self
    {
        return new self($bytes, $gemeldet ?? strlen($bytes), true);
    }

    /**
     * Der Abdruck, unter dem diese Bytes wiedererkennbar sind.
     */
    public function hash(): string
    {
        return hash('sha256', $this->bytes);
    }

    /**
     * @return array{raw: string, size: int|null, complete: bool}
     */
    public function toArray(): array
    {
        return [
            'raw' => $this->bytes,
            'size' => $this->gemeldeteGroesse,
            'complete' => $this->vollstaendig,
        ];
    }
}
