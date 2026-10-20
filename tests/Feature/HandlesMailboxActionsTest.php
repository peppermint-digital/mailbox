<?php

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use Peppermint\Mailbox\Contracts\Mailbox;
use Peppermint\Mailbox\Folders\Ordnerliste;
use Peppermint\Mailbox\Http\HandlesMailboxActions;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * Die Aktionen am Postfach, als geteilter Baustein (22.09.2026).
 *
 * Geprueft wird das, was still falsch sein kann: Zaehlt ein `false` als
 * Erfolg? Geht Archivieren wirklich in EINEM Aufruf? Kommen die Kennungen
 * unveraendert unten an — auch die von JMAP, die Zeichenketten sind?
 *
 * Nicht geprueft wird, ob IMAP etwas verschiebt. Das ist Sache der
 * Transport-Tests; hier geht es um die Strecke vom Browser zum Vertrag.
 */
class TestControllerFuerAktionen
{
    use HandlesMailboxActions;

    public function __construct(private readonly Mailbox $postfach) {}

    protected function mailboxFor(int $account): Mailbox
    {
        return $this->postfach;
    }

    protected function mailboxFailure(callable $work): JsonResponse
    {
        return response()->json($work());
    }

    /** `ordnerliste()` ist geschuetzt — ein Produkt ruft es, ein Test braucht einen Weg. */
    public function ordnerlisteFuerTest(int $account): array
    {
        return $this->ordnerliste($account);
    }
}

function anfrage(array $daten): Request
{
    $r = Request::create('/', 'POST', $daten);
    $r->headers->set('Accept', 'application/json');

    return $r;
}

it('reicht eine Zeichenketten-Kennung unveraendert durch', function () {
    // JMAP vergibt `cqiaaaaus`. Wird daraus unterwegs eine Zahl, trifft die
    // Aktion nichts — und meldet trotzdem Erfolg.
    $gesehen = null;
    $postfach = Mockery::mock(Mailbox::class);
    $postfach->shouldReceive('setSeen')->andReturnUsing(function ($ordner, $uid, $seen) use (&$gesehen) {
        $gesehen = $uid;

        return true;
    });

    (new TestControllerFuerAktionen($postfach))
        ->markSeen(anfrage(['folder' => 'INBOX', 'seen' => true]), 1, 'cqiaaaaus');

    expect($gesehen)->toBe('cqiaaaaus');
});

it('zaehlt ein abgelehntes false als Fehlschlag, nicht als Erfolg', function () {
    // Eine Kennung, die in DIESEM Ordner nicht liegt, wirft keine Ausnahme —
    // die Methode gibt `false` zurueck. Wer nur auf Ausnahmen achtet, meldet
    // Erfolg, waehrend nichts passiert ist.
    $postfach = Mockery::mock(Mailbox::class);
    $postfach->shouldReceive('delete')->andReturn(true, false, true);

    $antwort = (new TestControllerFuerAktionen($postfach))
        ->bulk(anfrage(['folder' => 'INBOX', 'action' => 'delete', 'uids' => [1, 2, 3]]), 1);

    $daten = $antwort->getData(true);

    expect($daten['processed'])->toBe(2)
        ->and($daten['failed'])->toBe(1);
});

it('meldet einen Teilerfolg als gelaufene Anfrage, nicht als Fehlschlag', function () {
    /*
     * Dieser Test stand bis zum 20.10.2026 umgekehrt da — er behauptete
     * `success === false` beim Teilerfolg. Gruen, und trotzdem falsch:
     *
     * `postCounted()` im eigenen Paket macht aus `success: false` ein `null`,
     * `runBulkAcrossFolders()` macht daraus `unreachable`, und die
     * Oberflaeche sagt „Sammelaktion fehlgeschlagen". Die Zweige fuer „3 von
     * 5 bearbeitet" und „keine einzige, laden Sie die Liste neu" waren damit
     * unerreichbar — also genau die Meldungen, die der Fund vom 13.09.2026
     * eingefuehrt hat.
     *
     * `success` beantwortet deshalb nur: Ist die Anfrage gelaufen? Was sie
     * erwischt hat, steht in `processed` und `failed`.
     */
    $postfach = Mockery::mock(Mailbox::class);
    $postfach->shouldReceive('delete')->andReturn(true, false, true);

    $daten = (new TestControllerFuerAktionen($postfach))
        ->bulk(anfrage(['folder' => 'INBOX', 'action' => 'delete', 'uids' => [1, 2, 3]]), 1)
        ->getData(true);

    expect($daten['success'])->toBeTrue();
});

