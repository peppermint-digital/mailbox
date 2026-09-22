<?php

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Peppermint\Mailbox\Contracts\Mailbox;
use Peppermint\Mailbox\Http\HandlesMailboxActions;

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
        ->and($daten['failed'])->toBe(1)
        ->and($daten['success'])->toBeFalse();
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
        ->toThrow(Illuminate\Validation\ValidationException::class);
});

it('verlangt ein Ziel, bevor es verschiebt', function () {
    $postfach = Mockery::mock(Mailbox::class);
    $postfach->shouldReceive('move')->never();

    expect(fn () => (new TestControllerFuerAktionen($postfach))
        ->bulk(anfrage(['folder' => 'INBOX', 'action' => 'move', 'uids' => [1]]), 1))
        ->toThrow(Illuminate\Validation\ValidationException::class);
});

it('nimmt keine leere Auswahl an', function () {
    // Eine Sammelaktion ohne Kennungen ist immer ein Fehler des Aufrufers.
    // Sie durchzulassen hiesse, einen ganzen Ordner zu riskieren, falls
    // spaeter jemand „keine Auswahl = alle" ergaenzt.
    $postfach = Mockery::mock(Mailbox::class);

    expect(fn () => (new TestControllerFuerAktionen($postfach))
        ->bulk(anfrage(['folder' => 'INBOX', 'action' => 'delete', 'uids' => []]), 1))
        ->toThrow(Illuminate\Validation\ValidationException::class);
});

it('meldet Erfolg nur, wenn wirklich nichts danebenging', function () {
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
        ->toThrow(Symfony\Component\HttpKernel\Exception\HttpException::class);
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
            ->toThrow(Symfony\Component\HttpKernel\Exception\HttpException::class, '', "loeschbar: {$ordner}");
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
        ->toThrow(Symfony\Component\HttpKernel\Exception\HttpException::class);
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
        ->toThrow(Symfony\Component\HttpKernel\Exception\HttpException::class);
});
