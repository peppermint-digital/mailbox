<?php

use Peppermint\Mailbox\Models\MailAccount;
use Peppermint\Mailbox\Stores\BrainSignaturvorrat;
use Peppermint\Mailbox\Stores\BrainVerlaufsspeicher;

/**
 * Die Signaturen aus der Mitte (Signatur-Umzug, 01.11.2026).
 *
 * Gebaut und geprüft wie {@see BrainVerlaufsspeicher}:
 * Der Aufruf kommt als Rückruf herein, also lässt sich die Leitung fälschen,
 * ohne eine eingerichtete Brücke zu brauchen — ein Test, der sie braucht,
 * prüft am Ende die Brücke und nicht diesen Vorrat.
 */
function vorratsKonto(): MailAccount
{
    $konto = new MailAccount;
    $konto->forceFill(['id' => 3, 'email' => 'info@example.test']);

    return $konto;
}

it('holt die Signaturen zum Paar aus Postfach und Person', function () {
    $gefragt = [];

    $vorrat = new BrainSignaturvorrat(
        function (string $werkzeug, array $nutzlast) use (&$gefragt): array {
            $gefragt = ['werkzeug' => $werkzeug] + $nutzlast;

            // Genau die Huelle, die das Werkzeug drueben schickt. Ein Fake, der
            // sie weglaesst, prueft die eigene Annahme statt der Leitung.
            return ['ok' => true, 'data' => [
                ['id' => 7, 'name' => 'Kurz', 'body_html' => '<p>-- <br>B</p>', 'is_default' => true],
                ['id' => 8, 'name' => 'Lang', 'body_html' => '<p>lang</p>', 'is_default' => false],
            ]];
        },
        fn (): string => 'bastian@example.test',
    );

    $signaturen = $vorrat->signaturen(vorratsKonto());

    expect($gefragt)->toBe([
        'werkzeug' => 'mail-signatures-tool',
        'account_id' => 3,
        // Die ADRESSE, nicht die oertliche Nummer: Dieselbe Person hat in
        // jedem System eine andere.
        'owner_email' => 'bastian@example.test',
    ])
        ->and($signaturen)->toHaveCount(2)
        ->and($signaturen[0])->toBe([
            'id' => 7,
            'name' => 'Kurz',
            'body_html' => '<p>-- <br>B</p>',
            'is_default' => true,
        ])
        // Die Reihenfolge der Mitte bleibt: Sie entscheidet, was vorausgewaehlt
        // wird — am Postfach vor allgemein, darin die Vorgabe.
        ->and($signaturen[1]['name'])->toBe('Lang');
});

it('uebersieht die Huelle nicht', function () {
    /*
     * Der Fehler, der hier haengt: `data` zu ueberspringen. Nichts wirft,
     * nichts protokolliert — die Signaturzeile erscheint nur nie, und das
     * sieht aus wie „dieses Produkt fuehrt keine Signaturen".
     */
    $vorrat = new BrainSignaturvorrat(
        fn (): array => [['id' => 1, 'name' => 'Ohne Huelle', 'body_html' => '<p>x</p>']],
        fn (): string => 'bastian@example.test',
    );

    expect($vorrat->signaturen(vorratsKonto()))->toBe([]);
});

it('laesst eine Zeile ohne Text weg', function () {
    // Eine Signatur ohne Rumpf waere in der Auswahl sichtbar und beim
    // Einsetzen leer — der Mensch waehlt etwas und bekommt nichts.
    $vorrat = new BrainSignaturvorrat(
        fn (): array => ['data' => [
            ['id' => 1, 'name' => 'Leer', 'body_html' => ''],
            ['id' => 2, 'name' => 'Mit Text', 'body_html' => '<p>da</p>'],
        ]],
        fn (): string => 'bastian@example.test',
    );

    $signaturen = $vorrat->signaturen(vorratsKonto());

    expect($signaturen)->toHaveCount(1)
        ->and($signaturen[0]['name'])->toBe('Mit Text');
});

it('fragt gar nicht erst, wenn niemand erkennbar ist', function () {
    $gefragt = false;

    $vorrat = new BrainSignaturvorrat(
        function () use (&$gefragt): array {
            $gefragt = true;

            return ['data' => [['id' => 1, 'name' => 'X', 'body_html' => '<p>x</p>']]];
        },
        fn (): ?string => null,
    );

    expect($vorrat->signaturen(vorratsKonto()))->toBe([])
        ->and($gefragt)->toBeFalse();
});

it('nimmt eine unerreichbare Mitte als „keine Signaturen", nicht als Fehler', function () {
    // Wer hier wuerfe, naehme wegen einer Unterschrift die ganze
    // Verfassen-Seite mit. Ohne Signaturen kann man eine Mail schreiben, ohne
    // Formular nicht.
    $vorrat = new BrainSignaturvorrat(
        fn () => throw new RuntimeException('Gateway weg'),
        fn (): string => 'bastian@example.test',
    );

    expect($vorrat->signaturen(vorratsKonto()))->toBe([]);
});