it('bleibt auch dann auskunftsfaehig, wenn KEINE einzige Nachricht erwischt wurde', function () {
    /*
     * Der haertere Fall: `processed: 0`. Mit `success: false` waere er von
     * „Postfach nicht erreichbar" nicht zu unterscheiden gewesen — und das
     * ist ein Unterschied, der zaehlt. Nicht erreichbar heisst abwarten;
     * „liegt nicht mehr im Ordner" heisst Liste neu laden.
     *
     * Dieselbe Regel wie bei der Zustaendigkeit: eine leere Antwort ist kein
     * leerer Bestand.
     */
    $postfach = Mockery::mock(Mailbox::class);
    $postfach->shouldReceive('delete')->andReturn(false, false);

    $daten = (new TestControllerFuerAktionen($postfach))
        ->bulk(anfrage(['folder' => 'INBOX', 'action' => 'delete', 'uids' => [1, 2]]), 1)
        ->getData(true);

    expect($daten)->toMatchArray(['success' => true, 'processed' => 0, 'failed' => 2]);
});

it('archiviert in EINEM Aufruf statt einmal je Nachricht', function () {
    // Je Nachricht den Archiv-Ordner zu suchen heisst, sich je Nachricht neu
    // anzumelden. O365 drosselt pro Postfach ueber alle Verbindungen.
    $aufrufe = 0;
    $postfach = Mockery::mock(Mailbox::class);
    $postfach->shouldReceive('archive')->andReturnUsing(function ($ordner, $uids) use (&$aufrufe) {
        $aufrufe++;

        return ['archived' => count($uids), 'failed' => 0];
    });
    $postfach->shouldReceive('move')->never();
    $postfach->shouldReceive('specialFolder')->andReturn('Archiv');

    $antwort = (new TestControllerFuerAktionen($postfach))
        ->bulk(anfrage(['folder' => 'INBOX', 'action' => 'archive', 'uids' => [1, 2, 3, 4, 5]]), 1);

    expect($aufrufe)->toBe(1)
        ->and($antwort->getData(true)['processed'])->toBe(5);
});

it('weist eine unbekannte Aktion ab, statt still nichts zu tun', function () {
    // Ohne den Riegel faellt ein Tippfehler in die `default`-Zweige: Die
    // Antwort meldete dann „0 erledigt, 3 fehlgeschlagen" — eine Stoerung,
    // wo eine ungueltige Anfrage vorliegt.
    $postfach = Mockery::mock(Mailbox::class);

    expect(fn () => (new TestControllerFuerAktionen($postfach))
        ->bulk(anfrage(['folder' => 'INBOX', 'action' => 'verbrennen', 'uids' => [1]]), 1))
        ->toThrow(ValidationException::class);
});

it('verlangt ein Ziel, bevor es verschiebt', function () {
    $postfach = Mockery::mock(Mailbox::class);
    $postfach->shouldReceive('move')->never();

    expect(fn () => (new TestControllerFuerAktionen($postfach))
        ->bulk(anfrage(['folder' => 'INBOX', 'action' => 'move', 'uids' => [1]]), 1))
        ->toThrow(ValidationException::class);
});

it('nimmt keine leere Auswahl an', function () {
    // Eine Sammelaktion ohne Kennungen ist immer ein Fehler des Aufrufers.
    // Sie durchzulassen hiesse, einen ganzen Ordner zu riskieren, falls
    // spaeter jemand „keine Auswahl = alle" ergaenzt.
    $postfach = Mockery::mock(Mailbox::class);

    expect(fn () => (new TestControllerFuerAktionen($postfach))
        ->bulk(anfrage(['folder' => 'INBOX', 'action' => 'delete', 'uids' => []]), 1))
        ->toThrow(ValidationException::class);
});

