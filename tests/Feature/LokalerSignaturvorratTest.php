<?php

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;
use Peppermint\Mailbox\Http\PflegtSignaturen;
use Peppermint\Mailbox\Models\MailAccount;
use Peppermint\Mailbox\Models\MailSignature;
use Peppermint\Mailbox\Stores\LokalerSignaturvorrat;

/**
 * Signaturen, die ein Produkt selbst fuehrt (24.11.2026, #10195).
 *
 * Drei Dinge koennen hier still falsch sein:
 *
 * - Die Reihenfolge. Die Verfassen-Seite nimmt die ERSTE Vorgabe — steht die
 *   allgemeine vor der des Postfachs, antwortet man an info@ persoenlich.
 * - Die Person. Ohne sie „alle Signaturen" zu liefern hiesse, mit der
 *   Unterschrift eines Kollegen zu antworten.
 * - Das Postfach. Eine Signatur an ein Postfach zu binden, das man nicht sehen
 *   darf, verraet, dass es das Postfach gibt.
 */
uses(RefreshDatabase::class);

class SignaturPflegePerson extends Model
{
    protected $table = 'users';

    protected $guarded = [];

    public $timestamps = false;
}

class TestControllerFuerSignaturPflege
{
    use PflegtSignaturen;

    public function __construct(private readonly Model $ich) {}

    protected function signaturPerson(): Model
    {
        return $this->ich;
    }
}

function signaturAnfrage(array $daten, string $methode = 'POST'): Request
{
    $r = Request::create('/', $methode, $daten);
    $r->headers->set('Accept', 'application/json');

    return $r;
}

beforeEach(function () {
    if (! Schema::hasTable('users')) {
        Schema::create('users', function (Blueprint $table): void {
            $table->id();
            $table->string('email');
        });
    }

    config()->set('mailbox.user_model', SignaturPflegePerson::class);

    DB::table('users')->insert([
        ['id' => 3, 'email' => 'anna@beispiel.de'],
        ['id' => 4, 'email' => 'bert@beispiel.de'],
    ]);

    $this->anna = SignaturPflegePerson::find(3);
    $this->bert = SignaturPflegePerson::find(4);
    $this->info = MailAccount::create(['email' => 'info@beispiel.de']);
    $this->privat = MailAccount::create(['email' => 'bert@beispiel.de', 'user_id' => 4]);
});

it('legt die Tabelle an', function () {
    expect(Schema::hasColumns('mail_signatures', ['user_id', 'mail_account_id', 'name', 'body_html', 'is_default']))->toBeTrue();
});

it('liefert am Postfach dessen Signaturen vor den allgemeinen, darin die Vorgabe zuerst', function () {
    MailSignature::create(['user_id' => 3, 'mail_account_id' => null, 'name' => 'Allgemein', 'body_html' => '<p>A</p>', 'is_default' => true]);
    MailSignature::create(['user_id' => 3, 'mail_account_id' => $this->info->id, 'name' => 'Info kurz', 'body_html' => '<p>K</p>', 'is_default' => false]);
    MailSignature::create(['user_id' => 3, 'mail_account_id' => $this->info->id, 'name' => 'Info', 'body_html' => '<p>I</p>', 'is_default' => true]);
    MailSignature::create(['user_id' => 3, 'mail_account_id' => $this->privat->id, 'name' => 'Anderes Postfach', 'body_html' => '<p>X</p>']);

    $namen = array_column((new LokalerSignaturvorrat(fn () => 3))->signaturen($this->info), 'name');

    expect($namen)->toBe(['Info', 'Info kurz', 'Allgemein']);
});

it('liefert niemandem die Signaturen einer anderen Person', function () {
    MailSignature::create(['user_id' => 3, 'mail_account_id' => null, 'name' => 'Anna', 'body_html' => '<p>A</p>']);

    expect((new LokalerSignaturvorrat(fn () => 4))->signaturen($this->info))->toBe([])
        ->and((new LokalerSignaturvorrat(fn () => null))->signaturen($this->info))->toBe([]);
});

it('laesst eine leere Signatur aus der Auswahl', function () {
    MailSignature::create(['user_id' => 3, 'mail_account_id' => null, 'name' => 'Leer', 'body_html' => '  ']);

    expect((new LokalerSignaturvorrat(fn () => 3))->signaturen($this->info))->toBe([]);
});

