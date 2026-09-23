<?php

use Illuminate\Foundation\Testing\RefreshDatabase;
use Peppermint\Mailbox\Archive\Ablage;
use Peppermint\Mailbox\Archive\Erfassung;
use Peppermint\Mailbox\Contracts\Mailbox;
use Peppermint\Mailbox\Database\ArchiveTables;
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

it('nimmt neue Nachrichten auf', function () {
    $ablage = testAblage();

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

    (new Erfassung($postfach, 1, $ablage))->ordner('INBOX');
    $zweiter = (new Erfassung($postfach, 1, $ablage))->ordner('INBOX');

    expect($zweiter->aufgenommen)->toBe(0)
        ->and(MailMessage::count())->toBe(1)
        ->and(MailLocation::count())->toBe(1);
});

it('meldet eine Kopie, die nicht vollständig ist', function () {
    // Der Fall, der sonst als wortgetreu durchginge — und bei dem die
    // DKIM-Signatur wertlos ist.
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

    (new Erfassung(testPostfach([5 => ['message_id' => '<zurueck@example.test>']]), 1, $ablage))->ordner('INBOX');
    (new Erfassung(testPostfach([]), 1, $ablage))->ordner('INBOX');

    expect(MailMessage::first()->imPostfach())->toBeFalse();

    // Aus dem Papierkorb zurueckgeholt.
    (new Erfassung(testPostfach([12 => ['message_id' => '<zurueck@example.test>']]), 1, $ablage))->ordner('INBOX');

    expect(MailMessage::first()->imPostfach())->toBeTrue();
});

it('erkennt einen Bruch der Gültigkeitsnummer, statt alles für verschwunden zu halten', function () {
    $ablage = testAblage();

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

    $ergebnis = (new Erfassung(testPostfach($nachrichten), 1, testAblage()))->ordner('INBOX', hoechstens: 2);

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
