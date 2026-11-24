<?php

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Peppermint\Mailbox\Accounts\OAuthVerbindung;
use Peppermint\Mailbox\Contracts\Mailbox;
use Peppermint\Mailbox\Http\LegtKontenAn;
use Peppermint\Mailbox\Mailboxes;
use Peppermint\Mailbox\Models\MailAccount;
use Peppermint\Mailbox\Sending\Mailer;
use Peppermint\Mailbox\Sending\RecordingTransport;

/**
 * Ein Postfach anlegen und verbinden (24.11.2026, #10189).
 *
 * Was hier still schiefgehen kann, ist am 30.10.2026 schon einmal passiert:
 * Ein „Neu verbinden" legte eine ZWEITE Zeile fuer ein vorhandenes Postfach
 * an, und die Maske meldete Erfolg. Die Proben zum Rueckweg pruefen deshalb
 * vor allem, welche Zeile geschrieben wird — und dass es genau eine bleibt.
 */
uses(RefreshDatabase::class);

class KontoAnlegenPerson extends Model
{
    protected $table = 'users';

    protected $guarded = [];

    public $timestamps = false;
}

class TestControllerFuerKontoAnlegen
{
    use LegtKontenAn;

    public static ?Model $ich = null;

    public static bool $darfTeilen = false;

    /** @var list<array{id: mixed, neu: bool}> */
    public static array $angelegt = [];

    protected function kontoPerson(): Model
    {
        abort_if(self::$ich === null, 403);

        return self::$ich;
    }

    protected function kontoDarfTeilen(Model $person): bool
    {
        return self::$darfTeilen;
    }

    protected function kontoZiel(): string
    {
        return '/postfaecher';
    }

    protected function kontoAngelegt(MailAccount $konto, bool $neu, Request $request): void
    {
        self::$angelegt[] = ['id' => $konto->getKey(), 'neu' => $neu];
    }
}

/** Ein id_token, wie es der Anbieter schickt — nur der mittlere Teil zaehlt. */
function idToken(array $angaben): string
{
    $teil = fn (array $wert): string => rtrim(strtr(base64_encode(json_encode($wert)), '+/', '-_'), '=');

    return $teil(['alg' => 'none']).'.'.$teil($angaben).'.signatur';
}

function lesenGeht(bool $ok = true, string $text = 'Verbindung steht, 5 Ordner gefunden.'): void
{
    Mailboxes::fake(function () use ($ok, $text) {
        $attrappe = Mockery::mock(Mailbox::class);
        $attrappe->shouldReceive('probe')->andReturn(['ok' => $ok, 'message' => $text]);

        return $attrappe;
    });
}

function kennwortFormular(array $abweichend = []): array
{
    return array_merge([
        'name' => 'Info',
        'email' => 'info@beispiel.de',
        'host' => 'imap.beispiel.de',
        'port' => 993,
        'encryption' => 'ssl',
        'username' => 'info@beispiel.de',
        'password' => 'geheim',
        'smtp_host' => 'smtp.beispiel.de',
        'smtp_port' => 587,
        'smtp_encryption' => 'tls',
    ], $abweichend);
}

/** Einen OAuth-Vorgang beginnen und den state zurueckgeben. */
function verbindenBeginnen($test, string $abfrage = ''): string
{
    $antwort = $test->get('/postfaecher/oauth/microsoft'.$abfrage);
    $antwort->assertRedirect();

    parse_str((string) parse_url($antwort->headers->get('Location'), PHP_URL_QUERY), $teile);

    return (string) $teile['state'];
}

function anbieterAntwortet(string $email, string $erneuerung = 'erneuerung-neu'): void
{
    Http::fake([
        'login.microsoftonline.com/*' => Http::response([
            'access_token' => 'zugriff-neu',
            'refresh_token' => $erneuerung,
            'expires_in' => 3600,
            'id_token' => idToken(['preferred_username' => $email, 'name' => 'Anna Beispiel']),
        ]),
    ]);
}

