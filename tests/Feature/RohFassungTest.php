<?php

use Peppermint\Mailbox\Content\RohFassung;

/**
 * Ob eine gespeicherte Kopie wirklich die Nachricht ist.
 *
 * Diese Rechnung stand zuerst mitten in der IMAP-Sitzung, wo sie ohne echten
 * Server nicht pruefbar war — und sie ist genau die Aussage, auf die sich im
 * Streitfall jemand beruft.
 */
it('bestätigt eine Kopie, deren Länge zur gemeldeten Größe passt', function () {
    $kopf = "From: a@example.test\r\nSubject: Test\r\n\r\n";
    $rumpf = "Hallo.\r\n";

    $roh = RohFassung::ausTeilen($kopf, $rumpf, strlen($kopf.$rumpf));

    expect($roh->vollstaendig)->toBeTrue()
        ->and($roh->bytes)->toBe($kopf.$rumpf);
});

it('erkennt eine Kopie, der etwas fehlt', function () {
    // Der Fall, den niemand bemerkt, wenn er nicht geprueft wird: Die Kopie
    // sieht vollstaendig aus, die DKIM-Signatur ist trotzdem wertlos.
    $roh = RohFassung::ausTeilen("From: a@example.test\r\n\r\n", 'Hallo.', 9999);

    expect($roh->vollstaendig)->toBeFalse();
});

it('behauptet ohne Größenangabe nichts', function () {
    // „Unbestaetigt" ist die ehrliche Antwort — nicht „vollstaendig".
    $roh = RohFassung::ausTeilen("From: a@example.test\r\n\r\n", 'Hallo.', null);

    expect($roh->vollstaendig)->toBeFalse()
        ->and($roh->gemeldeteGroesse)->toBeNull();
});

it('hält eine am Stück geholte Nachricht für vollständig', function () {
    // Bei JMAP wurde nichts zusammengesetzt, also kann nichts danebengehen.
    $roh = RohFassung::amStueck('ganze Nachricht');

    expect($roh->vollstaendig)->toBeTrue()
        ->and($roh->gemeldeteGroesse)->toBe(15);
});

it('bildet den Abdruck über die Bytes', function () {
    $roh = RohFassung::amStueck('ganze Nachricht');

    expect($roh->hash())->toBe(hash('sha256', 'ganze Nachricht'))
        ->and($roh->hash())->toHaveLength(64);
});

it('liefert die Form, die die Schnittstelle zusagt', function () {
    $roh = RohFassung::ausTeilen('a', 'b', 2);

    expect($roh->toArray())->toBe(['raw' => 'ab', 'size' => 2, 'complete' => true]);
});
