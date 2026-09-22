<?php

use Peppermint\Mailbox\AddressBook\ContactEntries;

/**
 * Der gemeinsame Teil des Adressbuchs (22.09.2026).
 *
 * `peppermint/contacts` ist fuer dieses Paket eine Empfehlung, keine Pflicht.
 * Deshalb wird der Vertrag hier ueber seinen Namen angesprochen — und deshalb
 * legt dieser Test ihn selbst an, wenn er fehlt. Ohne das waere der ganze
 * Abbildungsteil ungeprueft, und „gibt leere Liste zurueck" saehe genauso aus
 * wie „funktioniert".
 */
if (! interface_exists('Peppermint\Contacts\Contracts\ContactStore')) {
    eval('namespace Peppermint\Contacts\Contracts; interface ContactStore { public function search(string $query, int $limit); }');
}

/** Ein Kontakt, wie ihn die Ablage liefert: Adressen als Objekte mit `value`. */
function kontakt(string $name, array $adressen, ?string $firma = null): object
{
    return new class($name, $adressen, $firma)
    {
        public function __construct(public string $formatted_name, public array $emails, public ?string $organization)
        {
            $this->emails = array_map(fn (string $a) => (object) ['value' => $a], $emails);
        }
    };
}

function ablage(callable $antwort): void
{
    app()->bind('Peppermint\Contacts\Contracts\ContactStore', fn () => new class($antwort) implements Peppermint\Contacts\Contracts\ContactStore
    {
        public function __construct(private $antwort) {}

        public function search(string $query, int $limit)
        {
            return ($this->antwort)($query, $limit);
        }
    });
}

afterEach(function () {
    app()->forgetInstance('Peppermint\Contacts\Contracts\ContactStore');
});

it('gibt eine leere Liste, wenn es gar keinen Kontaktbestand gibt', function () {
    // Ein Produkt ohne `peppermint/contacts` soll ein Adressbuch mit seinen
    // eigenen Quellen bekommen — keinen Absturz.
    expect(ContactEntries::search('Meier'))->toBe([]);
});

it('macht aus jeder Adresse eines Kontakts eine eigene Zeile', function () {
    // Gesucht wird eine ADRESSE, nicht eine Person. Welche der drei gemeint
    // ist, weiss nur der Mensch davor — also muessen alle drei dastehen.
    ablage(fn () => [kontakt('Anna Meier', ['anna@example.test', 'a.meier@example.test'])]);

    $zeilen = ContactEntries::search('Meier');

    expect($zeilen)->toHaveCount(2)
        ->and(array_column($zeilen, 'email'))->toBe(['anna@example.test', 'a.meier@example.test'])
        ->and($zeilen[0]['name'])->toBe('Anna Meier')
        ->and($zeilen[0]['source'])->toBe('adressbuch');
});

it('nennt die Firma nur, wenn sie nicht schon der Name ist', function () {
    // Bei einer Organisation stuende ihr eigener Name sonst zweimal da.
    ablage(fn () => [kontakt('Beispiel GmbH', ['info@example.test'], 'Beispiel GmbH')]);

    expect(ContactEntries::search('Beispiel')[0]['company'])->toBeNull();

    app()->forgetInstance('Peppermint\Contacts\Contracts\ContactStore');
    ablage(fn () => [kontakt('Anna Meier', ['anna@example.test'], 'Beispiel GmbH')]);

    expect(ContactEntries::search('Meier')[0]['company'])->toBe('Beispiel GmbH');
});

it('laesst leere Adressen weg', function () {
    // Eine Zeile ohne Adresse ist im Adressbuch eine Zeile, die man anklickt
    // und die nichts tut.
    ablage(fn () => [kontakt('Anna Meier', ['', '   ', 'anna@example.test'])]);

    expect(ContactEntries::search('Meier'))->toHaveCount(1);
});

it('beantwortet eine unerreichbare Ablage mit einer leeren Liste', function () {
    // Ein Ausfall in EINER Quelle darf nicht die ganze Seite mitnehmen — die
    // uebrigen Quellen des Produkts haengen nicht an ihr.
    ablage(fn () => throw new RuntimeException('Ablage antwortet nicht'));

    expect(ContactEntries::search('Meier'))->toBe([]);
});

it('reicht die Obergrenze an die Ablage durch', function () {
    // Ohne sie holt eine Suche nach „a" den ganzen Bestand.
    $gesehen = null;
    ablage(function ($query, $limit) use (&$gesehen) {
        $gesehen = [$query, $limit];

        return [];
    });

    ContactEntries::search('Meier', 5);

    expect($gesehen)->toBe(['Meier', 5]);
});