beforeEach(function () {
    if (! Schema::hasTable('users')) {
        Schema::create('users', function (Blueprint $table): void {
            $table->id();
            $table->string('email');
        });
    }

    config()->set('mailbox.store', 'local');
    config()->set('mailbox.user_model', KontoAnlegenPerson::class);
    config()->set('mailbox.oauth.microsoft', ['client_id' => 'app-pm', 'client_secret' => 'geheim-pm', 'tenant_id' => 'common']);
    config()->set('mailbox.oauth.google', ['client_id' => null, 'client_secret' => null]);
    config()->set('mailbox.oauth.redirect_uri', 'https://pm.beispiel.de/postfaecher/oauth/callback');

    DB::table('users')->insert([
        ['id' => 3, 'email' => 'anna@beispiel.de'],
        ['id' => 4, 'email' => 'bert@beispiel.de'],
    ]);

    TestControllerFuerKontoAnlegen::$ich = KontoAnlegenPerson::find(3);
    TestControllerFuerKontoAnlegen::$darfTeilen = false;
    TestControllerFuerKontoAnlegen::$angelegt = [];

    Route::middleware('web')->group(function (): void {
        Route::post('/postfaecher', [TestControllerFuerKontoAnlegen::class, 'kontoAnlegen']);
        Route::post('/postfaecher/pruefen', [TestControllerFuerKontoAnlegen::class, 'kontoPruefen']);
        Route::get('/postfaecher/oauth/callback', [TestControllerFuerKontoAnlegen::class, 'kontoRueckweg']);
        Route::get('/postfaecher/oauth/{anbieter}', [TestControllerFuerKontoAnlegen::class, 'kontoVerbinden']);
    });

    Mailer::fake(fn () => new RecordingTransport);
});

afterEach(function () {
    Mailboxes::fake(null);
    Mailer::fake(null);
});

/*
|--------------------------------------------------------------------------
| Kennwort-Postfach
|--------------------------------------------------------------------------
*/

it('legt ein persoenliches Kennwort-Postfach an, wenn die Verbindung steht', function () {
    lesenGeht();

    $this->postJson('/postfaecher', kennwortFormular())->assertCreated();

    $konto = MailAccount::query()->sole();

    expect($konto->field('email'))->toBe('info@beispiel.de')
        ->and($konto->field('user_id'))->toBe(3)
        ->and($konto->field('auth_type'))->toBe('password')
        ->and($konto->field('password'))->toBe('geheim')
        ->and($konto->field('imap_host'))->toBe('imap.beispiel.de')
        ->and($konto->field('smtp_port'))->toBe(587)
        ->and(TestControllerFuerKontoAnlegen::$angelegt)->toBe([['id' => $konto->getKey(), 'neu' => true]]);

    // Verschluesselt abgelegt, nicht im Klartext.
    expect(DB::table('mail_accounts')->value('password'))->not->toBe('geheim');
});

it('speichert nichts, wenn der Posteingang nicht antwortet', function () {
    lesenGeht(false, 'Authentication failed.');

    $this->postJson('/postfaecher', kennwortFormular())
        ->assertUnprocessable()
        ->assertJsonPath('errors.verbindung.0', 'Posteingang: Authentication failed.');

    expect(MailAccount::query()->count())->toBe(0);
});

it('speichert nichts, wenn der Postausgang die Anmeldung ablehnt', function () {
    lesenGeht();
    Mailer::fake(fn () => throw new RuntimeException('535 Authentication rejected'));

    $this->postJson('/postfaecher', kennwortFormular())
        ->assertUnprocessable()
        ->assertJsonPath('errors.verbindung.0', 'Postausgang: 535 Authentication rejected');

    expect(MailAccount::query()->count())->toBe(0);
});

it('prueft ohne Postausgang nur den Posteingang', function () {
    lesenGeht();
    Mailer::fake(fn () => throw new RuntimeException('haette nicht gefragt werden duerfen'));

    $this->postJson('/postfaecher/pruefen', kennwortFormular(['smtp_host' => null, 'smtp_port' => null]))
        ->assertOk()
        ->assertJsonPath('ok', true)
        ->assertJsonPath('senden', null);

    expect(MailAccount::query()->count())->toBe(0);
});

it('lehnt eine Adresse ab, die es schon gibt — auch in anderer Schreibweise', function () {
    lesenGeht();
    MailAccount::create(['email' => 'Info@Beispiel.de']);

    $this->postJson('/postfaecher', kennwortFormular())
        ->assertUnprocessable()
        ->assertJsonValidationErrors('email');

    expect(MailAccount::query()->count())->toBe(1);
});

it('laesst ein Gruppenpostfach nur mit Recht zu', function () {
    lesenGeht();

    $this->postJson('/postfaecher', kennwortFormular(['geteilt' => true]))->assertForbidden();
    $this->postJson('/postfaecher', kennwortFormular(['leser' => [4]]))->assertForbidden();

    expect(MailAccount::query()->count())->toBe(0);
});

