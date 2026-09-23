<?php

use Illuminate\Foundation\Testing\RefreshDatabase;
use Peppermint\Mailbox\Archive\Ablage;
use Peppermint\Mailbox\Archive\Erfassung;
use Peppermint\Mailbox\Contracts\Mailbox;
use Peppermint\Mailbox\Database\ArchiveTables;
use Peppermint\Mailbox\Models\MailFolderState;
use Peppermint\Mailbox\Models\MailLocation;
use Peppermint\Mailbox\Models\MailMessage;

uses(RefreshDatabase::class);

beforeEach(function () {
    ArchiveTables::create();
});

/**
 * Eine Ablage, die sich merkt, was sie bekommen hat.
 */
function testAblage(): Ablage
{
    return new class implements Ablage
    {
        /** @var array<string, string> */
        public array $dateien = [];

        public function ablegen(string $pfad, string $bytes): void
        {
            // Wie die echte: nicht ueberschreiben.
            $this->dateien[$pfad] ??= $bytes;
        }

        public function vorhanden(string $pfad): bool
        {
            return isset($this->dateien[$pfad]);
        }

        public function lesen(string $pfad): ?string
        {
            return $this->dateien[$pfad] ?? null;
        }
    };
}

/**
 * Ein Postfach mit vorgegebenem Inhalt.
 *
 * @param  array<int|string, array{message_id: string, raw?: string, complete?: bool, kopf?: array<string, mixed>}>  $nachrichten
 */
function testPostfach(array $nachrichten, ?int $uidvalidity = 7): Mailbox
{
    $postfach = Mockery::mock(Mailbox::class);

    $postfach->shouldReceive('folderState')->andReturn([
        'uidvalidity' => $uidvalidity,
        'uidnext' => 999,
        'messages' => count($nachrichten),
    ]);

    $postfach->shouldReceive('handles')->andReturn(array_keys($nachrichten));

    // Die juengste Kennung und der Zuwachs dahinter — beides braucht der
    // Stichtag.
    $postfach->shouldReceive('newest')->andReturnUsing(function ($ordner, $limit = 200) use ($nachrichten) {
        $uids = array_keys($nachrichten);
        rsort($uids);

        return array_map(static fn ($u): array => ['uid' => $u], array_slice($uids, 0, $limit));
    });

    $postfach->shouldReceive('newerThan')->andReturnUsing(function ($ordner, $handle, $limit = 200) use ($nachrichten) {
        $neuer = array_filter(array_keys($nachrichten), static fn ($u): bool => (string) $u > (string) $handle || (int) $u > (int) $handle);

        return array_map(static fn ($u): array => ['uid' => $u], array_values($neuer));
    });


    $postfach->shouldReceive('raw')->andReturnUsing(function ($ordner, $uid) use ($nachrichten) {
        $n = $nachrichten[$uid] ?? null;

        if ($n === null) {
            return null;
        }

        $roh = $n['raw'] ?? "Message-ID: {$n['message_id']}\r\n\r\nHallo.";

        return ['raw' => $roh, 'size' => strlen($roh), 'complete' => $n['complete'] ?? true];
    });

    $postfach->shouldReceive('message')->andReturnUsing(function ($ordner, $uid) use ($nachrichten) {
        $n = $nachrichten[$uid] ?? null;

        return $n === null ? null : ($n['kopf'] ?? []) + [
            'message_id' => $n['message_id'],
            'subject' => 'Betreff',
            'from_address' => 'kunde@example.test',
            'body_text' => "Die Freigabe ist da.\n\n-- \nChris",
        ];
    });

    return $postfach;
}

/**
 * Setzt den Stichtag so, wie er in Wirklichkeit entsteht: Der Ordner wird
 * einmal angesehen, und ab da zaehlt, was dazukommt.
 *
 * Ohne diesen Schritt beschreiben die Tests einen Lauf, den es nicht gibt —
 * der erste Blick in einen Ordner nimmt nie etwas auf.
 */
