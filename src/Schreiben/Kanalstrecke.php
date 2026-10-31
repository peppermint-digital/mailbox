<?php

namespace Peppermint\Mailbox\Schreiben;

/**
 * Wie eine Kanalanfrage zum Brain kommt — und nur das.
 *
 * ## Warum es diese Naht gibt
 *
 * {@see Kanalklient} wusste zwei verschiedene Dinge: WAS beim Warten auf einen
 * Kanal zu entscheiden ist (Frist, Takt, `needs_input` ist kein Ende, das
 * Ergebnis kommt in drei Gestalten) und WIE die Anfrage hinkommt (ueber die
 * Bruecke, per Netz). Das erste gilt ueberall. Das zweite gilt in drei von
 * vier Produkten.
 *
 * Das vierte ist AI Brain selbst, und dort ist die Bruecke ein Umweg zu sich
 * selbst: `verfuegbar()` sagt dort `false` (Bruecke installiert, nicht
 * eingerichtet), und mit einem Netzaufruf an den eigenen Server waere es
 * langsamer und zerbrechlicher als ein Dienstaufruf im Prozess.
 *
 * Vorgeschlagen hat die Naht `mail-2b` am 31.10.2026, nachdem der Keeper die
 * Frage entschieden hatte: **Kann das Paket die Bindung allgemein loesen,
 * gehoert sie ins Paket und nicht als Sonderweg ins Produkt.** Mein erster
 * Rat war der falsche — ich hatte AI Brain eine eigene Schreibhilfe bauen
 * lassen wollen, und das haette das Pollen, die Frist, `needs_input` und das
 * Entpacken ein zweites Mal gebaut. Vier Entscheidungen, von denen jede
 * einzeln falsch werden kann, und niemand haette gemerkt, welche der beiden
 * Fassungen abdriftet.
 *
 * ## Die Grenze, und sie ist eng gezogen
 *
 * Hier steht NUR der Transport: hinschicken, nachsehen, und ob er ueberhaupt
 * benutzbar ist. Kein Warten, keine Frist, kein Entpacken — das bleibt im
 * {@see Kanalklient}, denn genau dafuer gibt es die Naht. Eine Strecke, die
 * selbst wartet, waere die zweite Schleife, die wir vermeiden wollten.
 *
 * ## Wer das umsetzt
 *
 * Drei Produkte: niemand. Sie bekommen {@see BrueckenStrecke} als Vorgabe.
 *
 * AI Brain: eine schmale Umsetzung gegen die eigenen Dienste
 * (`ChannelChat::forChannel(...)` zum Hineinschicken, der Stand aus der
 * Chat-Zeile zum Nachsehen). Rund dreissig Zeilen, und sie beantworten
 * ausschliesslich Transportfragen.
 */
interface Kanalstrecke
{
    /**
     * Ist dieser Weg benutzbar?
     *
     * Nicht „gibt es die Klasse" — AI Brain TRAEGT die Bruecke, ohne sie
     * einzurichten. Die Frage lautet: Kaeme eine Anfrage auf diesem Weg an?
     *
     * Ein Produkt fragt das, BEVOR es den Knopf zeigt. Ein Assistent, der
     * erst beim Klick zugibt, dass es ihn nicht gibt, ist schlechter als
     * keiner.
     */
    public function eingerichtet(): bool;

    /**
     * Die Anfrage hineinschicken und die Kennung des Gespraechs zurueckgeben.
     *
     * @param  array<string, mixed>  $nutzlast
     *
     * @throws \RuntimeException mit einem Satz fuer den Absender, wenn der Weg
     *                           nicht zustandekommt oder die Anfrage nicht
     *                           angenommen wird
     */
    public function hineinschicken(string $kanal, array $nutzlast): int;

    /**
     * Der Stand eines Gespraechs.
     *
     * `status` muss einer der Werte aus {@see Kanalklient} sein — sonst
     * entscheidet {@see Kanalklient::wartet()} falsch, und zwar still: Ein
     * unbekannter Status gilt als „fertig", und dann wird ein leeres Ergebnis
     * fuer eine Antwort gehalten.
     *
     * @return array{status: string, result: mixed, error: ?string}
     *
     * @throws \RuntimeException wenn der Stand nicht zu erfragen ist
     */
    public function nachsehen(int $chatId): array;
}