it('legt mit Recht ein Gruppenpostfach samt Leseliste an', function () {
    lesenGeht();
    TestControllerFuerKontoAnlegen::$darfTeilen = true;

    $this->postJson('/postfaecher', kennwortFormular(['geteilt' => true, 'leser' => [3, 4]]))->assertCreated();

    $konto = MailAccount::query()->sole();

    expect($konto->field('user_id'))->toBeNull()
        ->and(DB::table('mail_account_user')->where('mail_account_id', $konto->getKey())->pluck('user_id')->sort()->values()->all())
        ->toBe([3, 4]);
});

it('verlangt fuer eine Leseliste ein Gruppenpostfach', function () {
    lesenGeht();
    TestControllerFuerKontoAnlegen::$darfTeilen = true;

    $this->postJson('/postfaecher', kennwortFormular(['leser' => [4]]))
        ->assertUnprocessable()
        ->assertJsonValidationErrors('leser');
});

it('legt nichts an, wenn die Postfaecher aus AI Brain kommen', function () {
    lesenGeht();
    config()->set('mailbox.store', 'brain');

    $this->postJson('/postfaecher', kennwortFormular())->assertStatus(409);
    $this->get('/postfaecher/oauth/microsoft')->assertStatus(409);

    expect(MailAccount::query()->count())->toBe(0);
});

/*
|--------------------------------------------------------------------------
| OAuth
|--------------------------------------------------------------------------
*/

it('schickt zu Microsoft mit der App der Anwendung und dem eingetragenen Rueckweg', function () {
    $antwort = $this->get('/postfaecher/oauth/microsoft?name=Anna');

    $ziel = $antwort->headers->get('Location');

    expect($ziel)->toStartWith('https://login.microsoftonline.com/common/oauth2/v2.0/authorize?')
        ->and($ziel)->toContain('client_id=app-pm')
        ->and($ziel)->toContain('redirect_uri='.urlencode('https://pm.beispiel.de/postfaecher/oauth/callback'))
        ->and($ziel)->toContain(urlencode('IMAP.AccessAsUser.All'));
});

it('bietet nur Anbieter mit eingetragener App an', function () {
    expect(OAuthVerbindung::verfuegbar())->toBe(['microsoft']);

    $this->get('/postfaecher/oauth/google')
        ->assertRedirect('/postfaecher')
        ->assertSessionHas('error');
});

it('legt beim ersten Verbinden ein persoenliches OAuth-Postfach an', function () {
    $state = verbindenBeginnen($this, '?name=Anna');
    anbieterAntwortet('anna@beispiel.de');

    $this->get('/postfaecher/oauth/callback?code=c0de&state='.$state)
        ->assertRedirect('/postfaecher')
        ->assertSessionHas('success');

    $konto = MailAccount::query()->sole();

    expect($konto->field('email'))->toBe('anna@beispiel.de')
        ->and($konto->field('label'))->toBe('Anna')
        ->and($konto->field('user_id'))->toBe(3)
        ->and($konto->field('auth_type'))->toBe('oauth')
        ->and($konto->field('provider'))->toBe('microsoft')
        ->and($konto->field('oauth_refresh_token'))->toBe('erneuerung-neu')
        ->and($konto->field('imap_host'))->toBe('outlook.office365.com');
});

it('verbindet ein vorhandenes Postfach NEU, statt eine zweite Zeile anzulegen', function () {
    // Das Postfach aus dem Vorfall: uebernommen, mit der App eines anderen
    // Systems und ohne Anmeldung.
    $alt = MailAccount::create([
        'email' => 'Office@Beispiel.de',
        'label' => 'Office',
        'user_id' => 3,
        'auth_type' => 'oauth',
        'provider' => 'office365',
        'oauth_client_id' => 'app-brain',
        'oauth_client_secret' => 'geheim-brain',
        'oauth_tenant_id' => 'mandant-brain',
        'is_active' => false,
    ]);

    $state = verbindenBeginnen($this, '?konto='.$alt->getKey());
    anbieterAntwortet('office@beispiel.de');

    $this->get('/postfaecher/oauth/callback?code=c0de&state='.$state)->assertSessionHas('success');

    expect(MailAccount::query()->count())->toBe(1);

    $konto = $alt->fresh();

    expect($konto->field('oauth_refresh_token'))->toBe('erneuerung-neu')
        ->and($konto->field('oauth_access_token'))->toBe('zugriff-neu')
        ->and($konto->field('label'))->toBe('Office')
        ->and($konto->field('is_active'))->toBeTrue()
        // Das neue Token gehoert der App der Anwendung — die fremde App am
        // Konto wuerde es bei der Erneuerung entwerten.
        ->and($konto->field('oauth_client_id'))->toBeNull()
        ->and($konto->field('oauth_client_secret'))->toBeNull()
        ->and($konto->field('oauth_tenant_id'))->toBeNull()
        ->and(TestControllerFuerKontoAnlegen::$angelegt)->toBe([['id' => $alt->getKey(), 'neu' => false]]);
});