function stichtagSetzen(Ablage $ablage, array $vorhanden = [], int $accountId = 1, string $ordner = 'INBOX'): void
{
    (new Erfassung(testPostfach($vorhanden), $accountId, $ablage))->ordner($ordner);
}

it('nimmt beim ersten Blick nichts auf, sondern merkt sich den Stand', function () {
    // Die Entscheidung „ab jetzt": Was schon im Postfach liegt, bleibt dort.
    $ablage = testAblage();

    $ergebnis = (new Erfassung(
        testPostfach([
            5 => ['message_id' => '<alt1@example.test>'],
            6 => ['message_id' => '<alt2@example.test>'],
        ]),
        1,
        $ablage,
    ))->ordner('INBOX');

    expect($ergebnis->stichtag)->toBeTrue()
        ->and($ergebnis->aufgenommen)->toBe(0)
        ->and(MailMessage::count())->toBe(0)
        ->and(MailFolderState::first()->since_handle)->toBe('6');
});

it('nimmt ab dem zweiten Blick auf, was dazugekommen ist', function () {
    $ablage = testAblage();

    stichtagSetzen($ablage, [
        5 => ['message_id' => '<alt@example.test>'],
    ]);

    $ergebnis = (new Erfassung(
        testPostfach([
            5 => ['message_id' => '<alt@example.test>'],
            9 => ['message_id' => '<neu@example.test>'],
        ]),
        1,
        $ablage,
    ))->ordner('INBOX');

    // Nur die neue. Die alte bleibt draussen, bis jemand sie oeffnet.
    expect($ergebnis->aufgenommen)->toBe(1)
        ->and(MailMessage::count())->toBe(1)
        ->and(MailMessage::first()->message_id)->toBe('<neu@example.test>');
});

it('nimmt alles auf, wenn der Ordner beim Stichtag leer war', function () {
    // `newerThan` hat dann nichts, woran es sich orientieren koennte — und
    // alles, was jetzt drin liegt, ist nach dem Stichtag gekommen.
    $ablage = testAblage();

    stichtagSetzen($ablage, []);

    $ergebnis = (new Erfassung(
        testPostfach([1 => ['message_id' => '<erste@example.test>']]),
        1,
        $ablage,
    ))->ordner('INBOX');

    expect($ergebnis->aufgenommen)->toBe(1);
});

it('nimmt neue Nachrichten auf', function () {
    $ablage = testAblage();

    stichtagSetzen($ablage);

    $ergebnis = (new Erfassung(
        testPostfach([
            5 => ['message_id' => '<a@example.test>'],
            6 => ['message_id' => '<b@example.test>'],
        ]),
        accountId: 1,
        ablage: $ablage,
    ))->ordner('INBOX');

    expect($ergebnis->aufgenommen)->toBe(2)
        ->and($ergebnis->sauber())->toBeTrue()
        ->and(MailMessage::count())->toBe(2)
        ->and(MailLocation::count())->toBe(2)
        ->and($ablage->dateien)->toHaveCount(2);
});

it('legt Rohfassung, Prüfsumme und Gesprächstext ab', function () {
    $ablage = testAblage();

    stichtagSetzen($ablage);

    (new Erfassung(
        testPostfach([5 => ['message_id' => '<a@example.test>', 'raw' => 'ROHE BYTES']]),
        accountId: 1,
        ablage: $ablage,
    ))->ordner('INBOX');

    $n = MailMessage::first();

    expect($n->raw_sha256)->toBe(hash('sha256', 'ROHE BYTES'))
        ->and($ablage->lesen($n->raw_path))->toBe('ROHE BYTES')
        // Der Verlauf zeigt das, was jemand geschrieben hat.
        ->and($n->body->content)->toBe('Die Freigabe ist da.')
        ->and($n->body->signature)->toContain('Chris');
});

