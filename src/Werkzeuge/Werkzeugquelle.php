<?php

namespace Peppermint\Mailbox\Werkzeuge;

/**
 * Woher die Werkzeuge der ANDEREN Systeme kommen.
 *
 * Es gibt zwei Wege, und beide sind richtig — je nachdem, wo man steht:
 *
 * - Ein **Produkt** fragt ueber die Bruecke beim AI Brain an, und das Brain
 *   reicht an das Zielsystem weiter ({@see BridgeWerkzeugquelle}).
 * - Das **Brain** fragt selbst, ueber seinen eigenen Gateway. Es hat keine
 *   Bruecke, es IST die Gegenstelle.
 *
 * Deshalb eine Schnittstelle und keine Klasse: Das aufnehmende System bindet
 * die Umsetzung, die zu seiner Lage passt. Genauso loest es das Inbox-Paket,
 * und zwei Muster fuer dieselbe Lage waere eines zu viel.
 */
interface Werkzeugquelle
{
    /**
     * Welche Systeme ueberhaupt Werkzeuge anbieten koennen.
     *
     * Nur Systeme, fuer die dieses hier freigeschaltet ist — das entscheidet
     * das Faehigkeits-Register in Brain, nicht dieses Paket.
     *
     * @return array<int, array{slug: string, key?: string, label?: string}>
     */
    public function anbieter(): array;

    /**
     * Die Werkzeuge eines Systems fuer diesen Vorgang.
     *
     * @return array{tools: array<int, array<string, mixed>>, failed: array<int, string>}
     */
    public function holen(string $slug, Vorgang $vorgang, string $adresse): array;

    /**
     * Ein Werkzeug DRUEBEN ausloesen.
     *
     * @param  array<string, mixed>  $daten
     * @return array<string, mixed> Rohform von {@see Ergebnis::aus()}
     */
    public function ausloesen(string $slug, string $schluessel, Vorgang $vorgang, array $daten, string $adresse): array;
}
