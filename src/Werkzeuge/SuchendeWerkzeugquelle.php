<?php

namespace Peppermint\Mailbox\Werkzeuge;

/**
 * Eine Werkzeugquelle, die auch SUCHEN kann.
 *
 * ## Warum das kein Zusatz an {@see Werkzeugquelle} ist
 *
 * Es war einer, fuer eine halbe Stunde, und die Testsuite hat es sofort
 * gemeldet: Eine Methode mehr am Pflicht-Vertrag macht jede bestehende
 * Umsetzung ungueltig — im Paket die Attrappe der Tests, in einem Produkt die
 * eigene Quelle, und zwar beim naechsten `composer update` und ohne Vorwarnung.
 *
 * Dieselbe Lehre steht seit dem 20.10.2026 an {@see LiefertFelder} und seit
 * heute an {@see LiefertAuswahl}. Hier ist sie zum dritten Mal faellig, und
 * diesmal hat sie nichts gekostet, weil sie schon aufgeschrieben war.
 *
 * ## Was eine Quelle ohne diesen Vertrag bedeutet
 *
 * Keine Treffer aus FREMDEN Systemen — die eigenen Verben suchen weiter, denn
 * die gehen nicht ueber eine Quelle. Das ist eine ehrliche Teilmenge und kein
 * Fehler: Ein Produkt mit eigenem Transport, der die Feldsuche nicht kann,
 * verliert nicht die Leiste, sondern einen Auswaehler an fremden Verben.
 */
interface SuchendeWerkzeugquelle extends Werkzeugquelle
{
    /**
     * Treffer fuer ein suchbares Feld aus einem anderen System.
     *
     * Unter der LESE-Faehigkeit (`mailbox.actions`), nicht unter der zum
     * Ausloesen: Suchen liest, was moeglich ist. Eine eigene Faehigkeit waere
     * eine dritte Stelle, an der ein Produkt sie vergessen kann — und das
     * stille Ergebnis waere ein Auswaehler ohne Treffer, was wie „gibt es
     * nicht" aussieht.
     *
     * Ausgepackt wie bei {@see Werkzeugquelle::holen()}: Die Huelle des
     * Gateways schaelt die Quelle selbst ab, damit darueber niemand mehr daran
     * denken muss.
     *
     * @return array<int, array{wert: string, wort: string, hinweis?: string}>
     */
    public function auswaehlen(string $slug, string $schluessel, string $feld, string $suche, Vorgang $vorgang, string $adresse): array;
}