it('nimmt dieselbe Nachricht beim zweiten Lauf nicht noch einmal auf', function () {
    $postfach = testPostfach([5 => ['message_id' => '<a@example.test>']]);
    $ablage = testAblage();

    stichtagSetzen($ablage);

    (new Erfassung($postfach, 1, $ablage))->ordner('INBOX');
    $zweiter = (new Erfassung($postfach, 1, $ablage))->ordner('INBOX');

    expect($zweiter->aufgenommen)->toBe(0)
        ->and(MailMessage::count())->toBe(1)
        ->and(MailLocation::count())->toBe(1);
});

it('zählt die Anhänge aus der Liste, nicht aus einem Feld', function () {
    // Beim ersten Livelauf stand bei einer Auftragsbestaetigung mit einem
    // 105-KB-PDF „0 Anhaenge". Der Grund war ein Feldname, den `message()`
    // gar nicht liefert — und die Zahl sah plausibel genug aus, um
    // durchzugehen.
    stichtagSetzen(testAblage());

    (new Erfassung(
        testPostfach([5 => ['message_id' => '<beleg@example.test>', 'kopf' => [
            'attachments' => [
                ['filename' => 'Auftragsbestaetigung.pdf', 'size' => 105350],
            ],
        ]]]),
        1,
        testAblage(),
    ))->ordner('INBOX');

    $n = MailMessage::first();

    expect($n->attachment_count)->toBe(1)
        ->and($n->has_attachments)->toBeTrue();
});

it('meldet eine Kopie, die nicht vollständig ist', function () {
    // Der Fall, der sonst als wortgetreu durchginge — und bei dem die
    // DKIM-Signatur wertlos ist.
    stichtagSetzen(testAblage());

    $ergebnis = (new Erfassung(
        testPostfach([5 => ['message_id' => '<a@example.test>', 'complete' => false]]),
        1,
        testAblage(),
    ))->ordner('INBOX');

    expect($ergebnis->unvollstaendig)->toHaveCount(1)
        ->and($ergebnis->sauber())->toBeFalse();
});

it('schließt den Ort einer verschwundenen Nachricht, ohne sie zu löschen', function () {
    $ablage = testAblage();

    stichtagSetzen($ablage);

    (new Erfassung(testPostfach([5 => ['message_id' => '<weg@example.test>']]), 1, $ablage))->ordner('INBOX');

    // Jemand loescht sie im Postfach.
    $ergebnis = (new Erfassung(testPostfach([]), 1, $ablage))->ordner('INBOX');

    $n = MailMessage::first();

    expect($ergebnis->verschwunden)->toBe(1)
        ->and($ergebnis->ausDemPostfach)->toBe(1)
        ->and($n)->not->toBeNull()
        ->and($n->imPostfach())->toBeFalse()
        ->and($n->raw_path)->not->toBeNull()
        ->and($ablage->lesen($n->raw_path))->not->toBeNull();
});

it('hält eine verschobene Nachricht nicht für verschwunden', function () {
    $ablage = testAblage();

    stichtagSetzen($ablage);
    stichtagSetzen($ablage, [], 1, 'Archiv');

    (new Erfassung(testPostfach([5 => ['message_id' => '<umzug@example.test>']]), 1, $ablage))->ordner('INBOX');

    // Dieselbe Nachricht taucht im Archiv-Ordner auf — mit neuer Kennung.
    (new Erfassung(testPostfach([9 => ['message_id' => '<umzug@example.test>']]), 1, $ablage))->ordner('Archiv');

    // Und ist aus dem Posteingang weg.
    $ergebnis = (new Erfassung(testPostfach([]), 1, $ablage))->ordner('INBOX');

    $n = MailMessage::first();

    expect(MailMessage::count())->toBe(1)
        ->and($ergebnis->verschwunden)->toBe(1)
        // Der Ort im Posteingang ist zu, die Nachricht liegt trotzdem im
        // Postfach — nur woanders.
        ->and($ergebnis->ausDemPostfach)->toBe(0)
        ->and($n->imPostfach())->toBeTrue()
        ->and($n->aktuelleOrte()->first()->folder)->toBe('Archiv');
});

