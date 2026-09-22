<?php

use Peppermint\Mailbox\Support\Betreff;

/**
 * Der Betreff, lesbar gemacht (22.09.2026).
 *
 * Anlass: In der Liste stand `Neue Nachricht von &quot;McPaper&quot;`. Die
 * Kopfzeile der Mail traegt diese Zeichen wirklich — ein Versender hat den
 * Betreff HTML-kodiert, obwohl eine MIME-Kopfzeile reiner Text ist.
 */
it('loest HTML-Entitaeten auf', function () {
    expect(Betreff::lesbar('Neue Nachricht von &quot;McPaper&quot;'))
        ->toBe('Neue Nachricht von "McPaper"');
});

it('kommt auch mit Umlauten und Zahlen-Entitaeten zurecht', function () {
    expect(Betreff::lesbar('Gr&uuml;&szlig;e &amp; Dank &#8212; Rechnung'))
        ->toBe('Grüße & Dank — Rechnung');
});

it('laesst einen gewoehnlichen Betreff unveraendert', function () {
    // Der haeufigste Fall, und der wichtigste: nichts anfassen.
    foreach (['Rechnung 2026-001', 'Grüße aus Hannover', 'Re: Bestellung zum 3.10.', ''] as $betreff) {
        expect(Betreff::lesbar($betreff))->toBe($betreff);
    }
});

it('laesst null null sein', function () {
    // Eine Nachricht ohne Betreff gibt es; daraus darf keine leere
    // Zeichenkette werden, sonst faellt die Ersatzanzeige („Kein Betreff")
    // der Oberflaeche aus.
    expect(Betreff::lesbar(null))->toBeNull();
});

it('fasst einen Betreff ohne kaufmaennisches Und gar nicht erst an', function () {
    // Der Kurzschluss ist Vorsicht, nicht Geschwindigkeit: Was keine Entitaet
    // enthalten KANN, wird auch nicht durch den Dekodierer geschickt.
    $mitSemikolon = 'Wichtig; bitte bis Freitag';

    expect(Betreff::lesbar($mitSemikolon))->toBe($mitSemikolon);
});
