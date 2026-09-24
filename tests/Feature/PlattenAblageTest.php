<?php

use Illuminate\Support\Facades\Storage;
use Peppermint\Mailbox\Archive\PlattenAblage;

/**
 * Die ECHTE Ablage, nicht die nachgebaute.
 *
 * Diese Datei gibt es, weil der Test dafuer zuerst gegen eine Attrappe lief —
 * und die hatte den Fehler mit nachgebaut („wie die echte: nicht
 * ueberschreiben"). Eine Mutationsprobe an der echten Klasse liess den Test
 * deshalb kalt: Er prueft sie gar nicht.
 *
 * Eine Attrappe, die sich verhaelt wie der Code, den sie ersetzt, prueft
 * diesen Code nicht — sie bestaetigt nur die eigene Annahme.
 */
it('legt eine neue Datei ab', function () {
    $platte = Storage::fake('ablage-test');

    (new PlattenAblage($platte))->ablegen('mail/1/a.eml', 'INHALT');

    expect($platte->get('mail/1/a.eml'))->toBe('INHALT');
});

it('schreibt nicht neu, wenn dieselben Bytes schon daliegen', function () {
    // Ein Archiv soll sich nicht aendern. Gleiches gleich zu ueberschreiben
    // waere ein Schreibvorgang ohne Anlass — und in jeder Sicherung eine
    // Aenderung, die keine ist.
    //
    // GEZAEHLT und nicht am Zeitstempel gemessen: Der hat Sekundenaufloesung,
    // und zwei Schreibvorgaenge in derselben Sekunde sehen daran gleich aus.
    // Genau daran ist die erste Fassung dieses Tests gescheitert — sie blieb
    // gruen, als ich die Bedingung testweise entfernte.
    $geschrieben = 0;
    $inhalt = 'INHALT';

    $platte = Mockery::mock(Illuminate\Contracts\Filesystem\Filesystem::class);
    // Gewoehnliche Closures mit Referenz: Eine Pfeilfunktion faengt den Wert
    // beim DEFINIEREN ein und saehe hier immer 0.
    $platte->shouldReceive('exists')->andReturnUsing(function () use (&$geschrieben): bool {
        return $geschrieben > 0;
    });
    $platte->shouldReceive('get')->andReturnUsing(function () use (&$inhalt): string {
        return $inhalt;
    });
    $platte->shouldReceive('put')->andReturnUsing(function () use (&$geschrieben): bool {
        $geschrieben++;

        return true;
    });

    $ablage = new PlattenAblage($platte);

    $ablage->ablegen('mail/1/a.eml', 'INHALT');
    $ablage->ablegen('mail/1/a.eml', 'INHALT');
    $ablage->ablegen('mail/1/a.eml', 'INHALT');

    expect($geschrieben)->toBe(1);
});

it('ersetzt eine Datei, deren Inhalt nicht mehr stimmt', function () {
    // Der Fall vom 24.09.2026: 1894 Bytes auf der Platte, 41029 in der Zeile.
    // Ein frueherer, unvollstaendiger Abruf hatte gewonnen, weil „liegt schon
    // da" als Grund galt, nichts zu tun.
    $platte = Storage::fake('ablage-test');
    $ablage = new PlattenAblage($platte);

    $ablage->ablegen('mail/1/a.eml', 'HALBE NACHRICHT');
    $ablage->ablegen('mail/1/a.eml', 'DIE GANZE NACHRICHT, VOLLSTAENDIG');

    expect($platte->get('mail/1/a.eml'))->toBe('DIE GANZE NACHRICHT, VOLLSTAENDIG');
});

it('sagt, ob etwas daliegt, und gibt es zurück', function () {
    $platte = Storage::fake('ablage-test');
    $ablage = new PlattenAblage($platte);

    expect($ablage->vorhanden('mail/1/a.eml'))->toBeFalse()
        ->and($ablage->lesen('mail/1/a.eml'))->toBeNull();

    $ablage->ablegen('mail/1/a.eml', 'INHALT');

    expect($ablage->vorhanden('mail/1/a.eml'))->toBeTrue()
        ->and($ablage->lesen('mail/1/a.eml'))->toBe('INHALT');
});
