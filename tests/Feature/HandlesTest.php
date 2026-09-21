<?php

use Peppermint\Mailbox\Handles;

/**
 * Was eine brauchbare Kennung ist (#5847 / 21.09.2026).
 *
 * Der Riegel hiess vorher `$uid <= 0` und stammte aus einer Zeit, in der jede
 * Kennung eine Zahl war. Eine JMAP-Zeichenkette fiel durch — und das Ergebnis
 * war ein Postfach, in dem sich keine Nachricht oeffnen liess.
 */
it('nimmt eine JMAP-Kennung an', function () {
    expect(Handles::usable('bpyaaaal1'))->toBeTrue();
});

it('nimmt eine echte uid an', function () {
    expect(Handles::usable(1))->toBeTrue()
        ->and(Handles::usable(172570))->toBeTrue();
});

it('lehnt ab, was gar keine Kennung ist', function () {
    // `UID FETCH 0` ist nicht „es passiert nichts", sondern „die
    // Nachrichtenmenge ist ungueltig" — die Meldung sagt einem Menschen
    // nichts und verdeckt die Ursache.
    expect(Handles::usable(0))->toBeFalse()
        ->and(Handles::usable(-1))->toBeFalse()
        ->and(Handles::usable(null))->toBeFalse()
        ->and(Handles::usable(''))->toBeFalse()
        ->and(Handles::usable('   '))->toBeFalse();
});

it('beurteilt eine Zahl in Zeichenketten-Kleidung wie eine Zahl', function () {
    // Routenparameter kommen IMMER als Zeichenkette. Waere `"0"` brauchbar,
    // haette der Riegel genau die Luecke, gegen die es ihn gibt.
    expect(Handles::usable('0'))->toBeFalse()
        ->and(Handles::usable('-1'))->toBeFalse()
        ->and(Handles::usable('172570'))->toBeTrue();
});

it('haelt eine Kennung, die zufaellig mit einer Ziffer beginnt, fuer brauchbar', function () {
    // Stalwart vergibt Zeichenketten wie `0abc`. Ein `(int)`-Kast daraus waere
    // 0 — und damit genau der Wert, den der Riegel abweist.
    expect(Handles::usable('0abc'))->toBeTrue()
        ->and(Handles::usable('1a2b3c'))->toBeTrue();
});
