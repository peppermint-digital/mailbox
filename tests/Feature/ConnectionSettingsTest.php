<?php

use Peppermint\Mailbox\Exceptions\MissingAccessToken;
use Peppermint\Mailbox\Imap\ConnectionSettings;
use Peppermint\Mailbox\Models\MailAccount;

function konto(array $werte = []): MailAccount
{
    return MailAccount::fromRemote(array_merge([
        'id' => 1,
        'email' => 'post@beispiel.de',
        'username' => null,
        'password' => 'geheim',
        'host' => 'imap.beispiel.de',
        'port' => 993,
        'encryption' => 'ssl',
        'auth_type' => 'password',
        'oauth_access_token' => null,
        'oauth_token_expires_at' => null,
    ], $werte));
}

describe('Zugangsdaten fuer eine Verbindung', function () {
    it('nimmt bei Passwort-Konten Benutzernamen und Kennwort', function () {
        $w = ConnectionSettings::for(konto(['username' => 'post']))->values;

        expect($w['username'])->toBe('post');
        expect($w['password'])->toBe('geheim');
        expect($w)->not->toHaveKey('authentication');
    });

    it('faellt auf die Adresse zurueck, wenn kein Benutzername eingetragen ist', function () {
        // Viele Anbieter nehmen die Adresse; ein leeres Feld ist kein Benutzername.
        $w = ConnectionSettings::for(konto(['username' => null]))->values;

        expect($w['username'])->toBe('post@beispiel.de');
    });

    it('nimmt bei OAuth die ADRESSE als Identitaet, nicht den Benutzernamen', function () {
        $w = ConnectionSettings::for(konto([
            'auth_type' => 'oauth',
            'username' => 'etwas-anderes',
            'oauth_access_token' => 'tok123',
        ]))->values;

        expect($w['username'])->toBe('post@beispiel.de');
    });

    it('schickt bei OAuth das Token als Kennwort UND sagt dem Server Bescheid', function () {
        // Ohne `authentication: oauth` liest der Server das Token als Passwort
        // und lehnt die Anmeldung ab — mit einer Meldung, in der nichts auf
        // OAuth hinweist.
        $w = ConnectionSettings::for(konto([
            'auth_type' => 'oauth',
            'oauth_access_token' => 'tok123',
        ]))->values;

        expect($w['password'])->toBe('tok123');
        expect($w['authentication'])->toBe('oauth');
    });

    it('traegt Server, Port und Verschluesselung unveraendert weiter', function () {
        $w = ConnectionSettings::for(konto(['host' => 'mail.example.org', 'port' => 143, 'encryption' => 'tls']))->values;

        expect($w['host'])->toBe('mail.example.org');
        expect($w['port'])->toBe(143);
        expect($w['encryption'])->toBe('tls');
    });

    it('prueft das Zertifikat immer', function () {
        expect(ConnectionSettings::for(konto())->values['validate_cert'])->toBeTrue();
    });

    it('nimmt die uebergebene Zeitgrenze', function () {
        expect(ConnectionSettings::for(konto(), timeout: 30)->values['timeout'])->toBe(30);
    });
});

describe('wie die Server-Felder heissen', function () {
    it('nimmt imap_host, imap_port und imap_encryption, wenn es sie gibt', function () {
        // Ein Postfach hat einen IMAP- UND einen SMTP-Server. Beide Abnehmer
        // dieses Pakets und AI Brain benennen sie getrennt; wer nur `host`
        // liest, verbindet sich gegen einen leeren String.
        $w = ConnectionSettings::for(konto([
            'imap_host' => 'imap.example.org',
            'imap_port' => 143,
            'imap_encryption' => 'tls',
            'host' => null, 'port' => null, 'encryption' => null,
        ]))->values;

        expect($w['host'])->toBe('imap.example.org');
        expect($w['port'])->toBe(143);
        expect($w['encryption'])->toBe('tls');
    });

    it('faellt auf die schlichten Namen zurueck, wo es keine getrennten gibt', function () {
        $w = ConnectionSettings::for(konto(['host' => 'mail.example.org', 'port' => 993, 'encryption' => 'ssl']))->values;

        expect($w['host'])->toBe('mail.example.org');
    });

    it('zieht die IMAP-Angabe vor, wenn beide da sind', function () {
        $w = ConnectionSettings::for(konto([
            'host' => 'smtp.falsch.de', 'imap_host' => 'imap.richtig.de',
        ]))->values;

        expect($w['host'])->toBe('imap.richtig.de');
    });
});