it('zaehlt einen glatten Durchlauf vollstaendig', function () {
    // Hiess bis zum 20.10.2026 „meldet Erfolg nur, wenn wirklich nichts
    // danebenging" — ein Titel, der nach der Korrektur an `success` eine
    // Zusage machte, die diese Methode nicht mehr gibt und auch nicht geben
    // soll. Geprueft wurde ohnehin nur der glatte Fall.
    $postfach = Mockery::mock(Mailbox::class);
    $postfach->shouldReceive('setSeen')->andReturn(true, true);

    $antwort = (new TestControllerFuerAktionen($postfach))
        ->bulk(anfrage(['folder' => 'INBOX', 'action' => 'read', 'uids' => [1, 2]]), 1);

    expect($antwort->getData(true))->toMatchArray(['success' => true, 'processed' => 2, 'failed' => 0]);
});

/**
 * Ordnerverwaltung — und der Schutz, den es bis heute nur im Browser gab.
 */
it('legt einen Ordner an', function () {
    $gesehen = null;
    $postfach = Mockery::mock(Mailbox::class);
    $postfach->shouldReceive('createFolder')->andReturnUsing(function ($pfad) use (&$gesehen) {
        $gesehen = $pfad;
    });

    (new TestControllerFuerAktionen($postfach))->createFolder(anfrage(['path' => 'Kunden/2026']), 1);

    expect($gesehen)->toBe('Kunden/2026');
});

it('laesst den Posteingang nicht loeschen', function () {
    // Die Pruefung stand bis zum 22.09.2026 NUR im Browser: Sie blendete das
    // Kontextmenue aus, und das war alles. Ein Aufruf des Endpunkts von Hand
    // haette den Posteingang geloescht.
    $postfach = Mockery::mock(Mailbox::class);
    $postfach->shouldReceive('folders')->andReturn([]);
    $postfach->shouldReceive('deleteFolder')->never();

    expect(fn () => (new TestControllerFuerAktionen($postfach))->deleteFolder(anfrage(['path' => 'INBOX']), 1))
        ->toThrow(HttpException::class);
});

it('laesst auch die Standardordner in Ruhe', function () {
    // Wer `Sent` loescht, verliert nicht nur einen Ordner, sondern den Ort, an
    // dem kuenftig Gesendetes landet — und das faellt erst Wochen spaeter auf.
    $postfach = Mockery::mock(Mailbox::class);
    $postfach->shouldReceive('folders')->andReturn([]);
    $postfach->shouldReceive('deleteFolder')->never();
    $postfach->shouldReceive('renameFolder')->never();

    foreach (['Gesendete Elemente', 'Papierkorb', 'Entwürfe', 'Archiv'] as $ordner) {
        expect(fn () => (new TestControllerFuerAktionen($postfach))->deleteFolder(anfrage(['path' => $ordner]), 1))
            ->toThrow(HttpException::class, '', "loeschbar: {$ordner}");
    }
});

it('glaubt den Marken des Servers mehr als dem Namen', function () {
    // Ein Ordner kann „Zeug" heissen und trotzdem der Papierkorb sein. Der
    // Server sagt es ueber seine Marken — in jeder Sprache.
    $postfach = Mockery::mock(Mailbox::class);
    $postfach->shouldReceive('folders')->andReturn([
        ['path' => 'Zeug', 'flags' => ['\\Trash']],
    ]);
    $postfach->shouldReceive('deleteFolder')->never();

    expect(fn () => (new TestControllerFuerAktionen($postfach))->deleteFolder(anfrage(['path' => 'Zeug']), 1))
        ->toThrow(HttpException::class);
});

it('laesst einen gewoehnlichen Ordner loeschen', function () {
    // Der Schutz darf nicht so weit gehen, dass gar nichts mehr geht.
    $geloescht = null;
    $postfach = Mockery::mock(Mailbox::class);
    $postfach->shouldReceive('folders')->andReturn([['path' => 'Kunden/2026', 'flags' => []]]);
    $postfach->shouldReceive('deleteFolder')->andReturnUsing(function ($pfad) use (&$geloescht) {
        $geloescht = $pfad;
    });

    (new TestControllerFuerAktionen($postfach))->deleteFolder(anfrage(['path' => 'Kunden/2026']), 1);

    expect($geloescht)->toBe('Kunden/2026');
});

it('schuetzt auch beim Umbenennen, nicht nur beim Loeschen', function () {
    // Ein umbenannter Posteingang ist so kaputt wie ein geloeschter.
    $postfach = Mockery::mock(Mailbox::class);
    $postfach->shouldReceive('folders')->andReturn([]);
    $postfach->shouldReceive('renameFolder')->never();

    expect(fn () => (new TestControllerFuerAktionen($postfach))->renameFolder(anfrage(['path' => 'INBOX', 'name' => 'Posteingang']), 1))
        ->toThrow(HttpException::class);
});

