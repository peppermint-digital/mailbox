<?php

namespace Peppermint\Mailbox\Accounts;

use Illuminate\Support\Facades\Http;
use Peppermint\Mailbox\Http\LegtKontenAn;
use RuntimeException;

/**
 * Holt die ERSTE Anmeldung eines OAuth-Postfachs — das Gegenstueck zu
 * {@see OAuthErneuerung}, die sie danach am Leben haelt.
 *
 * Zwei Schritte: {@see adresse()} schickt die Person zum Anbieter, und
 * {@see tauschen()} macht aus dem Code, mit dem sie zurueckkommt, die Tokens
 * und die Adresse des Postfachs.
 *
 * ## Welche App
 *
 * Immer die der Anwendung (`mailbox.oauth.<anbieter>`). Ein Erneuerungs-Token
 * gehoert zu der App, die es geholt hat — wer hier verbindet, muss deshalb die
 * App-Angaben am Konto LEEREN, sonst erneuert {@see OAuthErneuerung} ein Token
 * dieser App mit der App eines anderen Systems. Das erledigt der Aufrufer
 * ({@see LegtKontenAn}).
 *
 * ## Woher die Adresse kommt
 *
 * Aus dem `id_token`, das beide Anbieter zusammen mit den Tokens schicken,
 * wenn `openid email` erbeten wurde. Kein zweiter Aufruf an Graph oder
 * userinfo: Das Zugriffstoken gilt fuer IMAP und SMTP, nicht fuer Graph, und
 * ein Mischen der Ressourcen lehnt Microsoft ab. Die Signatur des `id_token`
 * wird nicht geprueft — es kam eben ueber TLS direkt vom Token-Endpunkt, nicht
 * ueber den Browser.
 */
class OAuthVerbindung
{
    /**
     * Dieselben Rechte, die die Erneuerung verlangt — plus `openid email
     * profile` fuer die Adresse.
     */
    private const MICROSOFT_SCOPE = 'offline_access https://outlook.office365.com/IMAP.AccessAsUser.All https://outlook.office365.com/SMTP.Send openid email profile';

    private const GOOGLE_SCOPE = 'https://mail.google.com/ openid email profile';

    /**
     * Die Anbieter, fuer die eine App eingetragen ist.
     *
     * @return list<string>
     */
    public static function verfuegbar(): array
    {
        return array_values(array_filter(
            ['microsoft', 'google'],
            fn (string $anbieter): bool => (string) config("mailbox.oauth.{$anbieter}.client_id", '') !== ''
                && (string) config("mailbox.oauth.{$anbieter}.client_secret", '') !== '',
        ));
    }

    public static function istVerfuegbar(string $anbieter): bool
    {
        return in_array($anbieter, self::verfuegbar(), true);
    }

    /**
     * Wohin die Person zum Anmelden geschickt wird.
     */
    public function adresse(string $anbieter, string $state, string $rueckweg, ?string $hinweis = null): string
    {
        $app = $this->app($anbieter);

        if ($anbieter === 'google') {
            return 'https://accounts.google.com/o/oauth2/v2/auth?'.http_build_query(array_filter([
                'client_id' => $app['client_id'],
                'response_type' => 'code',
                'redirect_uri' => $rueckweg,
                'scope' => self::GOOGLE_SCOPE,
                'state' => $state,
                // Ohne beides kommt beim zweiten Verbinden KEIN Erneuerungs-
                // Token mit, und das Postfach faellt nach einer Stunde aus.
                'access_type' => 'offline',
                'prompt' => 'consent',
                'login_hint' => $hinweis,
            ]));
        }

        return 'https://login.microsoftonline.com/'.rawurlencode($app['tenant_id']).'/oauth2/v2.0/authorize?'.http_build_query(array_filter([
            'client_id' => $app['client_id'],
            'response_type' => 'code',
            'redirect_uri' => $rueckweg,
            'response_mode' => 'query',
            'scope' => self::MICROSOFT_SCOPE,
            'state' => $state,
            'prompt' => 'select_account',
            'login_hint' => $hinweis,
        ]));
    }

