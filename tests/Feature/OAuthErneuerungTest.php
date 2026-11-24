<?php

use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Peppermint\Mailbox\Accounts\OAuthErneuerung;
use Peppermint\Mailbox\Contracts\TokenRefresher;
use Peppermint\Mailbox\Imap\MailboxClient;
use Peppermint\Mailbox\Mailboxes;
use Peppermint\Mailbox\Models\MailAccount;
use Peppermint\Mailbox\Sending\Mailer;
use Symfony\Component\Mailer\Transport\TransportInterface;

/**
 * Ein OAuth-Postfach aus der EIGENEN Tabelle bleibt erreichbar.
 *
 * Der Brain-Speicher liefert ein fertiges Zugriffstoken; die eigene Tabelle
 * haelt nur, was man zum Praegen braucht. Bis v0.213.0 band das Paket dafuer
 * nichts — jedes Office-365-Postfach endete in `MissingAccessToken`, sobald
 * eine Anwendung ihre Konten selbst fuehrte.
 */
uses(RefreshDatabase::class);

function oauthKonto(array $mehr = []): MailAccount
{
    return MailAccount::create($mehr + [
        'email' => 'postfach@beispiel.de',
        'auth_type' => 'oauth',
        'provider' => 'office365',
        'oauth_tenant_id' => 'mandant-1',
        'oauth_client_id' => 'app-1',
        'oauth_client_secret' => 'geheim-1',
        'oauth_refresh_token' => 'erneuerung-alt',
        'oauth_access_token' => null,
        'oauth_token_expires_at' => null,
    ]);
}

beforeEach(function () {
    Carbon::setTestNow('2026-11-24 12:00:00');
});

it('praegt ein Zugriffstoken mit den Angaben am Konto und schreibt es zurueck', function () {
    Http::fake(['login.microsoftonline.com/*' => Http::response([
        'access_token' => 'zugriff-neu',
        'refresh_token' => 'erneuerung-neu',
        'expires_in' => 3600,
    ])]);

    $konto = oauthKonto();
    (new OAuthErneuerung)->ensureFresh($konto);

    Http::assertSent(fn (Request $r): bool => $r->url() === 'https://login.microsoftonline.com/mandant-1/oauth2/v2.0/token'
        && $r['client_id'] === 'app-1'
        && $r['client_secret'] === 'geheim-1'
        && $r['refresh_token'] === 'erneuerung-alt'
        && $r['grant_type'] === 'refresh_token'
        && str_contains($r['scope'], 'IMAP.AccessAsUser.All')
        && str_contains($r['scope'], 'offline_access'));

    $frisch = $konto->fresh();
    expect($frisch->oauth_access_token)->toBe('zugriff-neu')
        ->and($frisch->oauth_refresh_token)->toBe('erneuerung-neu')
        ->and($frisch->oauth_token_expires_at->equalTo(now()->addSeconds(3600)))->toBeTrue()
        ->and($konto->field('oauth_access_token'))->toBe('zugriff-neu')
        ->and(DB::table('mail_accounts')->value('oauth_access_token'))->not->toBe('zugriff-neu');
});

it('nimmt die App-Registrierung aus der Konfiguration, wenn das Konto keine traegt', function () {
    config()->set('mailbox.oauth.microsoft', ['client_id' => 'app-conf', 'client_secret' => 'geheim-conf', 'tenant_id' => 'common']);
    Http::fake(['login.microsoftonline.com/*' => Http::response(['access_token' => 'a', 'expires_in' => 60])]);

    (new OAuthErneuerung)->ensureFresh(oauthKonto(['oauth_tenant_id' => null, 'oauth_client_id' => null, 'oauth_client_secret' => null]));

    Http::assertSent(fn (Request $r): bool => str_contains($r->url(), '/common/oauth2/v2.0/token')
        && $r['client_id'] === 'app-conf' && $r['client_secret'] === 'geheim-conf');
});

it('behaelt das alte Erneuerungs-Token, wenn der Anbieter kein neues schickt', function () {
    Http::fake(['login.microsoftonline.com/*' => Http::response(['access_token' => 'a', 'expires_in' => 60])]);

    $konto = oauthKonto();
    (new OAuthErneuerung)->ensureFresh($konto);

    expect($konto->fresh()->oauth_refresh_token)->toBe('erneuerung-alt');
});

it('erneuert ueber Google, wenn das Konto ein Google-Konto ist', function () {
    Http::fake(['oauth2.googleapis.com/*' => Http::response(['access_token' => 'g', 'expires_in' => 60])]);

    (new OAuthErneuerung)->ensureFresh(oauthKonto(['provider' => 'google', 'oauth_tenant_id' => null]));

    Http::assertSent(fn (Request $r): bool => $r->url() === 'https://oauth2.googleapis.com/token');
});