/**
 * Der Haken fuer die Zwischenspeicher (19.10.2026).
 *
 * Aufgefallen beim Versuch, den Projekt-Manager auf dieses Merkmal zu ziehen:
 * Seine eigenen Wege sind KEINE Dopplung. Sie rufen dasselbe Paket, raeumen
 * danach aber ihre Zwischenspeicher auf.
 *
 * Ohne diesen Haken waere die Uebernahme ein Rueckschritt mit Ansage: Wer eine
 * Mail als gelesen markiert, saehe sie weiter als ungelesen — und das sieht
 * nicht wie ein Fehler aus, sondern wie „hat nicht geklickt".
 */
class TestControllerMitZwischenspeicher
{
    use HandlesMailboxActions;

    /** @var list<array{ordner: list<string>, nachrichten: list<int|string>}> */
    public array $gemeldet = [];

    public function __construct(private readonly Mailbox $postfach) {}

    protected function mailboxFor(int $account): Mailbox
    {
        return $this->postfach;
    }

    protected function mailboxFailure(callable $work): JsonResponse
    {
        return response()->json($work());
    }

    protected function afterMailboxChange(int $account, array $ordner, array $nachrichten = []): void
    {
        $this->gemeldet[] = ['ordner' => $ordner, 'nachrichten' => $nachrichten];
    }
}

it('meldet nach dem Markieren, welcher Ordner sich geaendert hat', function () {
    $postfach = Mockery::mock(Mailbox::class);
    $postfach->shouldReceive('setSeen')->andReturn(true);

    $controller = new TestControllerMitZwischenspeicher($postfach);
    $controller->markSeen(anfrage(['folder' => 'INBOX', 'seen' => true]), 1, 7);

    expect($controller->gemeldet)->toBe([['ordner' => ['INBOX'], 'nachrichten' => [7]]]);
});

it('meldet beim Verschieben BEIDE Ordner', function () {
    // Der eine hat eine Nachricht weniger, der andere eine mehr. Nur den
    // Quellordner zu melden liesse die Mail im Ziel fehlen, bis der
    // Zwischenspeicher ablaeuft.
    $postfach = Mockery::mock(Mailbox::class);
    $postfach->shouldReceive('move')->andReturn(true);

    $controller = new TestControllerMitZwischenspeicher($postfach);
    $controller->moveMessage(anfrage(['folder' => 'INBOX', 'target_folder' => 'Archiv']), 1, 7);

    expect($controller->gemeldet[0]['ordner'])->toBe(['INBOX', 'Archiv']);
});

it('meldet nach einem Fehlschlag NICHT', function () {
    // Nichts hat sich geaendert. Trotzdem zu leeren waere harmlos, aber es
    // waere eine Luege im Protokoll: „hier ist etwas passiert".
    $postfach = Mockery::mock(Mailbox::class);
    $postfach->shouldReceive('setSeen')->andReturn(false);

    $controller = new TestControllerMitZwischenspeicher($postfach);
    $controller->markSeen(anfrage(['folder' => 'INBOX', 'seen' => true]), 1, 7);

    expect($controller->gemeldet)->toBe([]);
});

it('meldet bei der Mehrfachauswahl EINMAL, nicht je Nachricht', function () {
    // Fuenfzig Zeilen wuerden sonst fuenfzig Mal dieselben Zwischenspeicher
    // leeren.
    $postfach = Mockery::mock(Mailbox::class);
    $postfach->shouldReceive('setSeen')->andReturn(true);

    $controller = new TestControllerMitZwischenspeicher($postfach);
    $controller->bulk(anfrage([
        'folder' => 'INBOX',
        'action' => 'read',
        'uids' => [7, 8, 9],
    ]), 1);

    expect($controller->gemeldet)->toHaveCount(1)
        ->and($controller->gemeldet[0]['nachrichten'])->toBe([7, 8, 9]);
});

