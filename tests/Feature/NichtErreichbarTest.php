<?php

use Peppermint\Mailbox\Exceptions\MissingAccessToken;
use Peppermint\Mailbox\Exceptions\NichtErreichbar;
use Peppermint\Mailbox\Imap\RetryPolicy;

/**
 * „Nicht erreichbar" von „geht so nicht" trennen (20.10.2026).
 *
 * Der Unterschied ist nicht Genauigkeit, sondern die naechste Handlung des
 * Menschen davor: Bei 502 wartet er ab, bei 422 aendert er die Eingabe. Wer
 * beides zusammenwirft, laesst ihn warten, bis er von selbst darauf kommt —
 * oder etwas aendern, wo nichts zu aendern ist.
 *
 * Dieselbe Regel wie bei der Zustaendigkeit und bei der Massenaktion: Eine
 * fehlende Antwort ist kein Inhalt.
 */
test('eine abgebrochene Verbindung heisst nicht erreichbar', function () {
    foreach ([
        'Connection closed by server',
        'Server closed the connection (EOF)',
        'stream_socket_client(): Connection refused',
        'Operation timed out',
        'Broken pipe',
        'connection reset by peer',
    ] as $nachricht) {
        expect(NichtErreichbar::trifftZu(new RuntimeException($nachricht)))
            ->toBeTrue("Nicht erreichbar erwartet fuer: {$nachricht}");
    }
});

test('ein fehlendes Token heisst nicht erreichbar', function () {
    // Ohne Token kommt keine Verbindung zustande. Das ist kein Eingabefehler
    // des Menschen davor — er kann nichts daran aendern.
    expect(NichtErreichbar::trifftZu(new MissingAccessToken('kein Token')))->toBeTrue();
});

test('eine ANTWORT des Servers heisst NICHT nicht erreichbar', function () {
    /*
     * Die Gegenprobe, und sie ist die wichtigere Haelfte: Ohne sie waere die
     * Versuchung gross, die Liste zu verbreitern, bis alles 502 wird — und
     * dann wartet jemand auf ein Postfach, das laengst antwortet und nur
     * „diesen Ordner gibt es schon" gesagt hat.
     */
    foreach ([
        'Ordner existiert bereits.',
        'Folder not found',
        'Die Nachricht liegt nicht in diesem Ordner.',
        'Dieser Ordner gehört zur Grundausstattung des Postfachs.',
        'Zu dieser Nachricht fehlen die Angaben.',
    ] as $nachricht) {
        expect(NichtErreichbar::trifftZu(new RuntimeException($nachricht)))
            ->toBeFalse("Das ist eine Antwort, kein Ausfall: {$nachricht}");
    }
});

test('die Ursache zaehlt, nicht die Verpackung', function () {
    /*
     * Der HTTP-Klient verpackt einen abgebrochenen Strom in eine eigene
     * Ausnahme, deren Nachricht nichts verraet. Ohne den Blick auf
     * `getPrevious()` entscheidet die Verpackung — und JMAP-Ausfaelle kaemen
     * als 422 an, also als Eingabefehler.
     */
    $innen = new RuntimeException('Connection reset by peer');
    $aussen = new RuntimeException('Request failed', 0, $innen);

    expect(NichtErreichbar::trifftZu($aussen))->toBeTrue();
});

test('eine harmlose Verpackung um eine harmlose Ursache bleibt harmlos', function () {
    // Gegenprobe zur Ursachenkette: Sie darf nicht alles einfangen, was
    // irgendwo verschachtelt ist.
    $innen = new RuntimeException('Ordner existiert bereits.');
    $aussen = new RuntimeException('Request failed', 0, $innen);

    expect(NichtErreichbar::trifftZu($aussen))->toBeFalse();
});

test('die Antwort haengt nicht am Transport', function () {
    /*
     * Der eigentliche Anlass. Im Projekt-Manager entschied ein `catch` auf
     * `ImapConnectionFailedException` — an einem JMAP-Postfach fliegt die nie,
     * und ein echter Abbruch kam dort als 422 an.
     *
     * Dieselbe Lage, zwei Transporte, dieselbe Antwort: Das ist die Zusage
     * dieser Klasse.
     */
    $imapStil = new RuntimeException('Server closed the connection (EOF)');
    $jmapStil = new RuntimeException('cURL error 56: Connection reset by peer');

    expect(NichtErreichbar::trifftZu($imapStil))->toBe(NichtErreichbar::trifftZu($jmapStil))
        ->and(NichtErreichbar::trifftZu($jmapStil))->toBeTrue();
});

test('es benutzt dieselbe Grundlage wie die Wiederholung', function () {
    /*
     * Keine zweite Liste. „Wiederholen lohnt" und „nicht erreichbar" sind
     * dieselbe Frage von zwei Seiten; zwei Listen liefen auseinander, und dann
     * wartet der Mensch bei einem Fehler, den das System still wiederholt
     * haette.
     *
     * Dieser Test faellt, sobald jemand eine eigene Liste daneben baut.
     */
    foreach (RetryPolicy::TRANSIENT as $begriff) {
        expect(NichtErreichbar::trifftZu(new RuntimeException("irgendwas {$begriff} irgendwas")))
            ->toBeTrue("Gilt fuer die Wiederholung, aber nicht hier: {$begriff}");
    }
});