it('holt eine zurückgeholte Nachricht aus dem Verschwunden-Zustand', function () {
    $ablage = testAblage();

    stichtagSetzen($ablage);

    (new Erfassung(testPostfach([5 => ['message_id' => '<zurueck@example.test>']]), 1, $ablage))->ordner('INBOX');
    (new Erfassung(testPostfach([]), 1, $ablage))->ordner('INBOX');

    expect(MailMessage::first()->imPostfach())->toBeFalse();

    // Aus dem Papierkorb zurueckgeholt.
    (new Erfassung(testPostfach([12 => ['message_id' => '<zurueck@example.test>']]), 1, $ablage))->ordner('INBOX');

    expect(MailMessage::first()->imPostfach())->toBeTrue();
});

it('erkennt einen Bruch der Gültigkeitsnummer, statt alles für verschwunden zu halten', function () {
    $ablage = testAblage();

    stichtagSetzen($ablage);

    (new Erfassung(testPostfach([5 => ['message_id' => '<a@example.test>']], uidvalidity: 7), 1, $ablage))->ordner('INBOX');

    // Der Server hat die Nummer gewechselt: Dieselbe Nachricht, andere Kennung.
    $ergebnis = (new Erfassung(
        testPostfach([88 => ['message_id' => '<a@example.test>']], uidvalidity: 42),
        1,
        $ablage,
    ))->ordner('INBOX');

    $n = MailMessage::first();

    expect($ergebnis->umbruchVon)->toBe(7)
        ->and($ergebnis->umbruchNach)->toBe(42)
        // Nicht als „aus dem Postfach" gemeldet — sie liegt ja noch da.
        ->and($ergebnis->ausDemPostfach)->toBe(0)
        ->and($n->imPostfach())->toBeTrue()
        ->and(MailMessage::count())->toBe(1);
});

it('meldet eine Nachricht ohne Message-ID, statt sie bei jedem Lauf neu anzulegen', function () {
    stichtagSetzen(testAblage());

    $ergebnis = (new Erfassung(
        testPostfach([5 => ['message_id' => '']]),
        1,
        testAblage(),
    ))->ordner('INBOX');

    expect($ergebnis->fehler)->toHaveCount(1)
        ->and($ergebnis->fehler[0]['grund'])->toContain('Message-ID')
        ->and(MailMessage::count())->toBe(0);
});

it('sagt, wie viele beim nächsten Lauf noch drankommen', function () {
    $nachrichten = [];

    for ($i = 1; $i <= 5; $i++) {
        $nachrichten[$i] = ['message_id' => "<n{$i}@example.test>"];
    }

    $ablage = testAblage();
    stichtagSetzen($ablage);

    $ergebnis = (new Erfassung(testPostfach($nachrichten), 1, $ablage))->ordner('INBOX', hoechstens: 2);

    expect($ergebnis->aufgenommen)->toBe(2)
        ->and($ergebnis->offen)->toBe(3);
});

it('meldet einen fehlenden Ordner, statt ihn für leer zu halten', function () {
    $postfach = Mockery::mock(Mailbox::class);
    $postfach->shouldReceive('folderState')->andReturn(null);

    $ergebnis = (new Erfassung($postfach, 1, testAblage()))->ordner('Gibtsnicht');

    expect($ergebnis->ordnerFehlt)->toBeTrue()
        ->and($ergebnis->sauber())->toBeFalse()
        ->and($ergebnis->verschwunden)->toBe(0);
});

it('öffnet einen Ordner nicht, der aussieht wie beim letzten Mal', function () {
    // Der Grund, warum ein stuendlicher Lauf ueber 220 Ordner vertretbar ist:
    // `STATUS` ist eine Zeile, das Auflisten der Kennungen ist der teure Teil.
    $ablage = testAblage();
    $postfach = testPostfach([5 => ['message_id' => '<a@example.test>']]);

    stichtagSetzen($ablage);
    (new Erfassung($postfach, 1, $ablage))->ordner('INBOX');

    $zustand = MailFolderState::first();

    expect($zustand)->not->toBeNull()
        ->and($zustand->folder)->toBe('INBOX')
        ->and($zustand->uidvalidity)->toBe(7);

    $zweiter = (new Erfassung($postfach, 1, $ablage))->ordner('INBOX');

    expect($zweiter->uebersprungen)->toBeTrue()
        ->and($zweiter->aufgenommen)->toBe(0);
});

