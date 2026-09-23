<?php

use Illuminate\Foundation\Testing\RefreshDatabase;
use Peppermint\Mailbox\Archive\Erfassung;
use Peppermint\Mailbox\Database\ArchiveTables;
use Peppermint\Mailbox\Http\HandlesConversations;
use Peppermint\Mailbox\Models\MailMessage;

uses(RefreshDatabase::class);

beforeEach(function () {
    ArchiveTables::create();
});

class TestControllerFuerVerlauf
{
    use HandlesConversations;

    protected function currentUserId(): ?int
    {
        return 1;
    }
}

function verlauf(): TestControllerFuerVerlauf
{
    return new TestControllerFuerVerlauf;
}

/**
 * Legt eine Kette an: Wurzel plus Antworten.
 */
function kette(string $wurzelId, array $antworten = [], int $account = 1): void
{
    MailMessage::create([
        'email_account_id' => $account,
        'message_id' => "<{$wurzelId}>",
        'thread_key' => $wurzelId,
        'is_root' => true,
        'subject' => 'Angebot',
        'from_email' => 'kunde@example.test',
        'sent_at' => now()->subDays(3),
    ]);

    foreach ($antworten as $i => $id) {
        MailMessage::create([
            'email_account_id' => $account,
            'message_id' => "<{$id}>",
            'thread_key' => $wurzelId,
            'is_root' => false,
            'subject' => 'Re: Angebot',
            'from_email' => 'wir@example.test',
            'sent_at' => now()->subDays(2 - $i),
        ]);
    }
}

it('bietet den Verlauf an, wenn der Anfang der Kette da ist', function () {
    kette('anfang@example.test', ['antwort@example.test']);

    $antwort = verlauf()->conversationAvailable(1, 'anfang@example.test');

    expect($antwort->getData(true)['available'])->toBeTrue();
});

it('bietet den Verlauf NICHT an, wenn die Kette vor dem Stichtag begann', function () {
    // Nur Antworten, kein Anfang: Ein Chat, der mit der dritten Antwort
    // beginnt, sieht nicht aus wie „unvollstaendig", sondern wie „so war es".
    MailMessage::create([
        'email_account_id' => 1,
        'message_id' => '<spaete-antwort@example.test>',
        'thread_key' => 'alter-anfang@example.test',
        'is_root' => false,
        'sent_at' => now(),
    ]);

    $antwort = verlauf()->conversationAvailable(1, 'alter-anfang@example.test');

    expect($antwort->getData(true)['available'])->toBeFalse();
});

it('gibt den Verlauf in zeitlicher Reihenfolge heraus', function () {
    kette('anfang@example.test', ['a@example.test', 'b@example.test']);

    $daten = verlauf()->conversation(1, 'anfang@example.test')->getData(true);

    expect($daten['available'])->toBeTrue()
        ->and($daten['entries'])->toHaveCount(3)
        ->and($daten['entries'][0]['message_id'])->toBe('<anfang@example.test>')
        ->and($daten['entries'][2]['message_id'])->toBe('<b@example.test>');
});

it('antwortet mit 409 statt 404, wenn der Anfang fehlt', function () {
    // Die Kette gibt es — nur nicht vollstaendig genug. „Nicht gefunden"
    // schickte jemanden auf die Suche nach einem Fehler, der keiner ist.
    $antwort = verlauf()->conversation(1, 'unbekannt@example.test');

    expect($antwort->getStatusCode())->toBe(409)
        ->and($antwort->getData(true)['available'])->toBeFalse();
});

it('trennt die Ketten zweier Postfächer', function () {
    kette('anfang@example.test', [], account: 1);
    kette('anfang@example.test', [], account: 2);

    $daten = verlauf()->conversation(1, 'anfang@example.test')->getData(true);

    expect($daten['entries'])->toHaveCount(1);
});

it('erkennt die Wurzel an der normalisierten Kennung, nicht an den spitzen Klammern', function () {
    // Die Falle: Der Kettenschluessel ist normalisiert (`abc@x`), die
    // Message-ID nicht (`<abc@x>`). Ein direkter Vergleich waere NIE wahr —
    // und der Verlauf saehe nicht kaputt aus, sondern wie „es gibt eben noch
    // keine vollstaendige Kette".
    $ablage = new class implements Peppermint\Mailbox\Archive\Ablage
    {
        public function ablegen(string $pfad, string $bytes): void {}

        public function vorhanden(string $pfad): bool
        {
            return false;
        }

        public function lesen(string $pfad): ?string
        {
            return null;
        }
    };

    $postfach = Mockery::mock(Peppermint\Mailbox\Contracts\Mailbox::class);
    $postfach->shouldReceive('message')->andReturn([
        'message_id' => '<wurzel@example.test>',
        'subject' => 'Ohne Vorgaenger',
        'body_text' => 'Erster Kontakt.',
        'attachments' => [],
    ]);
    $postfach->shouldReceive('raw')->andReturn(['raw' => 'ROH', 'size' => 3, 'complete' => true]);

    (new Erfassung($postfach, 1, $ablage))->einzelne('INBOX', 1);

    $n = MailMessage::first();

    expect($n->thread_key)->toBe('wurzel@example.test')
        ->and($n->message_id)->toBe('<wurzel@example.test>')
        ->and($n->istWurzel())->toBeTrue()
        ->and(verlauf()->conversationAvailable(1, 'wurzel@example.test')->getData(true)['available'])->toBeTrue();
});
