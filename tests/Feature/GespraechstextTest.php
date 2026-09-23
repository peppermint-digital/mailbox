<?php

use Peppermint\Mailbox\Content\Gespraechstext;

/**
 * Die Trennung von „was jemand geschrieben hat" und dem Rest.
 *
 * Die Beispiele sind absichtlich so geschrieben, wie E-Mails wirklich
 * aussehen — mit Outlook-Kopfblock, mit fehlendem Signaturtrenner, mit
 * Haftungshinweis ohne Leerzeile davor. Eine Heuristik an sauberen Vorlagen
 * zu prüfen beweist nichts über den Posteingang.
 */
it('schneidet den zitierten Vorgängertext weg', function () {
    $g = Gespraechstext::aus(<<<'TEXT'
    Passt, machen wir Dienstag.

    Am 12.09.2026 um 14:33 schrieb Max Mustermann:
    > Wollen wir den Termin auf Dienstag legen?
    > Mir wäre 10 Uhr recht.
    TEXT);

    expect($g->inhalt())->toBe('Passt, machen wir Dienstag.')
        ->and($g->zitat())->toContain('Wollen wir den Termin');
});

it('erkennt ein Zitat auch ohne Einleitungszeile', function () {
    $g = Gespraechstext::aus(<<<'TEXT'
    Ja, passt.

    > Kommst du Donnerstag?
    TEXT);

    expect($g->inhalt())->toBe('Ja, passt.')
        ->and($g->zitat())->toContain('Kommst du Donnerstag?');
});

it('erkennt den Outlook-Kopfblock ohne Trennlinie', function () {
    $g = Gespraechstext::aus(<<<'TEXT'
    Anbei die Rechnung.

    Von: Max Mustermann <max@example.de>
    Gesendet: Freitag, 12. September 2026 09:12
    An: Buchhaltung
    Betreff: Rechnung September

    Bitte um Zusendung der Rechnung.
    TEXT);

    expect($g->inhalt())->toBe('Anbei die Rechnung.')
        ->and($g->zitat())->toContain('Betreff: Rechnung September');
});

it('trennt die Signatur am Trennzeichen aus RFC 3676', function () {
    $g = Gespraechstext::aus("Kurz zur Info: der Termin steht.\n\n-- \nLuka Haase\nPeppermint Digital GmbH\nTel. 0123 456789");

    expect($g->inhalt())->toBe('Kurz zur Info: der Termin steht.')
        ->and($g->signatur())->toContain('Peppermint Digital GmbH');
});

it('trennt die Signatur auch ohne Trennzeichen an der Grussformel', function () {
    $g = Gespraechstext::aus(<<<'TEXT'
    die Unterlagen sind raus.

    Mit freundlichen Grüßen
    Luka Haase

    Peppermint Digital GmbH
    Tel. 0123 456789
    TEXT);

    expect($g->inhalt())->toBe('die Unterlagen sind raus.')
        ->and($g->signatur())->toContain('Luka Haase');
});

it('verschluckt keinen Inhalt, der nach einer Grussformel noch kommt', function () {
    // Der Fall, an dem eine zu gierige Regel scheitert: „Viele Grüße" steht
    // mitten im Text, und danach kommt die eigentliche Ansage.
    $text = <<<'TEXT'
    Viele Grüße an deine Frau, sag ihr danke für gestern.

    Was ich eigentlich fragen wollte: Können wir den Liefertermin auf die
    kommende Woche schieben? Das Lager ist bis Freitag dicht und es waere
    schade, wenn die Ware davor ankommt und niemand sie annehmen kann.
    TEXT;

    $g = Gespraechstext::aus($text);

    expect($g->inhalt())->toContain('Liefertermin')
        ->and($g->signatur())->toBeNull();
});

it('trennt den Haftungshinweis ab', function () {
    $g = Gespraechstext::aus(<<<'TEXT'
    Anbei der Entwurf.

    Diese E-Mail enthält vertrauliche Informationen. Sollten Sie nicht der
    richtige Adressat sein, informieren Sie bitte den Absender.
    TEXT);

    expect($g->inhalt())->toBe('Anbei der Entwurf.')
        ->and($g->fusszeile())->toContain('vertrauliche Informationen');
});

