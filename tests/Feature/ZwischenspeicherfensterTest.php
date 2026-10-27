<?php

use Illuminate\Support\Facades\Log;
use Peppermint\Mailbox\Accounts\Zwischenspeicherfenster;
use Peppermint\Mailbox\Stores\BrainAccountStore;

/**
 * Der Deckel auf `mailbox.cache_ttl` (Task #8856, aus Bug #1098).
 *
 * Geprueft wird nicht nur, DASS gedeckelt wird, sondern auch dass es hoerbar
 * passiert: Ein stiller Deckel laesst die naechste Fehlersuche in die falsche
 * Richtung laufen.
 */
beforeEach(function () {
    // Die Warnung geht einmal je Prozess hinaus, und Pest laedt alle Tests in
    // EINEN Prozess — ohne das hier saehe sie nur der erste Test, der sie
    // ausloest, und jeder weitere pruefte stillschweigend nichts.
    Zwischenspeicherfenster::erneutMelden();
});

it('laesst eine Dauer unter dem Deckel unveraendert — und schweigt dazu', function () {
    Log::spy();

    expect(Zwischenspeicherfenster::gedeckelt(900))->toBe(900);

    Log::shouldNotHaveReceived('warning');
});

it('laesst die Dauer AUF dem Deckel durch', function () {
    // Die Grenze gehoert dazu: Ein Deckel, der seinen eigenen Wert schon
    // kuerzt, verschiebt die Vorgabe des Pakets um ein Fenster nach unten,
    // ohne dass es jemand beschlossen haette.
    expect(Zwischenspeicherfenster::gedeckelt(Zwischenspeicherfenster::deckel()))
        ->toBe(Zwischenspeicherfenster::deckel());
});

it('deckelt eine zu lange Dauer und nennt beide Zahlen', function () {
    Log::spy();

    expect(Zwischenspeicherfenster::gedeckelt(1800))->toBe(900);

    Log::shouldHaveReceived('warning')
        ->withArgs(fn (string $meldung) => str_contains($meldung, '1800')
            && str_contains($meldung, '900')
            && str_contains($meldung, 'MINDESTLAUFZEIT_MINUTEN'))
        ->once();
});

it('warnt einmal je Prozess, nicht einmal je Lesevorgang', function () {
    // Sonst ertraenkt eine Fehlkonfiguration das Protokoll — und eine Warnung,
    // die jede Zeile fuellt, liest am Ende niemand mehr.
    Log::spy();

    Zwischenspeicherfenster::gedeckelt(1800);
    Zwischenspeicherfenster::gedeckelt(3600);
    Zwischenspeicherfenster::gedeckelt(1800);

    Log::shouldHaveReceived('warning')->once();
});

it('bleibt unter der Mindestlaufzeit, die die Mitte zusichert', function () {
    // Der Grund selbst, nicht nur seine Folge. Dieser Test ist das Gegenstueck
    // zu dem in AI Brain, der die Mindestlaufzeit gegen die Paket-Vorgabe
    // haelt: Wird hier einmal der Deckel angehoben, ohne dort die
    // Mindestlaufzeit, faellt dieser Test und nicht erst ein Postfach.
    expect(Zwischenspeicherfenster::deckel())
        ->toBeLessThan(Zwischenspeicherfenster::MINDESTLAUFZEIT_SEKUNDEN);
});

it('deckelt auch den Zwischenspeicher der Kontenablage selbst', function () {
    // Der Deckel sitzt in der Klasse, die den Speicher fuehrt, nicht an der
    // Stelle, die die Konfiguration liest: Sonst umgeht ihn jeder, der das
    // Paket selbst verdrahtet.
    expect((new BrainAccountStore(fn () => null, 1800))->fenster())->toBe(900)
        ->and((new BrainAccountStore(fn () => null, 600))->fenster())->toBe(600)
        ->and((new BrainAccountStore(fn () => null))->fenster())->toBe(900);
});
