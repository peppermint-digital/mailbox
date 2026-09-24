<?php

use Illuminate\Foundation\Testing\RefreshDatabase;
use Peppermint\Mailbox\Contracts\Verlaufsspeicher;
use Peppermint\Mailbox\Database\ArchiveTables;
use Peppermint\Mailbox\Models\MailBody;
use Peppermint\Mailbox\Models\MailMessage;
use Peppermint\Mailbox\Stores\BrainVerlaufsspeicher;
use Peppermint\Mailbox\Stores\LokalerVerlaufsspeicher;

uses(RefreshDatabase::class);

/**
 * Eine Ablage, viele Leser.
 *
 * Der Verlauf liegt dort, wo archiviert wird — und soll trotzdem in jedem
 * System zu sehen sein. Dieselbe Aufteilung wie bei den Postfaechern.
 */
function verlaufKette(int $account = 1, string $wurzel = 'anfang@example.test'): void
{
    ArchiveTables::create();

    $n = MailMessage::create([
        'email_account_id' => $account,
        'message_id' => "<{$wurzel}>",
        'thread_key' => $wurzel,
        'is_root' => true,
        'subject' => 'Angebot',
        'from_email' => 'kunde@example.test',
        'sent_at' => now()->subDay(),
    ]);

    MailBody::create([
        'mail_message_id' => $n->id,
        'content' => 'Bitte um ein Angebot.',
        'signature' => 'Mit freundlichen Grüßen',
    ]);
}

it('liest den Verlauf lokal, wenn die Ablage da ist', function () {
    verlaufKette();

    $s = new LokalerVerlaufsspeicher;

    expect($s->verfuegbar(1, 'anfang@example.test'))->toBeTrue()
        ->and($s->verlauf(1, 'anfang@example.test'))->toHaveCount(1)
        ->and($s->verlauf(1, 'anfang@example.test')[0]['content'])->toBe('Bitte um ein Angebot.');
});

it('zeigt die veredelte Fassung und das Original daneben', function () {
    verlaufKette();
    MailBody::first()->update(['refined' => 'Der Kunde bittet um ein Angebot.', 'refined_by' => 'modell-1']);

    $eintrag = (new LokalerVerlaufsspeicher)->verlauf(1, 'anfang@example.test')[0];

    expect($eintrag['content'])->toBe('Der Kunde bittet um ein Angebot.')
        ->and($eintrag['original'])->toBe('Bitte um ein Angebot.')
        ->and($eintrag['refined_by'])->toBe('modell-1');
});

it('schickt das Original nicht mit, wenn kein Modell mitgeschrieben hat', function () {
    // Sonst staende in jeder Blase derselbe Text zweimal, und „hier hat eine
    // Maschine mitgeschrieben" verlöre seine Bedeutung.
    verlaufKette();

    expect((new LokalerVerlaufsspeicher)->verlauf(1, 'anfang@example.test')[0]['original'])->toBeNull();
});

it('holt den Verlauf über die Bridge, wenn es keine eigene Ablage gibt', function () {
    $gefragt = [];

    $s = new BrainVerlaufsspeicher(function (string $f, array $a) use (&$gefragt): array {
        $gefragt[] = $a;

        // Genau die Huelle, die das Werkzeug drueben schickt. Ein Fake, der
        // sie weglaesst, prueft die eigene Annahme statt der Leitung.
        return ['ok' => true, 'data' => ['available' => true, 'entries' => [['id' => 1, 'content' => 'Aus der Mitte.']]]];
    });

    expect($s->verlauf(7, 'kette@example.test')[0]['content'])->toBe('Aus der Mitte.')
        ->and($gefragt[0]['account_id'])->toBe(7)
        ->and($gefragt[0]['thread'])->toBe('kette@example.test');
});

it('fragt die Mitte nicht zweimal für dieselbe Frage', function () {
    // Die Verfuegbarkeit wird bei JEDER geoeffneten Nachricht gefragt. Ohne
    // Zwischenspeicher waere das ein Rundruf pro Klick, meist fuer ein „nein".
    $rufe = 0;

    $s = new BrainVerlaufsspeicher(function () use (&$rufe): array {
        $rufe++;

        return ['ok' => true, 'data' => ['available' => true]];
    });

    $s->verfuegbar(1, 'kette@example.test');
    $s->verfuegbar(1, 'kette@example.test');
    $s->verfuegbar(1, 'kette@example.test');

    expect($rufe)->toBe(1);
});

it('nimmt eine unerreichbare Mitte als „kein Verlauf", nicht als Fehler', function () {
    // Wer hier wuerfe, naehme wegen einer Lesehilfe die ganze
    // Nachrichtenansicht mit.
    $s = new BrainVerlaufsspeicher(function (): ?array {
        throw new RuntimeException('Gateway weg');
    });

    expect($s->verfuegbar(1, 'kette@example.test'))->toBeFalse()
        ->and($s->verlauf(1, 'kette@example.test'))->toBe([]);
});

it('bindet den lokalen Speicher, wenn die Ablage existiert', function () {
    ArchiveTables::create();

    expect(app(Verlaufsspeicher::class))->toBeInstanceOf(LokalerVerlaufsspeicher::class);
});

it('erkennt einen Verlauf auch dann, wenn die Antwort in ihrer Huelle steckt', function () {
    // Der Fehler, der hier haengt: `available` unter `data` zu uebersehen.
    // Nichts wirft, nichts loggt — der Umschalter erscheint nur nie, und das
    // sieht aus wie „es gibt noch keine Verlaeufe".
    $s = new BrainVerlaufsspeicher(fn (): array => ['ok' => true, 'data' => ['available' => true]]);

    expect($s->verfuegbar(1, 'kette@example.test'))->toBeTrue();
});