it('überspringt einen Ordner nicht, in dem etwas gelöscht wurde', function () {
    // `uidnext` aendert sich beim Loeschen NICHT. Ohne die Anzahl daneben
    // bliebe eine Loeschung fuer immer unbemerkt.
    $ablage = testAblage();

    stichtagSetzen($ablage);
    (new Erfassung(testPostfach([5 => ['message_id' => '<a@example.test>']]), 1, $ablage))->ordner('INBOX');

    $ergebnis = (new Erfassung(testPostfach([]), 1, $ablage))->ordner('INBOX');

    expect($ergebnis->uebersprungen)->toBeFalse()
        ->and($ergebnis->verschwunden)->toBe(1);
});

it('merkt sich den Zustand nicht, solange noch etwas offen ist', function () {
    // Sonst ueberspringt der naechste Lauf den Ordner — und das Uebersprungene
    // bliebe fuer immer aus.
    $nachrichten = [];

    for ($i = 1; $i <= 5; $i++) {
        $nachrichten[$i] = ['message_id' => "<n{$i}@example.test>"];
    }

    $postfach = testPostfach($nachrichten);
    $ablage = testAblage();

    stichtagSetzen($ablage);

    $erster = (new Erfassung($postfach, 1, $ablage))->ordner('INBOX', hoechstens: 2);

    // Der Stichtag darf NICHT vorruecken, solange noch etwas offen ist —
    // sonst ueberspringt der naechste Lauf den Ordner und das Uebersprungene
    // bleibt fuer immer aus.
    expect($erster->offen)->toBe(3)
        ->and(MailFolderState::first()->since_handle)->toBeNull();

    $zweiter = (new Erfassung($postfach, 1, $ablage))->ordner('INBOX', hoechstens: 2);

    expect($zweiter->uebersprungen)->toBeFalse()
        ->and($zweiter->aufgenommen)->toBe(2);
});

it('merkt sich den Zustand nicht nach einem Lauf mit Fehlern', function () {
    stichtagSetzen(testAblage());

    $ergebnis = (new Erfassung(
        testPostfach([5 => ['message_id' => '']]),
        1,
        testAblage(),
    ))->ordner('INBOX');

    expect($ergebnis->fehler)->toHaveCount(1)
        ->and(MailFolderState::first()->since_handle)->toBeNull();
});

it('holt eine einzelne Nachricht nach, wenn jemand sie öffnet', function () {
    // Der Weg rueckwaerts: Was vor dem Stichtag lag, kommt herein, sobald es
    // jemanden interessiert.
    $ablage = testAblage();
    $erfassung = new Erfassung(testPostfach([77 => ['message_id' => '<alt@example.test>']]), 1, $ablage);

    // Kein Stichtag noetig: Der Weg rueckwaerts geht bewusst daran vorbei.
    expect($erfassung->einzelne('INBOX', 77))->toBeTrue()
        ->and(MailMessage::count())->toBe(1)
        ->and(MailMessage::first()->body->content)->toBe('Die Freigabe ist da.');
});

it('holt eine Nachricht nicht zweimal, wenn sie schon da ist', function () {
    // Sonst kaeme bei jedem zweiten Blick auf dieselbe Mail ihre Rohfassung
    // erneut ueber die Leitung.
    $ablage = testAblage();
    $erfassung = new Erfassung(testPostfach([77 => ['message_id' => '<alt@example.test>']]), 1, $ablage);

    $erfassung->einzelne('INBOX', 77);

    expect($erfassung->einzelne('INBOX', 77))->toBeFalse()
        ->and(MailMessage::count())->toBe(1);
});
