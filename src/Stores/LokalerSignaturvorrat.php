<?php

namespace Peppermint\Mailbox\Stores;

use Peppermint\Mailbox\Contracts\Signaturvorrat;
use Peppermint\Mailbox\Mcp\HandelndePerson;
use Peppermint\Mailbox\Models\MailAccount;
use Peppermint\Mailbox\Models\MailSignature;

/**
 * Die Signaturen aus der eigenen Tabelle — fuer ein Produkt, das sie selbst
 * fuehrt.
 *
 * Gegenstueck zu {@see BrainSignaturvorrat}: dieselbe Frage nach dem Paar
 * (Person, Postfach), dieselbe Antwort, nur ohne Weg ueber die Mitte. Ein
 * Produkt bindet das eine ODER das andere; das Paket bindet keines von
 * beiden, weil „dieses Produkt fuehrt keine Signaturen" eine gueltige Lage
 * ist (siehe {@see Signaturvorrat}).
 *
 * ## Die Person ist hier die oertliche Kennung
 *
 * Ueber die Bruecke war es die Adresse, weil dieselbe Person in jedem System
 * eine andere Nummer hat. Hier liegen Person und Signatur in derselben
 * Datenbank, also gilt die Nummer.
 */
class LokalerSignaturvorrat implements Signaturvorrat
{
    /**
     * @param  (callable(): (int|string|null))|null  $person  Wessen Signaturen.
     *                                                        Ohne Angabe die handelnde bzw. angemeldete Person.
     */
    public function __construct(private $person = null) {}

    /**
     * @return list<array{id: int|string, name: string, body_html: string, is_default: bool}>
     */
    public function signaturen(MailAccount $konto): array
    {
        $person = $this->person();

        if ($person === null) {
            // Ohne Person nichts — „alle Signaturen dieses Postfachs" waere der
            // stille Weg, mit der Unterschrift eines Kollegen zu antworten.
            return [];
        }

        $kennung = $konto->field('id');

        return MailSignature::query()
            ->fuerBereich($person, is_scalar($kennung) ? (int) $kennung : null)
            ->get(['id', 'name', 'body_html', 'is_default'])
            // Eine leere Zeile waere in der Auswahl sichtbar und beim
            // Einsetzen leer — dieselbe Regel wie im Brain-Vorrat.
            ->filter(fn (MailSignature $zeile): bool => trim((string) $zeile->body_html) !== '')
            ->map(fn (MailSignature $zeile): array => [
                'id' => $zeile->id,
                'name' => (string) $zeile->name,
                'body_html' => (string) $zeile->body_html,
                'is_default' => (bool) $zeile->is_default,
            ])
            ->values()
            ->all();
    }

    private function person(): ?int
    {
        $roh = $this->person !== null
            ? ($this->person)()
            : HandelndePerson::finden()?->getKey();

        return is_numeric($roh) ? (int) $roh : null;
    }
}