it('haelt jede nicht gefundene Nachricht einer Massenaktion fest', function () {
    /*
     * Die Zahl allein reicht nicht: „3 von 5 fehlgeschlagen" sagt nicht,
     * WELCHE. Im Projekt-Manager stand das Protokollieren seit dem 13.09.2026
     * als Einzelloesung; seit dem 19.10.2026 tut es das Paket fuer alle.
     */
    Log::shouldReceive('warning')
        ->twice()
        ->withArgs(fn (string $satz, array $zusatz): bool => str_contains($satz, 'nicht gefunden')
            && $zusatz['folder'] === 'INBOX'
            && in_array($zusatz['uid'], [2, 3], true));

    $postfach = Mockery::mock(Mailbox::class);
    $postfach->shouldReceive('delete')->andReturn(true, false, false);

    (new TestControllerFuerAktionen($postfach))
        ->bulk(anfrage(['folder' => 'INBOX', 'action' => 'delete', 'uids' => [1, 2, 3]]), 1);
});

it('meldet nach dem Archivieren AUCH den Zielordner, nicht nur die Quelle', function () {
    /*
     * Stand bis zum 20.10.2026 nicht da — gemeldet wurde nur der Quellordner.
     * Das Archiv zeigte danach weiter seinen alten Stand, bis der
     * Zwischenspeicher von selbst ablief.
     *
     * Gefunden beim Gegenlesen mit dem Projekt-Manager, der es in seinem
     * eigenen Weg richtig machte: Die Uebernahme des Paket-Weges waere dort
     * ein Rueckschritt gewesen.
     */
    $postfach = Mockery::mock(Mailbox::class);
    $postfach->shouldReceive('archive')->andReturn(['archived' => 2, 'failed' => 0]);
    $postfach->shouldReceive('specialFolder')->with('Archive')->andReturn('Archiv');

    $controller = new TestControllerMitZwischenspeicher($postfach);
    $controller->bulk(anfrage(['folder' => 'INBOX', 'action' => 'archive', 'uids' => [1, 2]]), 1);

    expect($controller->gemeldet)->toBe([['ordner' => ['INBOX', 'Archiv'], 'nachrichten' => [1, 2]]]);
});

it('fragt den Archiv-Ordner nicht ab, wenn nichts archiviert wurde', function () {
    // Eine Ordnerliste kostet eine Anmeldung. Wurde nichts verschoben, ist im
    // Archiv auch nichts zu vergessen.
    $postfach = Mockery::mock(Mailbox::class);
    $postfach->shouldReceive('archive')->andReturn(['archived' => 0, 'failed' => 2]);
    $postfach->shouldReceive('specialFolder')->never();

    $controller = new TestControllerMitZwischenspeicher($postfach);
    $controller->bulk(anfrage(['folder' => 'INBOX', 'action' => 'archive', 'uids' => [1, 2]]), 1);

    expect($controller->gemeldet)->toBe([['ordner' => ['INBOX'], 'nachrichten' => [1, 2]]]);
});

it('laesst einen Archiv-Ordner weg, den das Postfach nicht nennen kann', function () {
    // `specialFolder()` gibt `null` zurueck, wenn es kein Archiv findet. Das
    // als Ordnernamen weiterzureichen hiesse, einen Zwischenspeicher-Schluessel
    // aus `null` zu bauen.
    $postfach = Mockery::mock(Mailbox::class);
    $postfach->shouldReceive('archive')->andReturn(['archived' => 1, 'failed' => 0]);
    $postfach->shouldReceive('specialFolder')->andReturn(null);

    $controller = new TestControllerMitZwischenspeicher($postfach);
    $controller->bulk(anfrage(['folder' => 'INBOX', 'action' => 'archive', 'uids' => [1]]), 1);

    expect($controller->gemeldet)->toBe([['ordner' => ['INBOX'], 'nachrichten' => [1]]]);
});

it('weist eine Auswahl ab, die groesser ist als der Riegel', function () {
    /*
     * Jede Kennung ist ein eigener Aufruf am Mailserver. O365 drosselt pro
     * Postfach ueber alle Verbindungen (#4159) — eine Auswahl ohne Obergrenze
     * ist deshalb kein theoretisches Risiko.
     *
     * Aus dem Projekt-Manager gehoben, der `max:200` hatte. Seine Regel
     * `uids.* => integer` kam bewusst NICHT mit: Eine JMAP-Kennung ist eine
     * Zeichenkette, und die waere dort abgewiesen worden.
     */
    $postfach = Mockery::mock(Mailbox::class);
    $postfach->shouldReceive('setSeen')->never();

    expect(fn () => (new TestControllerFuerAktionen($postfach))
        ->bulk(anfrage(['folder' => 'INBOX', 'action' => 'read', 'uids' => range(1, 201)]), 1))
        ->toThrow(ValidationException::class);
});

