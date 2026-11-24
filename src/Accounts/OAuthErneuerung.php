<?php

namespace Peppermint\Mailbox\Accounts;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Peppermint\Mailbox\Contracts\TokenRefresher;
use Peppermint\Mailbox\Models\MailAccount;
use RuntimeException;

/**
 * Haelt ein OAuth-Postfach aus der EIGENEN Tabelle erreichbar.
 *
 * Der Brain-Speicher liefert ein fertiges Zugriffstoken und erneuert selbst.
 * Fuehrt eine Anwendung ihre Konten selbst, haelt die Zeile nur, was man zum
 * Praegen braucht: Mandant, App, Geheimnis, Erneuerungs-Token. Bis v0.213.0
 * band das Paket dafuer nichts, und jedes Office-365-Postfach endete in
 * `MissingAccessToken`.
 *
 * ## Woher die App-Registrierung kommt
 *
 * Zuerst vom Konto: Ein Erneuerungs-Token gehoert zu der App, die es geholt
 * hat. Ein aus AI Brain uebernommenes Postfach bringt deshalb seine eigene
 * App-Angabe mit — mit einer anderen App waere sein Token wertlos. Fehlt sie
 * am Konto, gilt `mailbox.oauth.<anbieter>` (die App der Anwendung). Dieselbe
 * Reihenfolge benutzt AI Brain.
 *
 * ## Warum gesperrt und neu gelesen wird
 *
 * Microsoft rotiert Erneuerungs-Tokens. Erneuern zwei Anfragen gleichzeitig,
 * verbraucht die zweite das alte Token, waehrend die erste schon das neue
 * gespeichert hat. Unter der Sperre wird die Zeile deshalb frisch gelesen: Hat
 * jemand anderes inzwischen erneuert, wird dessen Ergebnis uebernommen.
 *
 * ## Was nicht angefasst wird
 *
 * Ein Konto aus der Mitte (`fromRemote`) — dort erneuert die Mitte, und ein
 * zweiter Erneuerer entwertete ihr Token.
 */
class OAuthErneuerung implements TokenRefresher
{
    private const MICROSOFT_SCOPE = 'offline_access https://outlook.office365.com/IMAP.AccessAsUser.All https://outlook.office365.com/SMTP.Send';

    public function ensureFresh(MailAccount $account): void
    {
        if ($account->isRemote() || ! $account->exists || ! $account->usesOAuth()) {
            return;
        }

        Cache::lock('mailbox.oauth.'.$account->getKey(), 30)->block(20, function () use ($account): void {
            $zeile = MailAccount::query()->find($account->getKey());

            if ($zeile !== null && ! $zeile->brauchtFrischesToken()) {
                $this->uebernehmen($account, $zeile);

                return;
            }

            $this->erneuern($account);
        });
    }

    private function erneuern(MailAccount $konto): void
    {
        $erneuerung = (string) $konto->field('oauth_refresh_token');

        if ($erneuerung === '') {
            throw new RuntimeException('Dieses Postfach hat keine OAuth-Anmeldung. Es muss einmal neu verbunden werden.');
        }

        $anbieter = $this->anbieter($konto);
        $app = (array) config("mailbox.oauth.{$anbieter}", []);
        $clientId = (string) ($konto->field('oauth_client_id') ?: ($app['client_id'] ?? ''));
        $geheimnis = (string) ($konto->field('oauth_client_secret') ?: ($app['client_secret'] ?? ''));

        if ($anbieter === 'google') {
            $adresse = 'https://oauth2.googleapis.com/token';
            $felder = [];
        } else {
            $mandant = (string) ($konto->field('oauth_tenant_id') ?: ($app['tenant_id'] ?? 'common'));
            $adresse = 'https://login.microsoftonline.com/'.rawurlencode($mandant).'/oauth2/v2.0/token';
            $felder = ['scope' => self::MICROSOFT_SCOPE];
        }

        $antwort = Http::asForm()->timeout(20)->post($adresse, $felder + array_filter([
            'client_id' => $clientId,
            'client_secret' => $geheimnis,
            'refresh_token' => $erneuerung,
            'grant_type' => 'refresh_token',
        ], fn (string $wert): bool => $wert !== ''));

        $token = $antwort->json('access_token');

        if (! $antwort->successful() || ! is_string($token) || $token === '') {
            // Nur der Fehlercode des Anbieters — die Antwort kann Teile der
            // Anfrage spiegeln, und darin stehen Geheimnisse.
            $code = $antwort->json('error');

            throw new RuntimeException(sprintf(
                'Das OAuth-Token fuer Postfach #%s liess sich nicht erneuern (%s, HTTP %d).',
                $konto->getKey(),
                is_string($code) ? $code : 'ohne Fehlercode',
                $antwort->status(),
            ));
        }

        $werte = [
            MailAccount::column('oauth_access_token') => $token,
            MailAccount::column('oauth_token_expires_at') => now()->addSeconds((int) ($antwort->json('expires_in') ?? 3600)),
        ];

        $neueErneuerung = $antwort->json('refresh_token');

        if (is_string($neueErneuerung) && $neueErneuerung !== '') {
            $werte[MailAccount::column('oauth_refresh_token')] = $neueErneuerung;
        }

        $konto->forceFill($werte)->save();
    }

    private function uebernehmen(MailAccount $konto, MailAccount $zeile): void
    {
        foreach (['oauth_access_token', 'oauth_token_expires_at', 'oauth_refresh_token'] as $feld) {
            $spalte = MailAccount::column($feld);
            $konto->setAttribute($spalte, $zeile->getAttribute($spalte));
        }

        $konto->syncOriginal();
    }

    /**
     * Microsoft oder Google — gelesen aus `oauth_provider`, sonst `provider`.
     *
     * Die Produkte benennen dasselbe verschieden (`office365`, `microsoft`,
     * `outlook`). Was nicht eindeutig Google ist, geht an Microsoft: Das ist
     * der Anbieter mit Mandant, und ein Mandant am Konto sagt es ohnehin.
     */
    private function anbieter(MailAccount $konto): string
    {
        // Dieselbe Reihenfolge wie im Versand ({@see \Peppermint\Mailbox\Sending\Mailer}):
        // Manche Produkte nennen den Anbieter `oauth_provider`, weil `provider`
        // dort schon „welche Art Postfach" heisst.
        $art = mb_strtolower((string) ($konto->field('oauth_provider') ?: $konto->field('provider')));

        return in_array($art, ['google', 'gmail', 'googlemail'], true) ? 'google' : 'microsoft';
    }
}