it('nimmt die vorhandene Zeile auch ohne Kennung, wenn die Adresse schon da ist', function () {
    $alt = MailAccount::create(['email' => 'anna@beispiel.de', 'user_id' => 3, 'auth_type' => 'oauth']);

    $state = verbindenBeginnen($this);
    anbieterAntwortet('anna@beispiel.de');

    $this->get('/postfaecher/oauth/callback?code=c0de&state='.$state)->assertSessionHas('success');

    expect(MailAccount::query()->count())->toBe(1)
        ->and($alt->fresh()->field('oauth_refresh_token'))->toBe('erneuerung-neu');
});

it('lehnt eine Anmeldung mit einem anderen Konto als dem des Postfachs ab', function () {
    $alt = MailAccount::create(['email' => 'office@beispiel.de', 'user_id' => 3, 'auth_type' => 'oauth']);

    $state = verbindenBeginnen($this, '?konto='.$alt->getKey());
    anbieterAntwortet('jemand-anderes@beispiel.de');

    $this->get('/postfaecher/oauth/callback?code=c0de&state='.$state)->assertSessionHas('error');

    expect(MailAccount::query()->count())->toBe(1)
        ->and($alt->fresh()->field('oauth_refresh_token'))->toBeNull();
});

it('laesst ein fremdes persoenliches Postfach nicht neu verbinden', function () {
    $fremd = MailAccount::create(['email' => 'bert@beispiel.de', 'user_id' => 4, 'auth_type' => 'oauth']);

    $this->get('/postfaecher/oauth/microsoft?konto='.$fremd->getKey())->assertNotFound();

    // Auch nicht auf dem Umweg ueber die Adresse.
    $state = verbindenBeginnen($this);
    anbieterAntwortet('bert@beispiel.de');

    $this->get('/postfaecher/oauth/callback?code=c0de&state='.$state)->assertSessionHas('error');

    expect($fremd->fresh()->field('oauth_refresh_token'))->toBeNull()
        ->and(MailAccount::query()->count())->toBe(1);
});

it('weist einen Rueckweg mit fremdem state ab, ohne den Anbieter zu fragen', function () {
    verbindenBeginnen($this);
    Http::fake();

    $this->get('/postfaecher/oauth/callback?code=c0de&state=gefaelscht')->assertSessionHas('error');

    Http::assertNothingSent();
    expect(MailAccount::query()->count())->toBe(0);
});

it('speichert nichts, wenn der Anbieter kein Erneuerungs-Token schickt', function () {
    $state = verbindenBeginnen($this);
    Http::fake([
        'login.microsoftonline.com/*' => Http::response([
            'access_token' => 'zugriff',
            'expires_in' => 3600,
            'id_token' => idToken(['preferred_username' => 'anna@beispiel.de']),
        ]),
    ]);

    $this->get('/postfaecher/oauth/callback?code=c0de&state='.$state)->assertSessionHas('error');

    expect(MailAccount::query()->count())->toBe(0);
});

it('legt ein Gruppenpostfach ueber OAuth nur mit Recht an', function () {
    $this->get('/postfaecher/oauth/microsoft?geteilt=1')->assertForbidden();

    TestControllerFuerKontoAnlegen::$darfTeilen = true;
    $state = verbindenBeginnen($this, '?geteilt=1');
    anbieterAntwortet('team@beispiel.de');

    $this->get('/postfaecher/oauth/callback?code=c0de&state='.$state)->assertSessionHas('success');

    expect(MailAccount::query()->sole()->field('user_id'))->toBeNull();
});

it('nimmt den Rueckweg nur von der Person an, die ihn begonnen hat', function () {
    $state = verbindenBeginnen($this);
    anbieterAntwortet('anna@beispiel.de');

    // Dieselbe Sitzung, inzwischen jemand anderes angemeldet.
    TestControllerFuerKontoAnlegen::$ich = KontoAnlegenPerson::find(4);

    $this->get('/postfaecher/oauth/callback?code=c0de&state='.$state)->assertSessionHas('error');

    expect(MailAccount::query()->count())->toBe(0);
});
