<?php

namespace Peppermint\Mailbox\AddressBook;


/**
 * Der Teil des Adressbuchs, den alle Produkte gemeinsam haben: der gepflegte
 * Kontaktbestand aus `peppermint/contacts`.
 *
 * ## Warum nur dieser Teil
 *
 * Der Projekt-Manager kennt daneben gelernte Empfaenger, Kollegen und die
 * Absender der letzten Wochen. Das sind seine eigenen Tabellen, und sie ins
 * Paket zu ziehen hiesse, jedem Produkt drei Tabellen aufzudraengen, die es
 * nicht hat.
 *
 * Geteilt wird, was wirklich geteilt ist. Der Rest kommt beim Produkt dazu —
 * additiv, nicht als Sonderfall im gemeinsamen Code.
 *
 * ## Eine unerreichbare Ablage ist kein Fehler dieser Klasse
 *
 * `StoreUnavailable` wird hier gefangen und als leere Liste beantwortet. Das
 * Adressbuch soll benutzbar bleiben, wenn der zentrale Kontaktbestand gerade
 * nicht antwortet — die anderen Quellen des Produkts haengen nicht an ihm.
 * Wer stattdessen durchwirft, nimmt bei einer Stoerung in EINER Quelle die
 * ganze Seite mit.
 *
 * ## `peppermint/contacts` ist eine Empfehlung, keine Pflicht
 *
 * Das Paket haelt seine Abhaengigkeiten schmal; Optionales steht unter
 * `suggest`. Fehlt der Kontaktbestand, gibt es hier eine leere Liste statt
 * eines Absturzes — ein Produkt ohne zentrale Kontakte bekommt dann eben nur
 * seine eigenen Quellen. Die Klassen werden deshalb ueber ihren Namen
 * angesprochen und vorher geprueft.
 */
class ContactEntries
{
    /**
     * Passende Kontakte als Adressbuch-Zeilen.
     *
     * Ein Kontakt mit mehreren Adressen ergibt mehrere Zeilen: Gesucht wird
     * eine Adresse, nicht eine Person, und welche der drei gemeint ist, weiss
     * nur der Mensch davor.
     *
     * @return list<array{email: string, name: string, company: string|null, source: string}>
     */
    public static function search(string $query, int $limit = 20, string $source = 'adressbuch'): array
    {
        $vertrag = 'Peppermint\\Contacts\\Contracts\\ContactStore';

        if (! interface_exists($vertrag) || ! app()->bound($vertrag)) {
            return [];
        }

        try {
            $treffer = app($vertrag)->search($query, $limit);
        } catch (\Throwable) {
            // `StoreUnavailable` heisst hier alles, was die Ablage nicht
            // beantworten kann. Den Klassennamen zu pruefen hiesse, das Paket
            // doch wieder an `contacts` zu binden.
            return [];
        }

        $zeilen = [];

        foreach ($treffer as $kontakt) {
            foreach ($kontakt->emails as $mail) {
                $adresse = trim((string) $mail->value);

                if ($adresse === '') {
                    continue;
                }

                $zeilen[] = [
                    'email' => $adresse,
                    'name' => (string) ($kontakt->formatted_name ?? ''),
                    // Bei einer Organisation stuende ihr eigener Name sonst
                    // zweimal da — einmal als Name reicht.
                    'company' => $kontakt->organization !== $kontakt->formatted_name
                        ? ($kontakt->organization ?: null)
                        : null,
                    'source' => $source,
                ];
            }
        }

        return $zeilen;
    }
}
