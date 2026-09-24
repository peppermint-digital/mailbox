<?php

use Peppermint\Mailbox\Content\RohFassung;

/**
 * Ob eine gespeicherte Kopie wirklich die ganze Nachricht ist.
 *
 * Die erste Fassung verglich die Laenge mit `RFC822.SIZE`. Das klang zwingend
 * und markierte am 24.09.2026 111 von 122 archivierten Nachrichten als
 * unvollstaendig — genau die aus den Office-365-Postfaechern, waehrend die von
 * Hetzner und Stalwart aufs Byte passten.
 *
 * 84 von 84 mehrteiligen Nachrichten endeten dabei mit ihrer schliessenden
 * Grenze. Nicht die Daten waren kaputt, sondern die Pruefung.
 */
function mehrteilig(string $abschluss): string
{
    return "From: a@example.test\r\n"
        ."Content-Type: multipart/mixed; boundary=\"GRENZE123\"\r\n\r\n"
        ."--GRENZE123\r\nContent-Type: text/plain\r\n\r\nHallo.\r\n"
        .$abschluss;
}

it('haelt eine mehrteilige Nachricht mit schliessender Grenze fuer vollstaendig', function () {
    $roh = RohFassung::ausTeilen(mehrteilig("--GRENZE123--\r\n"), '', 99999);

    // Die gemeldete Groesse weicht ab — und das entscheidet NICHTS mehr.
    expect($roh->vollstaendig)->toBeTrue()
        ->and($roh->groesseWeichtAb())->toBeTrue();
});

it('erkennt eine mehrteilige Nachricht ohne schliessende Grenze als abgeschnitten', function () {
    // Der Fall, den die Pruefung wirklich fangen soll.
    $roh = RohFassung::ausTeilen(mehrteilig(''), '', null);

    expect($roh->vollstaendig)->toBeFalse();
});

it('laesst sich von der Grenze im Text nicht taeuschen', function () {
    // Die Grenze steht auch in jedem Trenner mitten in der Nachricht. „Kommt
    // irgendwo vor" waere deshalb keine Aussage ueber das Ende.
    $abgeschnitten = mehrteilig("--GRENZE123--\r\n").str_repeat("FORTSETZUNG OHNE ENDE\r\n", 80);

    expect(RohFassung::strukturellVollstaendig($abgeschnitten))->toBeFalse();
});

it('behauptet bei einer einteiligen Nachricht nichts', function () {
    // IMAP kuendigt jedes Literal mit seiner Laenge an; ein kurz gelesenes
    // Literal ist ein Protokollfehler, den die Bibliothek meldet.
    $roh = RohFassung::ausTeilen("From: a@example.test\r\n\r\n", 'Hallo.', 9999);

    expect($roh->vollstaendig)->toBeTrue();
});

it('merkt sich die gemeldete Groesse, auch wenn sie abweicht', function () {
    $roh = RohFassung::ausTeilen("From: a@example.test\r\n\r\n", 'Hallo.', 120311);

    expect($roh->gemeldeteGroesse)->toBe(120311)
        ->and($roh->groesseWeichtAb())->toBeTrue();
});

it('meldet keine Abweichung, wenn die Groesse passt', function () {
    $kopf = "From: a@example.test\r\n\r\n";
    $rumpf = 'Hallo.';

    expect(RohFassung::ausTeilen($kopf, $rumpf, strlen($kopf.$rumpf))->groesseWeichtAb())->toBeFalse();
});

it('bildet den Abdruck ueber die Bytes', function () {
    expect(RohFassung::amStueck('ganze Nachricht')->hash())->toBe(hash('sha256', 'ganze Nachricht'));
});

it('liefert die Form, die die Schnittstelle zusagt', function () {
    expect(RohFassung::ausTeilen('a', 'b', 2)->toArray())->toBe(['raw' => 'ab', 'size' => 2, 'complete' => true]);
});
