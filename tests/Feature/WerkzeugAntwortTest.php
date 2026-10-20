<?php

use Peppermint\Mailbox\Werkzeuge\Auspacken;

/**
 * Die Antwort eines ausgeloesten Werkzeugs auspacken (19.10.2026).
 *
 * Hier sitzt der teuerste Fehler dieses Bereichs: Aus „beauftragt" darf nicht
 * „erledigt" werden. Eine Gateway-Antwort hat eine aeussere Schale, die nur
 * ueber die ZUSTELLUNG spricht — wer sie fuer das Ergebnis haelt, meldet dem
 * Nutzer Erfolg fuer etwas, das im Zielsystem nie entstanden ist.
 *
 * Nur `ergebnis()`. `liste()` braucht `Huelle` aus der Bridge, und die ist ein
 * `suggest` — im Testbaum des Pakets gibt es sie nicht.
 */
it('liest das Ergebnis aus der Gateway-Huelle und nicht die Huelle selbst', function () {
    $ergebnis = Auspacken::ergebnis([
        'ok' => true,
        'product' => 'peppermint-verwaltung',
        'data' => ['ok' => true, 'message' => 'Beleg #4711 angelegt'],
    ]);

    expect($ergebnis['ok'])->toBeTrue()
        ->and($ergebnis['message'])->toBe('Beleg #4711 angelegt');
});

it('meldet einen zugestellten, aber abgelehnten Auftrag als Fehlschlag', function () {
    // Aeussere Schale gruen, inneres Ergebnis rot. Das ist der Normalfall
    // eines abgelehnten Vorgangs, kein Grenzfall.
    $ergebnis = Auspacken::ergebnis([
        'ok' => true,
        'product' => 'peppermint-verwaltung',
        'data' => ['ok' => false, 'message' => 'Dem Beleg fehlt ein Datum.'],
    ]);

    expect($ergebnis['ok'])->toBeFalse()
        ->and($ergebnis['message'])->toBe('Dem Beleg fehlt ein Datum.');
});

it('macht aus einer Gateway-Antwort ohne Nutzlast keinen stillen Erfolg', function () {
    // Der Fall, der die Oberflaeche am lautesten anluegt: Zustellung geklappt,
    // Nutzlast unterwegs verloren. Ohne diese Pruefung stuende dort ein
    // gruener Haken und ein leerer Satz.
    $ergebnis = Auspacken::ergebnis([
        'ok' => true,
        'product' => 'peppermint-verwaltung',
        'data' => null,
    ]);

    expect($ergebnis['ok'])->toBeFalse()
        ->and($ergebnis['message'])->toContain('nicht gesagt');
});

it('nennt den Grund, wenn der Auftrag gar nicht ankam', function () {
    $ergebnis = Auspacken::ergebnis([
        'ok' => false,
        'error' => 'Das System antwortet nicht.',
        'product' => 'peppermint-crm',
    ]);

    expect($ergebnis['ok'])->toBeFalse()
        ->and($ergebnis['message'])->toBe('Das System antwortet nicht.');
});

it('laesst einen Fehlschlag ohne Text nicht ohne Text', function () {
    // Eine Fehlermeldung ohne Satz sieht in der Oberflaeche aus wie ein
    // gelungener Vorgang, bei dem nur nichts passiert ist.
    $ergebnis = Auspacken::ergebnis(['ok' => false, 'product' => 'peppermint-crm']);

    expect($ergebnis['ok'])->toBeFalse()
        ->and($ergebnis['message'])->not->toBe('');
});

it('nimmt die nackte Form, wenn das Werkzeug direkt gerufen wurde', function () {
    // Die Nabe wird direkt gefragt, nicht ueber den Gateway. Dann traegt die
    // Antwort selbst das `ok`, und ihr `data` ist der Inhalt, nicht die Huelle.
    $ergebnis = Auspacken::ergebnis([
        'ok' => true,
        'message' => 'Aufgabe #8313 angelegt',
        'data' => ['task_id' => 8313],
    ]);

    expect($ergebnis['ok'])->toBeTrue()
        ->and($ergebnis['message'])->toBe('Aufgabe #8313 angelegt')
        ->and($ergebnis['data'])->toBe(['task_id' => 8313]);
});

it('findet das Ergebnis auch unter structuredContent', function () {
    // Dieselbe Nutzlast kommt je nach Weg in verschiedenen Huellen an. Wer
    // die Huelle nicht findet, bekommt „nichts" statt „wir haben nicht
    // hingesehen".
    $ergebnis = Auspacken::ergebnis([
        'ok' => true,
        'product' => 'peppermint-crm',
        'structuredContent' => ['ok' => true, 'message' => 'Kontakt zugeordnet'],
    ]);

    expect($ergebnis['message'])->toBe('Kontakt zugeordnet');
});
