<?php

namespace Peppermint\Mailbox\Content;

/**
 * Die Rohbytes einer Nachricht — und die Frage, ob sie vollstaendig sind.
 *
 * ## Warum NICHT ueber die gemeldete Groesse
 *
 * Hier stand zuerst: „Laenge der Bytes muss `RFC822.SIZE` entsprechen, sonst
 * ist die Kopie unvollstaendig." Das klang zwingend und war falsch.
 *
 * Am 24.09.2026 waren damit 111 von 122 archivierten Nachrichten als
 * unvollstaendig markiert — und zwar GENAU die aus den Office-365-Postfaechern.
 * Die von Hetzner (IMAP) und Stalwart (JMAP) passten auf das Byte.
 *
 * Die Gegenprobe entschied es: 84 von 84 mehrteiligen Nachrichten endeten mit
 * ihrer schliessenden MIME-Grenze. Keine einzige war abgeschnitten. Exchange
 * meldet in `RFC822.SIZE` eine Groesse aus seiner internen Darstellung, die
 * groesser ist als die MIME-Ausgabe — bei einer Nachricht 120311 gegen
 * tatsaechlich 93349 Bytes.
 *
 * Eine Pruefung, die bei einem von zwei verbreiteten Anbietern ausnahmslos
 * Alarm schlaegt, prueft nicht die Daten, sondern sich selbst.
 *
 * ## Was stattdessen geprueft wird
 *
 * **Die Struktur.** Eine mehrteilige Nachricht endet mit ihrer schliessenden
 * Grenze (`--grenze--`). Fehlt die, wurde wirklich abgeschnitten — und das
 * faellt unabhaengig davon auf, was der Server ueber die Groesse behauptet.
 *
 * Bei einer einteiligen Nachricht gibt es nichts zu pruefen. Das ist kein
 * Versaeumnis: IMAP kuendigt jedes Literal mit seiner Laenge an (`{93349}`),
 * und ein kurz gelesenes Literal ist ein Protokollfehler, den die Bibliothek
 * meldet. Eine still abgeschnittene einteilige Nachricht gibt es in diesem
 * Weg nicht.
 *
 * ## Die gemeldete Groesse bleibt trotzdem stehen
 *
 * Sie ist eine Angabe des Servers, keine Zusicherung — und als solche
 * aufzuheben ist nuetzlich, etwa um genau diesen Unterschied zwischen
 * Anbietern wiederzufinden. Sie entscheidet nur nichts mehr.
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

        return new self($bytes, $gemeldet, self::strukturellVollstaendig($bytes));
    }

    /**
     * Aus einem Stueck, wie JMAP sie herausgibt.
     */
    public static function amStueck(string $bytes, ?int $gemeldet = null): self
    {
        return new self($bytes, $gemeldet ?? strlen($bytes), self::strukturellVollstaendig($bytes));
    }

    /**
     * Weicht die Groesse von der gemeldeten ab?
     *
     * Kein Fehler — siehe oben. Aber eine Angabe, die man haben will, wenn
     * jemand fragt, warum zwei Zahlen nicht zusammenpassen.
     */
    public function groesseWeichtAb(): bool
    {
        return $this->gemeldeteGroesse !== null && strlen($this->bytes) !== $this->gemeldeteGroesse;
    }

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

    /**
     * Endet eine mehrteilige Nachricht mit ihrer schliessenden Grenze?
     *
     * Gesucht wird nur im letzten Stueck: Die Grenze steht als Zeichenkette
     * auch in jedem Trenner mitten im Text, und „kommt irgendwo vor" waere
     * deshalb keine Aussage ueber das Ende.
     */
    public static function strukturellVollstaendig(string $bytes): bool
    {
        if (preg_match('/boundary="?([^";\r\n]+)/i', $bytes, $treffer) !== 1) {
            // Einteilig — nichts zu pruefen, und nichts zu behaupten.
            return true;
        }

        $grenze = '--'.rtrim($treffer[1], '"').'--';

        return str_contains(substr($bytes, -max(600, strlen($grenze) + 200)), $grenze);
    }
}
