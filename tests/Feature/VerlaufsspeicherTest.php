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

it('fragt die Mitte nach der ADRESSE, nicht nach der oertlichen Nummer', function () {
    // Dieselbe Mailbox hat in jedem System eine andere Kennung. Wer mit der
    // eigenen Nummer fragt, bekommt die Unterhaltung eines fremden Postfachs —
    // und zwar ohne Fehlermeldung, weil ein Kettenschluessel weltweit
    // eindeutig ist und zufaellig auch dort passen kann.
    $gefragt = [];

    $s = new BrainVerlaufsspeicher(
        function (string $f, array $a) use (&$gefragt): array {
            $gefragt[] = $a;

            return ['ok' => true, 'data' => ['available' => true]];
        },
        300,
        60,
        fn (int $account): ?string => $account === 3 ? 'buero@example.test' : null,
    );

    $s->verfuegbar(3, 'kette@example.test');

    expect($gefragt[0]['mailbox'])->toBe('buero@example.test')
        ->and($gefragt[0]['account_id'])->toBe(3);
});

it('laesst die Adresse weg, wenn es zur Nummer keine gibt', function () {
    // Sonst stuende dort `null`, und die Mitte muesste raten, ob das „unbekannt"
    // heisst oder „nicht gefragt".
    $gefragt = [];

    $s = new BrainVerlaufsspeicher(
        function (string $f, array $a) use (&$gefragt): array {
            $gefragt[] = $a;

            return ['ok' => true, 'data' => ['available' => false]];
        },
        300,
        60,
        fn (int $account): ?string => null,
    );

    $s->verfuegbar(9, 'kette@example.test');

    expect($gefragt[0])->not->toHaveKey('mailbox');
});

/**
 * Eine zweite Nachricht in dieselbe Kette — fuer die Faelle, in denen es auf
 * die Reihenfolge ankommt.
 */
function verlaufAntwort(string $wurzel, string $id, string $text, ?string $entferntVon = null): MailMessage
{
    $n = MailMessage::create([
        'email_account_id' => 1,
        'message_id' => "<{$id}>",
        'thread_key' => $wurzel,
        'is_root' => false,
        'subject' => 'Re: Angebot',
        'from_email' => 'wir@example.test',
        'sent_at' => now()->subHours(2),
    ]);

    MailBody::create(['mail_message_id' => $n->id, 'content' => $text]);

    if ($entferntVon !== null) {
        // Genau der Zustand, den die Entfernung hinterlaesst: Rumpf weg,
        // Kopfzeilen leer, Kettenschluessel und Zeitpunkt bleiben.
        MailBody::where('mail_message_id', $n->id)->delete();
        $n->update([
            'message_id' => '',
            'subject' => null,
            'from_email' => null,
            'from_name' => null,
            'purged_at' => now()->subHour(),
            'purged_by' => $entferntVon,
        ]);
    }

    return $n->refresh();
}

it('haelt die Stelle einer entfernten Nachricht in der Kette', function () {
    // Der Punkt der ganzen Uebung: Wer nur eine Nachricht loescht, loescht
    // nicht die Unterhaltung. Faellt der Eintrag weg, beziehen sich die
    // folgenden Antworten auf etwas, das es nie gegeben zu haben scheint.
    verlaufKette();
    verlaufAntwort('anfang@example.test', 'mitte@example.test', 'Geheim.', 'bastian');
    $letzte = verlaufAntwort('anfang@example.test', 'ende@example.test', 'Danke!');
    $letzte->update(['sent_at' => now()->subMinutes(10)]);

    $verlauf = (new LokalerVerlaufsspeicher)->verlauf(1, 'anfang@example.test');

    expect($verlauf)->toHaveCount(3)
        ->and($verlauf[0]['content'])->toBe('Bitte um ein Angebot.')
        ->and($verlauf[1]['purged_at'])->not->toBeNull()
        ->and($verlauf[1]['purged_by'])->toBe('bastian')
        ->and($verlauf[1]['sent_at'])->not->toBeNull()
        ->and($verlauf[2]['content'])->toBe('Danke!');
});

it('verraet im Grabstein nichts ueber die entfernte Nachricht', function () {
    // Sonst waere die Entfernung eine Verschiebung: Betreff und Absender
    // stuenden weiter fuer jeden da, der das Postfach oeffnen darf.
    verlaufKette();
    verlaufAntwort('anfang@example.test', 'mitte@example.test', 'Geheim.', 'bastian');

    $grabstein = (new LokalerVerlaufsspeicher)->verlauf(1, 'anfang@example.test')[1];

    expect($grabstein['subject'])->toBeNull()
        ->and($grabstein['from']['email'])->toBeNull()
        ->and($grabstein['content'])->toBe('')
        ->and($grabstein['quote'])->toBeNull()
        ->and($grabstein['original'])->toBeNull()
        ->and($grabstein['in_mailbox'])->toBeFalse()
        ->and($grabstein['attachment_count'])->toBe(0);
});

it('setzt purged_at bei gebliebenen Nachrichten nicht', function () {
    // Sonst zeigte die Oberflaeche jede Blase als Grabstein — und der Test
    // oben waere trotzdem gruen.
    verlaufKette();

    expect((new LokalerVerlaufsspeicher)->verlauf(1, 'anfang@example.test')[0]['purged_at'])->toBeNull();
});

it('bietet keinen Verlauf mehr an, wenn die ganze Kette entfernt ist', function () {
    // Der zweite Fall: Wird alles entfernt, gibt es nichts mehr zu erzaehlen.
    // Ein Verlauf aus lauter Grabsteinen ist kein Verlauf.
    verlaufKette();
    MailBody::query()->delete();
    MailMessage::query()->update(['purged_at' => now(), 'purged_by' => 'bastian', 'message_id' => '']);

    expect((new LokalerVerlaufsspeicher)->verfuegbar(1, 'anfang@example.test'))->toBeFalse();
});

it('bietet den Verlauf weiter an, solange eine Nachricht geblieben ist', function () {
    verlaufKette();
    verlaufAntwort('anfang@example.test', 'mitte@example.test', 'Geheim.', 'bastian');

    expect((new LokalerVerlaufsspeicher)->verfuegbar(1, 'anfang@example.test'))->toBeTrue();
});