describe('wann ein Token erneuert werden muss', function () {
    it('sagt nein bei Passwort-Konten', function () {
        expect(konto()->isTokenExpiringSoon())->toBeFalse();
    });

    it('sagt nein, solange reichlich Zeit bleibt', function () {
        expect(konto([
            'auth_type' => 'oauth',
            'oauth_token_expires_at' => now()->addHour()->toIso8601String(),
        ])->isTokenExpiringSoon())->toBeFalse();
    });

    it('sagt JA schon kurz vor Ablauf, nicht erst danach', function () {
        // Ein Token, das noch zehn Sekunden gilt, ist fuer einen Aufruf,
        // der zwoelf braucht, wertlos.
        expect(konto([
            'auth_type' => 'oauth',
            'oauth_token_expires_at' => now()->addMinutes(2)->toIso8601String(),
        ])->isTokenExpiringSoon())->toBeTrue();
    });

    it('sagt nein, wenn gar kein Ablauf bekannt ist', function () {
        expect(konto(['auth_type' => 'oauth', 'oauth_token_expires_at' => null])->isTokenExpiringSoon())->toBeFalse();
    });
});

describe('woran OAuth erkannt wird', function () {
    it('liest die EINSTELLUNG, nicht das Vorhandensein eines Tokens', function () {
        // Ein Postfach, dessen Zustimmung zurueckgezogen wurde, hat die alten
        // Tokens noch in der Zeile. Daraus OAuth zu schliessen hiesse, eine
        // Anmeldung zu versuchen, die nicht mehr klappen kann.
        expect(konto(['auth_type' => 'password', 'oauth_access_token' => 'altes-token'])->usesOAuth())->toBeFalse();
        expect(konto(['auth_type' => 'oauth', 'oauth_access_token' => null])->usesOAuth())->toBeTrue();
    });
});


describe('ein OAuth-Postfach ohne Token', function () {
    it('bricht ab, statt mit leerem Passwort zu verbinden', function () {
        // Sonst schickt der Client ein leeres Kennwort, der Server lehnt die
        // Anmeldung ab, und in der Meldung steht nichts von OAuth — wer sie
        // liest, sucht ein falsches Passwort, das es nicht gibt.
        expect(fn () => ConnectionSettings::for(konto([
            'auth_type' => 'oauth',
            'oauth_access_token' => null,
            'oauth_refresh_token' => 'r123',
            'oauth_client_id' => 'c123',
        ])))->toThrow(MissingAccessToken::class);
    });

    it('sagt in der Meldung, WAS die Einstellungen stattdessen enthalten', function () {
        // Damit erkennbar ist, dass ein Erneuerer fehlt — und nicht das Konto.
        try {
            ConnectionSettings::for(konto([
                'auth_type' => 'oauth',
                'oauth_access_token' => null,
                'oauth_refresh_token' => 'r123',
                'oauth_client_id' => 'c123',
            ]));
            expect(false)->toBeTrue();
        } catch (MissingAccessToken $e) {
            expect($e->getMessage())->toContain('oauth_refresh_token');
            expect($e->getMessage())->toContain('oauth_client_id');
            expect($e->getMessage())->toContain('TokenRefresher');
        }
    });

    it('sagt es auch, wenn gar keine OAuth-Felder da sind', function () {
        try {
            ConnectionSettings::for(konto(['auth_type' => 'oauth', 'oauth_access_token' => null]));
            expect(false)->toBeTrue();
        } catch (MissingAccessToken $e) {
            expect($e->getMessage())->toContain('no OAuth fields at all');
        }
    });

    it('laesst ein Passwort-Postfach ohne Token in Ruhe', function () {
        expect(ConnectionSettings::for(konto())->values['password'])->toBe('geheim');
    });
});
