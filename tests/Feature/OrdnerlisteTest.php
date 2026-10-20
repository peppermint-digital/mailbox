<?php

use Illuminate\Support\Facades\Cache;
use Peppermint\Mailbox\Folders\Ordnerliste;

/**
 * Die gemerkte Ordnerliste (#6119).
 *
 * Gemessen am 23.09.2026 live im CRM: 485 ms je Abfrage, beim zweiten Mal
 * 525 ms — der zweite Aufruf war so teuer wie der erste, es gab keinen
 * Zwischenspeicher.
 *
 * Geprueft wird hier vor allem, was einen Zwischenspeicher GEFAEHRLICH macht:
 *
 * - Ein gemerkter Fehlschlag verlaengert einen Ausfall.
 * - Eine gemerkte Liste, die nach einer Aenderung stehenbleibt, laesst
 *   jemanden denselben Ordner zweimal anlegen.
 *
 * Beides ist schlimmer als die halbe Sekunde, die gespart wird.
 */
beforeEach(function () {
    Cache::flush();
});

it('holt die Liste nur EINMAL und gibt sie danach aus dem Speicher', function () {
    $aufrufe = 0;

    $holen = function () use (&$aufrufe): array {
        $aufrufe++;

        return [['name' => 'INBOX', 'path' => 'INBOX', 'flags' => []]];
    };

    $erste = Ordnerliste::fuer(7, $holen);
    $zweite = Ordnerliste::fuer(7, $holen);

    expect($aufrufe)->toBe(1)
        ->and($zweite)->toBe($erste);
});

it('haelt die Postfaecher auseinander', function () {
    // Ein Schluessel je Konto. Ohne das saehe ein Postfach die Ordner eines
    // anderen — und das waere nicht nur falsch, sondern ein Blick in ein
    // fremdes Postfach.
    Ordnerliste::fuer(7, fn (): array => [['name' => 'Sieben', 'path' => 'Sieben', 'flags' => []]]);
    $zwoelf = Ordnerliste::fuer(12, fn (): array => [['name' => 'Zwoelf', 'path' => 'Zwoelf', 'flags' => []]]);

    expect($zwoelf[0]['name'])->toBe('Zwoelf');
});

it('merkt eine LEERE Liste nicht — das ist ein Fehlschlag, kein Bestand', function () {
    /*
     * Kein Postfach hat null Ordner; es gibt mindestens INBOX. Eine leere
     * Antwort heisst also „die Abfrage ging schief". Sie zehn Minuten
     * festzuhalten hiesse, einen kurzen Ausfall zu verlaengern — dieselbe
     * Falle wie „leere Antwort ≠ leerer Bestand", die dieses Projekt schon
     * 36 geloeschte Eintraege gekostet hat.
     */
    $aufrufe = 0;

    $leer = function () use (&$aufrufe): array {
        $aufrufe++;

        return [];
    };

    Ordnerliste::fuer(7, $leer);
    Ordnerliste::fuer(7, $leer);

    expect($aufrufe)->toBe(2);
});

it('gibt eine geleerte Liste beim naechsten Mal frisch heraus', function () {
    $aufrufe = 0;

    $holen = function () use (&$aufrufe): array {
        $aufrufe++;

        return [['name' => "Lauf {$aufrufe}", 'path' => 'X', 'flags' => []]];
    };

    Ordnerliste::fuer(7, $holen);
    Ordnerliste::vergessen(7);
    $zweite = Ordnerliste::fuer(7, $holen);

    expect($aufrufe)->toBe(2)
        ->and($zweite[0]['name'])->toBe('Lauf 2');
});

it('leert nur das genannte Postfach', function () {
    Ordnerliste::fuer(7, fn (): array => [['name' => 'Sieben', 'path' => 'S', 'flags' => []]]);
    Ordnerliste::fuer(12, fn (): array => [['name' => 'Zwoelf', 'path' => 'Z', 'flags' => []]]);

    Ordnerliste::vergessen(7);

    $aufrufe = 0;
    Ordnerliste::fuer(12, function () use (&$aufrufe): array {
        $aufrufe++;

        return [];
    });

    // 12 lag noch im Speicher, also wurde nicht geholt.
    expect($aufrufe)->toBe(0);
});

it('fasst den Zwischenspeicher gar nicht an, wenn die Dauer auf 0 steht', function () {
    /*
     * Ein Produkt mit einem Postfach, in dem laufend Ordner entstehen, soll
     * abschalten koennen — und `0` ist dafuer die eindeutigere Angabe als ein
     * fehlender Schluessel.
     *
     * Geprueft wird, dass der Speicher NICHT BEFRAGT wird, und nicht bloss,
     * dass nichts liegenbleibt. Der Unterschied ist nicht akademisch: Eine
     * Mutationsprobe, die den Zweig entfernte, blieb zuerst gruen — Laravel
     * verwirft eine Dauer <= 0 von selbst, also war das Ergebnis dasselbe.
     *
     * Ein Zweig, dessen Entfernen kein Test merkt, ist entweder toter Code
     * oder falsch geprueft. Hier war das Zweite der Fall: Ausgeschaltet heisst
     * „den Speicher nicht einbeziehen", nicht „hineinschreiben und sofort
     * verfallen lassen" — das waere bei einem gemeinsam genutzten Redis ein
     * Netzweg pro Aufruf fuer nichts.
     */
    config()->set('mailbox.folders_cache_ttl', 0);

    Cache::shouldReceive('get')->never();
    Cache::shouldReceive('put')->never();

    $aufrufe = 0;

    $holen = function () use (&$aufrufe): array {
        $aufrufe++;

        return [['name' => 'INBOX', 'path' => 'INBOX', 'flags' => []]];
    };

    Ordnerliste::fuer(7, $holen);
    Ordnerliste::fuer(7, $holen);

    expect($aufrufe)->toBe(2);
});

it('haelt sich an die eingestellte Dauer', function () {
    config()->set('mailbox.folders_cache_ttl', 600);

    Ordnerliste::fuer(7, fn (): array => [['name' => 'INBOX', 'path' => 'INBOX', 'flags' => []]]);

    // Nicht die Dauer selbst nachrechnen — nur, DASS etwas liegt. Eine
    // Prüfung auf Sekunden hinge an der Uhr des Testlaufs.
    expect(Cache::has(Ordnerliste::PRAEFIX.'7'))->toBeTrue();
});