it('liest den Anbieter auch aus oauth_provider', function () {
    Http::fake(['oauth2.googleapis.com/*' => Http::response(['access_token' => 'g', 'expires_in' => 60])]);

    // Die Paket-Tabelle kennt die Spalte nicht, eine Produkttabelle schon —
    // gelesen wird nur, gespeichert erst nach der Antwort ohne dieses Feld.
    $konto = oauthKonto(['provider' => 'imap', 'oauth_tenant_id' => null]);
    $konto->setAttribute('oauth_provider', 'google');

    try {
        (new OAuthErneuerung)->ensureFresh($konto);
    } catch (QueryException) {
        // Das Zurueckschreiben scheitert an der fehlenden Spalte; der Weg
        // zum Anbieter ist bis dahin entschieden.
    }

    Http::assertSent(fn (Request $r): bool => $r->url() === 'https://oauth2.googleapis.com/token');
});

it('meldet eine Absage, ohne Geheimnisse zu nennen und ohne etwas zu ueberschreiben', function () {
    Http::fake(['login.microsoftonline.com/*' => Http::response(['error' => 'invalid_grant'], 400)]);

    $konto = oauthKonto(['oauth_access_token' => 'zugriff-alt', 'oauth_token_expires_at' => now()->subMinute()]);

    expect(fn () => (new OAuthErneuerung)->ensureFresh($konto))
        ->toThrow(RuntimeException::class, 'invalid_grant');

    try {
        (new OAuthErneuerung)->ensureFresh($konto);
    } catch (RuntimeException $e) {
        expect($e->getMessage())->not->toContain('erneuerung-alt')->not->toContain('geheim-1');
    }

    expect($konto->fresh()->oauth_refresh_token)->toBe('erneuerung-alt')
        ->and($konto->fresh()->oauth_access_token)->toBe('zugriff-alt');
});

it('fasst ein Konto aus der Mitte nicht an', function () {
    Http::fake();

    (new OAuthErneuerung)->ensureFresh(MailAccount::fromRemote([
        'id' => 4, 'email' => 'x@beispiel.de', 'auth_type' => 'oauth', 'oauth_refresh_token' => 'r',
    ]));

    Http::assertNothingSent();
});

it('nimmt ein inzwischen von nebenan erneuertes Token, statt noch einmal zu erneuern', function () {
    // Zwei Anfragen erneuern dasselbe Konto. Die zweite darf das rotierte
    // Token der ersten nicht mit dem alten verbrauchen.
    Http::fake();

    $veraltet = oauthKonto();
    MailAccount::query()->whereKey($veraltet->id)->first()
        ->forceFill(['oauth_access_token' => 'von-nebenan', 'oauth_token_expires_at' => now()->addHour()])
        ->save();

    (new OAuthErneuerung)->ensureFresh($veraltet);

    Http::assertNothingSent();
    expect($veraltet->field('oauth_access_token'))->toBe('von-nebenan');
});

it('haelt ein OAuth-Konto ohne Zugriffstoken fuer erneuerungsbeduerftig', function () {
    expect(oauthKonto()->brauchtFrischesToken())->toBeTrue()
        ->and(oauthKonto(['oauth_access_token' => 't', 'oauth_token_expires_at' => now()->addHour()])->brauchtFrischesToken())->toBeFalse()
        ->and(MailAccount::create(['email' => 'p@beispiel.de'])->brauchtFrischesToken())->toBeFalse();
});

it('erneuert vor dem Verbinden auch dann, wenn noch nie ein Token da war', function () {
    $spion = new class implements TokenRefresher
    {
        public int $aufrufe = 0;

        public function ensureFresh(MailAccount $account): void
        {
            $this->aufrufe++;
            $account->forceFill(['oauth_access_token' => 'frisch', 'oauth_token_expires_at' => now()->addHour()]);
        }
    };

    $client = new MailboxClient(
        account: oauthKonto(['imap_host' => 'imap.beispiel.de', 'imap_port' => 993]),
        refresher: $spion,
        connector: fn (array $werte) => throw new RuntimeException('verbunden mit '.$werte['password']),
    );

    expect(fn () => $client->folders())->toThrow(RuntimeException::class, 'verbunden mit frisch')
        ->and($spion->aufrufe)->toBe(1);
});

it('gibt jedem Postfach den angebundenen Erneuerer mit', function () {
    $client = Mailboxes::for(oauthKonto());

    $feld = (new ReflectionClass($client))->getProperty('refresher');

    expect($feld->getValue($client))->toBeInstanceOf(TokenRefresher::class)
        ->and(app(TokenRefresher::class))->toBeInstanceOf(OAuthErneuerung::class);
});

it('erneuert auch vor dem Versand ueber SMTP', function () {
    $spion = new class implements TokenRefresher
    {
        public int $aufrufe = 0;

        public function ensureFresh(MailAccount $account): void
        {
            $this->aufrufe++;
            $account->forceFill(['oauth_access_token' => 'frisch', 'oauth_token_expires_at' => now()->addHour()]);
        }
    };
    app()->instance(TokenRefresher::class, $spion);

    $transport = (new Mailer)->transportFor(oauthKonto(['smtp_host' => 'smtp.beispiel.de', 'smtp_port' => 587]));

    expect($spion->aufrufe)->toBe(1)
        ->and($transport)->toBeInstanceOf(TransportInterface::class);
});
