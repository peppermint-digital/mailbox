<?php

namespace Peppermint\Mailbox\Zustand;

/**
 * Ein Mensch — in der einen Form, die ueber Systemgrenzen traegt, und in der,
 * die hier gilt.
 *
 * ## Warum nicht einfach die Benutzer-ID
 *
 * Weil sie nur HIER gilt, und das faellt nicht auf. Jedes Produkt fuehrt seine
 * eigene Nutzertabelle: Nutzer 5 im CRM und Nutzer 5 im Manager sind zwei
 * verschiedene Menschen. Eine Zustaendigkeit, die als Nummer in die Mitte
 * geschrieben wird, kommt im naechsten System als jemand anders zurueck —
 * ohne Fehler, ohne Hinweis, nur mit dem falschen Namen am Abzeichen.
 *
 * Dieselbe Regel wie beim Postfach, nur eine Ebene weiter: **Ueber
 * Systemgrenzen zaehlt, was eine Sache IST, nicht die Nummer, die sie HIER
 * hat.** Beim Postfach ist das die Mailadresse; beim Menschen auch.
 *
 * ## `nummer` darf fehlen
 *
 * Und das ist kein Mangel, sondern die ehrliche Antwort auf eine echte Lage:
 * Die Mitte kennt eine Zustaendigkeit fuer `anna@…`, und in DIESEM System gibt
 * es Anna nicht (oder sie darf dieses Postfach nicht sehen). Dann steht am
 * Abzeichen ihre Adresse und keine erfundene Nummer.
 *
 * Die Versuchung waere, sie in diesem Fall weggzulassen. Das hiesse: eine Mail
 * sieht frei aus, obwohl jemand an ihr arbeitet.
 */
final class Person
{
    public function __construct(
        public readonly string $adresse,
        public readonly ?int $nummer = null,
        public readonly string $name = '',
    ) {}

    /**
     * Wie der Mensch heisst, wenn man ihn hinschreiben muss.
     *
     * Ohne Namen die Adresse — nie leer. Ein Abzeichen ohne Beschriftung ist
     * ein Abzeichen, das niemand deuten kann.
     */
    public function anzeige(): string
    {
        return $this->name !== '' ? $this->name : $this->adresse;
    }

    /**
     * „Anna Meier" wird zu „AM", `anna@firma.de` zu „A".
     */
    public function initialen(): string
    {
        $teile = array_values(array_filter(preg_split('/\s+/', trim($this->anzeige())) ?: []));

        if ($teile === []) {
            return '?';
        }

        $erste = mb_substr($teile[0], 0, 1);
        $letzte = count($teile) > 1 ? mb_substr($teile[count($teile) - 1], 0, 1) : '';

        return mb_strtoupper($erste.$letzte);
    }

    /**
     * Dieselbe Person, hier aufgeloest.
     *
     * Die Mitte liefert die Adresse; wer sie ist, weiss das Produkt. Getrennt
     * vom Konstruktor, weil die Reihenfolge so ist: erst kommt die Aussage,
     * dann wird sie oertlich benannt.
     */
    public function hiesig(?int $nummer, string $name): self
    {
        return new self($this->adresse, $nummer, $name);
    }

    public static function aus(string $adresse, ?int $nummer = null, string $name = ''): ?self
    {
        $adresse = mb_strtolower(trim($adresse));

        // Ohne Adresse gibt es keinen Menschen, den ein anderes System finden
        // koennte. Eine Person nur mit Nummer waere genau der Datensatz, der
        // beim ersten Systemwechsel auf jemand anderen zeigt.
        return $adresse === '' ? null : new self($adresse, $nummer, $name);
    }
}