it('erkennt die Signatur eines Mobilgeräts', function () {
    $g = Gespraechstext::aus("Bin unterwegs, melde mich später.\n\nVon meinem iPhone gesendet");

    expect($g->inhalt())->toBe('Bin unterwegs, melde mich später.')
        ->and($g->signatur())->toContain('iPhone');
});

it('räumt eine Nachricht mit allem auf einmal auf', function () {
    $g = Gespraechstext::aus(<<<'TEXT'
    guten Morgen,

    die Freigabe ist da. Wir können starten.

    Mit freundlichen Grüßen
    Chris Tolksdorf
    Peppermint Digital GmbH
    Tel. 0123 456789

    Diese E-Mail enthält vertrauliche Informationen.

    Am 11.09.2026 um 08:02 schrieb Kunde:
    > Gibt es schon eine Freigabe?
    TEXT);

    expect($g->inhalt())->toBe("guten Morgen,\n\ndie Freigabe ist da. Wir können starten.")
        ->and($g->signatur())->toContain('Chris Tolksdorf')
        ->and($g->fusszeile())->toContain('vertrauliche')
        ->and($g->zitat())->toContain('Gibt es schon eine Freigabe?');
});

it('gibt alles unverändert zurück, wenn nichts zu trennen ist', function () {
    $g = Gespraechstext::aus('Nur ein Satz.');

    expect($g->inhalt())->toBe('Nur ein Satz.')
        ->and($g->zitat())->toBeNull()
        ->and($g->signatur())->toBeNull()
        ->and($g->fusszeile())->toBeNull();
});

it('verliert nichts — was getrennt wurde, ergibt wieder den Eingang', function () {
    // Die Messung statt der Behauptung. Ohne sie wäre „es geht nichts
    // verloren" ein Versprechen ohne Deckung.
    $eingang = "Kurz zur Info.\n\nMit freundlichen Grüßen\nLuka\n\nDiese E-Mail enthält vertrauliche Informationen.\n\nAm 11.09.2026 um 08:02 schrieb Kunde:\n> Und?";

    $g = Gespraechstext::aus($eingang);

    $ohneLeerzeilen = fn (string $t): string => trim((string) preg_replace("/\n{2,}/", "\n", $t));

    expect($ohneLeerzeilen($g->vollstaendig()))->toBe($ohneLeerzeilen($eingang));
});

it('sagt, wenn nichts übrig bleibt', function () {
    $g = Gespraechstext::aus("Mit freundlichen Grüßen\nLuka Haase");

    expect($g->istLeer())->toBeTrue();
});

it('kommt mit einer leeren Nachricht zurecht', function () {
    $g = Gespraechstext::aus(null);

    expect($g->istLeer())->toBeTrue()
        ->and($g->inhalt())->toBe('');
});

it('macht aus HTML lesbaren Text und trennt darin genauso', function () {
    $g = Gespraechstext::ausHtml(
        '<div><p>Die Freigabe ist da.</p><p>Mit freundlichen Gr&uuml;&szlig;en<br>Chris</p></div>'
        .'<style>p{color:red}</style>'
    );

    expect($g->inhalt())->toBe('Die Freigabe ist da.')
        ->and($g->signatur())->toContain('Chris')
        ->and($g->vollstaendig())->not->toContain('color:red');
});

it('behandelt ein geschütztes Leerzeichen wie ein Leerzeichen', function () {
    // Sieht aus wie ein Leerzeichen, ist keins: Ohne die Umwandlung greift
    // kein einziges der zeilenweisen Muster auf so einer Zeile.
    $g = Gespraechstext::ausHtml('<p>Alles klar.</p><p>--&nbsp;<br>Luka</p>');

    expect($g->inhalt())->toBe('Alles klar.')
        ->and($g->signatur())->toContain('Luka');
});
