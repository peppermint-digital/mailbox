<?php

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Peppermint\Mailbox\Content\Gespraechstext;
use Peppermint\Mailbox\Database\ArchiveTables;
use Peppermint\Mailbox\Models\MailBody;
use Peppermint\Mailbox\Models\MailLocation;
use Peppermint\Mailbox\Models\MailMessage;

uses(RefreshDatabase::class);

beforeEach(function () {
    ArchiveTables::create();
});

it('legt die drei Tabellen an', function () {
    expect(Schema::hasTable('mail_messages'))->toBeTrue()
        ->and(Schema::hasTable('mail_locations'))->toBeTrue()
        ->and(Schema::hasTable('mail_bodies'))->toBeTrue();
});

it('setzt den Hash der Message-ID selbst', function () {
    // Der Riegel gegen Doppelanlagen haengt daran. Wer ihn von Hand setzen
    // muesste, vergaesse ihn — und bekaeme keine Fehlermeldung, sondern eine
    // Ablage mit Dubletten.
    $n = MailMessage::create([
        'email_account_id' => 1,
        'message_id' => '<abc@example.test>',
    ]);

    expect($n->message_id_hash)->toBe(hash('sha256', '<abc@example.test>'))
        ->and($n->message_id_hash)->toHaveLength(64);
});

it('lässt dieselbe Nachricht je Postfach nur einmal zu', function () {
    MailMessage::create(['email_account_id' => 1, 'message_id' => '<abc@example.test>']);

    expect(fn () => MailMessage::create(['email_account_id' => 1, 'message_id' => '<abc@example.test>']))
        ->toThrow(Illuminate\Database\UniqueConstraintViolationException::class);
});

it('lässt dieselbe Nachricht in zwei Postfächern zu', function () {
    // Ein Gruppenpostfach und ein persoenliches bekommen dieselbe Mail. Das
    // sind zwei Zeilen, weil die Orte und der Lesezustand verschieden sind.
    MailMessage::create(['email_account_id' => 1, 'message_id' => '<abc@example.test>']);
    MailMessage::create(['email_account_id' => 2, 'message_id' => '<abc@example.test>']);

    expect(MailMessage::count())->toBe(2);
});

it('unterscheidet zwei Message-IDs mit gleichem Anfang', function () {
    // Der Fall, den ein Praefix-Index ueber die ersten 191 Zeichen zu einer
    // einzigen Nachricht gemacht haette.
    $anfang = str_repeat('a', 200);

    MailMessage::create(['email_account_id' => 1, 'message_id' => "<{$anfang}1@example.test>"]);
    MailMessage::create(['email_account_id' => 1, 'message_id' => "<{$anfang}2@example.test>"]);

    expect(MailMessage::count())->toBe(2);
});

it('findet eine Nachricht über ihre Message-ID', function () {
    MailMessage::create(['email_account_id' => 1, 'message_id' => '<gesucht@example.test>']);
    MailMessage::create(['email_account_id' => 1, 'message_id' => '<anderes@example.test>']);

    $treffer = MailMessage::query()->fuerMessageId(1, '<gesucht@example.test>')->first();

    expect($treffer?->message_id)->toBe('<gesucht@example.test>');
});

it('führt die Umzüge einer Nachricht als Geschichte', function () {
    $n = MailMessage::create(['email_account_id' => 1, 'message_id' => '<umzug@example.test>']);

    // Erst im Posteingang, dann verschoben: zwei Zeilen, eine geschlossen.
    MailLocation::create([
        'mail_message_id' => $n->id, 'email_account_id' => 1,
        'folder' => 'INBOX', 'uid' => 42, 'uidvalidity' => 7,
        'seen_at' => now()->subDay(), 'gone_at' => now()->subHour(),
    ]);
    MailLocation::create([
        'mail_message_id' => $n->id, 'email_account_id' => 1,
        'folder' => 'Archiv', 'uid' => 9, 'uidvalidity' => 3,
        'seen_at' => now(),
    ]);

    expect($n->locations()->count())->toBe(2)
        ->and($n->aktuelleOrte()->count())->toBe(1)
        ->and($n->aktuelleOrte()->first()->folder)->toBe('Archiv');
});

it('behält die Nachricht, wenn sie aus dem Postfach verschwindet', function () {
    // Der Kern der Sache: Wer im Postfach loescht, entfernt hier nichts.
    $n = MailMessage::create([
        'email_account_id' => 1,
        'message_id' => '<geloescht@example.test>',
        'raw_path' => '2026/09/abc.eml',
        'raw_sha256' => str_repeat('f', 64),
    ]);
    $ort = MailLocation::create([
        'mail_message_id' => $n->id, 'email_account_id' => 1,
        'folder' => 'INBOX', 'uid' => 42, 'seen_at' => now()->subDay(),
    ]);

    $ort->update(['gone_at' => now()]);
    $n->update(['missing_since' => now()]);

    $frisch = MailMessage::find($n->id);

    expect($frisch)->not->toBeNull()
        ->and($frisch->imPostfach())->toBeFalse()
        ->and($frisch->raw_path)->toBe('2026/09/abc.eml')
        ->and($frisch->aktuelleOrte()->count())->toBe(0);
});

it('legt den aufbereiteten Text mit seiner Fassung ab', function () {
    $n = MailMessage::create(['email_account_id' => 1, 'message_id' => '<text@example.test>']);

    $text = Gespraechstext::aus("Die Freigabe ist da.\n\n-- \nChris");

    MailBody::create(['mail_message_id' => $n->id] + MailBody::felder($text));

    $body = $n->fresh()->body;

    expect($body->content)->toBe('Die Freigabe ist da.')
        ->and($body->signature)->toContain('Chris')
        ->and($body->parser_version)->toBe(MailBody::FASSUNG)
        ->and($body->veraltet())->toBeFalse();
});

it('erkennt Text aus einer älteren Fassung als nacharbeitsbedürftig', function () {
    $n = MailMessage::create(['email_account_id' => 1, 'message_id' => '<alt@example.test>']);

    MailBody::create([
        'mail_message_id' => $n->id,
        'content' => 'irgendwas',
        'parser_version' => '0',
    ]);

    expect($n->fresh()->body->veraltet())->toBeTrue();
});

it('hält Empfänger als Liste und nicht als Zeichenkette', function () {
    $n = MailMessage::create([
        'email_account_id' => 1,
        'message_id' => '<empf@example.test>',
        'recipients' => [
            ['email' => 'a@example.test', 'name' => 'A', 'type' => 'to'],
            ['email' => 'b@example.test', 'name' => null, 'type' => 'cc'],
        ],
    ]);

    expect($n->fresh()->recipients)->toBeArray()
        ->and($n->fresh()->recipients[1]['type'])->toBe('cc');
});
