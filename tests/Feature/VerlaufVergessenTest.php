<?php

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Peppermint\Mailbox\Contracts\AccountStore;
use Peppermint\Mailbox\Events\VerlaufVergessen;
use Peppermint\Mailbox\Models\MailAccount;
use Peppermint\Mailbox\Stores\BrainVerlaufsspeicher;

/**
 * Was die Mitte entfernt hat, soll hier nicht noch fuenf Minuten stehen.
 *
 * Der Zwischenspeicher haelt „gibt es einen Verlauf?" fuenf Minuten — richtig,
 * solange nur gelesen wird. Wer loescht, will aber nicht hoeren „in fuenf
 * Minuten ist es wirklich weg".
 */
function kontenSpeicher(array $konten): void
{
    app()->instance(AccountStore::class, new class($konten) implements AccountStore
    {
        public function __construct(private array $konten) {}

        public function all(?int $ownerId = null): Collection
        {
            return collect($this->konten)->map(fn (array $z): MailAccount => MailAccount::fromRemote($z));
        }

        public function find(string|int $id): ?MailAccount
        {
            return $this->all()->firstWhere('id', $id);
        }

        public function isWritable(): bool
        {
            return false;
        }
    });
}

it('vergisst beide Schluessel des betroffenen Postfachs', function () {
    kontenSpeicher([['id' => 4, 'email' => 'gruppe@example.test']]);

    Cache::put(BrainVerlaufsspeicher::schluessel('verfuegbar', 4, 'kette@example.test'), true, 300);
    Cache::put(BrainVerlaufsspeicher::schluessel('verlauf', 4, 'kette@example.test'), [['id' => 1]], 60);

    $betroffen = VerlaufVergessen::ausEreignis([
        'mailbox' => 'gruppe@example.test',
        'thread_key' => 'kette@example.test',
    ]);

    expect($betroffen)->toBe(1)
        ->and(Cache::has(BrainVerlaufsspeicher::schluessel('verfuegbar', 4, 'kette@example.test')))->toBeFalse()
        ->and(Cache::has(BrainVerlaufsspeicher::schluessel('verlauf', 4, 'kette@example.test')))->toBeFalse();
});

it('uebersetzt die Adresse in die OERTLICHE Nummer', function () {
    // Die Mitte kennt ihre Nummer, dieses System seine. Ohne Uebersetzung
    // vergisst man einen Schluessel, den es hier nie gab.
    kontenSpeicher([['id' => 77, 'email' => 'gruppe@example.test']]);

    Cache::put(BrainVerlaufsspeicher::schluessel('verfuegbar', 77, 'k@example.test'), true, 300);
    // Der Schluessel mit der Nummer der Mitte — den darf es nicht treffen.
    Cache::put(BrainVerlaufsspeicher::schluessel('verfuegbar', 4, 'k@example.test'), true, 300);

    VerlaufVergessen::ausEreignis(['mailbox' => 'GRUPPE@example.test', 'thread_key' => 'k@example.test']);

    expect(Cache::has(BrainVerlaufsspeicher::schluessel('verfuegbar', 77, 'k@example.test')))->toBeFalse()
        ->and(Cache::has(BrainVerlaufsspeicher::schluessel('verfuegbar', 4, 'k@example.test')))->toBeTrue();
});

it('laesst fremde Postfaecher in Ruhe', function () {
    kontenSpeicher([
        ['id' => 4, 'email' => 'gruppe@example.test'],
        ['id' => 5, 'email' => 'anderes@example.test'],
    ]);

    Cache::put(BrainVerlaufsspeicher::schluessel('verfuegbar', 5, 'k@example.test'), true, 300);

    VerlaufVergessen::ausEreignis(['mailbox' => 'gruppe@example.test', 'thread_key' => 'k@example.test']);

    expect(Cache::has(BrainVerlaufsspeicher::schluessel('verfuegbar', 5, 'k@example.test')))->toBeTrue();
});

it('tut nichts bei einem unvollstaendigen Ereignis', function () {
    kontenSpeicher([['id' => 4, 'email' => 'gruppe@example.test']]);

    expect(VerlaufVergessen::ausEreignis(['mailbox' => 'gruppe@example.test']))->toBe(0)
        ->and(VerlaufVergessen::ausEreignis(['thread_key' => 'k@example.test']))->toBe(0)
        ->and(VerlaufVergessen::ausEreignis([]))->toBe(0);
});

it('haengt am selben Schluessel wie der Speicher', function () {
    // Zwei Stellen, die denselben Schluessel selbst zusammensetzen, laufen
    // auseinander — und dann vergisst man Schluessel, die es nicht gibt.
    expect(BrainVerlaufsspeicher::schluessel('verfuegbar', 4, 'k@example.test'))
        ->toBe('mailbox.verlauf.verfuegbar.4.'.sha1('k@example.test'));
});