it('nimmt 200 noch an — der Riegel sitzt nicht darunter', function () {
    // Die Gegenprobe zur Grenze: Ohne sie waere nicht zu sehen, ob der Riegel
    // bei 200 oder schon bei 20 steht.
    $postfach = Mockery::mock(Mailbox::class);
    $postfach->shouldReceive('setSeen')->andReturn(true);

    $daten = (new TestControllerFuerAktionen($postfach))
        ->bulk(anfrage(['folder' => 'INBOX', 'action' => 'read', 'uids' => range(1, 200)]), 1)
        ->getData(true);

    expect($daten['processed'])->toBe(200);
});

it('laesst eine Zeichenketten-Kennung durch den Riegel — JMAP zaehlt nicht in Zahlen', function () {
    // Der Manager verlangte `uids.* => integer`. Haette das Paket es
    // uebernommen, waere jede Sammelaktion auf einem JMAP-Postfach mit 422
    // abgewiesen worden.
    $postfach = Mockery::mock(Mailbox::class);
    $postfach->shouldReceive('setSeen')->andReturn(true);

    $daten = (new TestControllerFuerAktionen($postfach))
        ->bulk(anfrage(['folder' => 'INBOX', 'action' => 'read', 'uids' => ['cqiaaaaus', 'cqiaaaaut']]), 1)
        ->getData(true);

    expect($daten['processed'])->toBe(2);
});

it('leert den Ordner-Zwischenspeicher, sobald sich Ordner aendern', function () {
    /*
     * Die Gegenprobe zum Zwischenspeicher aus #6119. Er spart 485 ms je
     * Ordnerabfrage — und richtet Schaden an, wenn er nach einer Aenderung
     * stehenbleibt: Der neue Ordner taucht nicht auf, also legt jemand ihn
     * noch einmal an.
     *
     * Deshalb liegt das Leeren im PAKET, an den Wegen, die Ordner aendern, und
     * nicht beim Produkt. Ein Produkt kann einen vergessen; ein Paket, das
     * drei Wege hat, vergisst sie an einer Stelle gemeinsam.
     */
    $vorher = [['name' => 'INBOX', 'path' => 'INBOX', 'flags' => []]];

    foreach (['createFolder', 'renameFolder', 'deleteFolder'] as $weg) {
        Cache::put(Ordnerliste::PRAEFIX.'1', $vorher, 600);

        $postfach = Mockery::mock(Mailbox::class);
        $postfach->shouldReceive('folders')->andReturn($vorher);
        $postfach->shouldReceive('createFolder')->andReturnNull();
        $postfach->shouldReceive('renameFolder')->andReturn('Neu');
        $postfach->shouldReceive('deleteFolder')->andReturnNull();

        (new TestControllerFuerAktionen($postfach))->{$weg}(
            anfrage(['path' => 'Kunden/2026', 'name' => 'Neu']),
            1,
        );

        expect(Cache::has(Ordnerliste::PRAEFIX.'1'))->toBeFalse("{$weg} hat den Zwischenspeicher nicht geleert");
    }
});

it('holt die Ordnerliste beim zweiten Mal nicht erneut', function () {
    // Der eigentliche Gewinn: Gemessen am 23.09.2026 kostete jede Abfrage
    // 485 ms, und der zweite Aufruf war so teuer wie der erste.
    Cache::flush();

    $postfach = Mockery::mock(Mailbox::class);
    $postfach->shouldReceive('folders')->once()->andReturn([
        ['name' => 'INBOX', 'path' => 'INBOX', 'flags' => []],
    ]);

    $controller = new TestControllerFuerAktionen($postfach);

    $erste = $controller->ordnerlisteFuerTest(1);
    $zweite = $controller->ordnerlisteFuerTest(1);

    // `once()` oben ist die Behauptung: Ein zweiter Aufruf am Postfach waere
    // ein Fehlschlag der Attrappe.
    expect($zweite)->toBe($erste);
});