it('legt eine Signatur fuer die eigene Person an', function () {
    $antwort = (new TestControllerFuerSignaturPflege($this->anna))->signaturAnlegen(signaturAnfrage([
        'name' => 'Gruss',
        'body_html' => '<p>Viele Gruesse</p>',
        'mail_account_id' => $this->info->id,
        'is_default' => true,
        // Eine fremde Person in der Nutzlast zaehlt nicht.
        'user_id' => 4,
    ]));

    expect($antwort->getStatusCode())->toBe(201)
        ->and(MailSignature::sole()->user_id)->toBe(3)
        ->and(MailSignature::sole()->mail_account_id)->toBe($this->info->id);
});

it('bindet keine Signatur an ein Postfach, das die Person nicht sieht', function () {
    (new TestControllerFuerSignaturPflege($this->anna))->signaturAnlegen(signaturAnfrage([
        'name' => 'Fremd',
        'body_html' => '<p>x</p>',
        'mail_account_id' => $this->privat->id,
    ]));
})->throws(ValidationException::class);

it('nimmt keine leere Signatur an', function () {
    (new TestControllerFuerSignaturPflege($this->anna))->signaturAnlegen(signaturAnfrage([
        'name' => 'Leer',
        'body_html' => '<p><br></p>',
    ]));
})->throws(ValidationException::class);

it('laesst nur die eigenen aendern und loeschen', function () {
    $fremd = MailSignature::create(['user_id' => 4, 'name' => 'Bert', 'body_html' => '<p>B</p>']);

    expect(fn () => (new TestControllerFuerSignaturPflege($this->anna))->signaturAendern(signaturAnfrage([
        'name' => 'Uebernommen', 'body_html' => '<p>!</p>',
    ], 'PATCH'), $fremd->id))->toThrow(ModelNotFoundException::class)
        ->and(fn () => (new TestControllerFuerSignaturPflege($this->anna))->signaturLoeschen($fremd->id))
        ->toThrow(ModelNotFoundException::class);

    expect($fremd->refresh()->name)->toBe('Bert');
});

it('haelt je Bereich genau eine Vorgabe', function () {
    $alt = MailSignature::create(['user_id' => 3, 'mail_account_id' => $this->info->id, 'name' => 'Alt', 'body_html' => '<p>A</p>', 'is_default' => true]);
    $allgemein = MailSignature::create(['user_id' => 3, 'mail_account_id' => null, 'name' => 'Allgemein', 'body_html' => '<p>G</p>', 'is_default' => true]);
    $bert = MailSignature::create(['user_id' => 4, 'mail_account_id' => $this->info->id, 'name' => 'Bert', 'body_html' => '<p>B</p>', 'is_default' => true]);

    (new TestControllerFuerSignaturPflege($this->anna))->signaturAnlegen(signaturAnfrage([
        'name' => 'Neu', 'body_html' => '<p>N</p>', 'mail_account_id' => $this->info->id, 'is_default' => true,
    ]));

    expect($alt->refresh()->is_default)->toBeFalse()
        ->and($allgemein->refresh()->is_default)->toBeTrue()
        ->and($bert->refresh()->is_default)->toBeTrue();
});

it('laesst eine Signatur bearbeiten, deren Postfach inzwischen unsichtbar ist', function () {
    $zeile = MailSignature::create(['user_id' => 3, 'mail_account_id' => $this->privat->id, 'name' => 'Alt', 'body_html' => '<p>A</p>']);

    (new TestControllerFuerSignaturPflege($this->anna))->signaturAendern(signaturAnfrage([
        'name' => 'Umbenannt', 'body_html' => '<p>A</p>', 'mail_account_id' => $this->privat->id,
    ], 'PATCH'), $zeile->id);

    expect($zeile->refresh()->name)->toBe('Umbenannt');
});

it('listet die eigenen Signaturen und die sichtbaren Postfaecher', function () {
    MailSignature::create(['user_id' => 3, 'name' => 'Anna', 'body_html' => '<p>A</p>']);
    MailSignature::create(['user_id' => 4, 'name' => 'Bert', 'body_html' => '<p>B</p>']);

    $daten = (new TestControllerFuerSignaturPflege($this->anna))->signaturListe()->getData(true);

    expect(array_column($daten['signatures'], 'name'))->toBe(['Anna'])
        ->and(array_column($daten['accounts'], 'email'))->toBe(['info@beispiel.de']);
});

it('laesst eine vorhandene Tabelle gleichen Namens stehen', function () {
    // AI Brain fuehrt `mail_signatures` schon, in derselben Form. Ein
    // Paket-Update dort darf sie weder neu anlegen noch scheitern.
    $migration = require __DIR__.'/../../database/migrations/signaturen/2026_11_24_160000_create_mail_signatures_table.php';
    MailSignature::create(['user_id' => 3, 'name' => 'Bleibt', 'body_html' => '<p>B</p>']);

    $migration->up();

    expect(MailSignature::count())->toBe(1);
});