    /**
     * Code gegen Tokens und Adresse.
     *
     * @return array{email: string, name: ?string, access_token: string, refresh_token: string, expires_in: int}
     */
    public function tauschen(string $anbieter, string $code, string $rueckweg): array
    {
        $app = $this->app($anbieter);

        $endpunkt = $anbieter === 'google'
            ? 'https://oauth2.googleapis.com/token'
            : 'https://login.microsoftonline.com/'.rawurlencode($app['tenant_id']).'/oauth2/v2.0/token';

        $felder = [
            'client_id' => $app['client_id'],
            'client_secret' => $app['client_secret'],
            'code' => $code,
            'redirect_uri' => $rueckweg,
            'grant_type' => 'authorization_code',
        ];

        if ($anbieter !== 'google') {
            $felder['scope'] = self::MICROSOFT_SCOPE;
        }

        $antwort = Http::asForm()->timeout(20)->post($endpunkt, $felder);

        $zugriff = $antwort->json('access_token');

        if (! $antwort->successful() || ! is_string($zugriff) || $zugriff === '') {
            // Nur der Fehlercode — die Antwort kann die Anfrage spiegeln.
            $fehler = $antwort->json('error');

            throw new RuntimeException(sprintf(
                'Die Anmeldung liess sich nicht abschliessen (%s, HTTP %d).',
                is_string($fehler) ? $fehler : 'ohne Fehlercode',
                $antwort->status(),
            ));
        }

        $erneuerung = $antwort->json('refresh_token');

        if (! is_string($erneuerung) || $erneuerung === '') {
            // Ohne Erneuerungs-Token geht das Postfach nach einer Stunde
            // nicht mehr — lieber jetzt sagen als dann.
            throw new RuntimeException('Der Anbieter hat kein Erneuerungs-Token geschickt. Bitte erneut verbinden und den Zugriff bestaetigen.');
        }

        $angaben = $this->angaben((string) $antwort->json('id_token', ''));
        $email = trim((string) ($angaben['email'] ?? $angaben['preferred_username'] ?? $angaben['upn'] ?? ''));

        if ($email === '' || ! str_contains($email, '@')) {
            throw new RuntimeException('Der Anbieter hat keine E-Mail-Adresse genannt.');
        }

        return [
            'email' => $email,
            'name' => isset($angaben['name']) && is_string($angaben['name']) ? $angaben['name'] : null,
            'access_token' => $zugriff,
            'refresh_token' => $erneuerung,
            'expires_in' => (int) ($antwort->json('expires_in') ?? 3600),
        ];
    }

    /**
     * @return array{client_id: string, client_secret: string, tenant_id: string}
     */
    private function app(string $anbieter): array
    {
        if (! self::istVerfuegbar($anbieter)) {
            throw new RuntimeException("Fuer {$anbieter} ist keine App eingetragen.");
        }

        $app = (array) config("mailbox.oauth.{$anbieter}", []);

        return [
            'client_id' => (string) $app['client_id'],
            'client_secret' => (string) $app['client_secret'],
            'tenant_id' => (string) (($app['tenant_id'] ?? '') ?: 'common'),
        ];
    }

    /**
     * Die Angaben im mittleren Teil des `id_token`.
     *
     * @return array<string, mixed>
     */
    private function angaben(string $idToken): array
    {
        $teile = explode('.', $idToken);

        if (count($teile) < 2) {
            return [];
        }

        $mitte = strtr($teile[1], '-_', '+/');
        $roh = base64_decode(str_pad($mitte, (int) ceil(strlen($mitte) / 4) * 4, '='), true);
        $angaben = is_string($roh) ? json_decode($roh, true) : null;

        return is_array($angaben) ? $angaben : [];
    }
}
