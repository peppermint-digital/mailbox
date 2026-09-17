<?php

use Peppermint\Mailbox\Imap\MailboxClient;
use Peppermint\Mailbox\Imap\RetryPolicy;
use Peppermint\Mailbox\Models\MailAccount;

/**
 * Die restlichen Verben aus #5844: Sonderordner, Zählen, Archivieren,
 * Token-Suche, Verbindungstest.
 *
 * Jedes lag nur im Manager. Was hier geprüft wird, ist nicht die Mechanik —
 * die ist klein — sondern die Entscheidungen: Kennzeichnung schlägt Namen,
 * schon archiviert ist kein Fehlschlag, und die Volltextsuche allein
 * entscheidet nichts.
 */
function verbKlient(array $ordner): MailboxClient
{
    return new MailboxClient(
        account: MailAccount::fromRemote([
            'id' => 1, 'email' => 'post@example.test', 'password' => 'geheim',
            'imap_host' => 'imap.example.test', 'auth_type' => 'password',
            'oauth_access_token' => null, 'oauth_token_expires_at' => null,
        ]),
        retry: new RetryPolicy(maxRetries: 1, sleeper: fn () => null, jitter: fn () => 0.0),
        connector: fn () => suchPostfach($ordner),
    );
}

describe('Sonderordner (IMAP)', function () {
    it('nimmt die Kennzeichnung, nicht den Namen', function () {
        // Ein Ordner „Ablage" mit \Archive ist das Archiv — auch wenn weiter
        // oben einer steht, der zufaellig „Archiv" heisst.
        $klient = verbKlient([
            suchOrdner('Archiv-alt', []),
            suchOrdner('Ablage', ['\\Archive']),
        ]);

        expect($klient->specialFolder('Archive'))->toBe('Ablage');
    });

    it('faellt auf den Namen zurueck, wenn nichts gekennzeichnet ist', function () {
        $klient = verbKlient([suchOrdner('INBOX', []), suchOrdner('Gesendete Elemente', [])]);

        expect($klient->specialFolder('Sent'))->toBe('Gesendete Elemente');
    });

    it('ist null, wenn es ihn nicht gibt', function () {
        expect(verbKlient([suchOrdner('INBOX', [])])->specialFolder('Archive'))->toBeNull();
    });
});

describe('Zaehlen seit (IMAP)', function () {
    it('zaehlt nur, was neuer ist', function () {
        $klient = verbKlient([suchOrdner('INBOX', [], [
            kettenNachricht(1, 'neu', '2026-09-05T10:00:00+00:00'),
            kettenNachricht(2, 'auch neu', '2026-09-04T10:00:00+00:00'),
            kettenNachricht(3, 'alt', '2026-09-01T10:00:00+00:00'),
        ])]);

        $anzahl = $klient->countNewSince('INBOX', new DateTimeImmutable('2026-09-03T00:00:00+00:00'));

        expect($anzahl)->toBe(2);
    });

    it('antwortet auf einen unbekannten Ordner mit null Treffern', function () {
        expect(verbKlient([suchOrdner('INBOX', [])])->countNewSince('Weg', new DateTimeImmutable('2026-01-01')))
            ->toBe(0);
    });
});

describe('Archivieren (IMAP)', function () {
    it('zaehlt schon Archiviertes als erledigt, nicht als Fehlschlag', function () {
        // Sonst sieht ein zweiter Klick kaputt aus.
        $klient = verbKlient([suchOrdner('Archiv', ['\\Archive'])]);

        expect($klient->archive('Archiv', [1, 2, 3]))->toBe(['archived' => 3, 'failed' => 0]);
    });

    it('meldet alles als gescheitert, wenn es gar kein Archiv gibt', function () {
        // Ehrlicher als ein stilles „erledigt" auf einem Postfach ohne Archiv.
        $klient = verbKlient([suchOrdner('INBOX', ['\\Inbox'])]);

        expect($klient->archive('INBOX', [1, 2]))->toBe(['archived' => 0, 'failed' => 2]);
    });

    it('fragt bei einer leeren Auswahl gar nicht erst nach', function () {
        $klient = verbKlient([]);

        expect($klient->archive('INBOX', []))->toBe(['archived' => 0, 'failed' => 0]);
    });
});

describe('Token-Suche (IMAP)', function () {
    it('entscheidet an der Message-ID, nicht an der Volltextsuche', function () {
        // Die Volltextsuche ist alles, was IMAP hat — sie trifft auch eine
        // Mail, die den Token nur zitiert. Die waere hier ein Fehlgriff.
        $token = 'abc-123-token';
        $klient = verbKlient([suchOrdner('Sent', ['\\Sent'], [
            kettenNachricht(7, 'Probe', '2026-09-05T10:00:00+00:00', "<probe.{$token}@example.test>"),
            kettenNachricht(8, 'Zitiert nur', '2026-09-05T11:00:00+00:00', '<echt@example.test>'),
        ])]);

        expect($klient->findByToken('Sent', $token))->toBe([7]);
    });
});

describe('Verbindungstest', function () {
    it('sagt bei Erfolg, was gefunden wurde', function () {
        $ergebnis = verbKlient([suchOrdner('INBOX', []), suchOrdner('Sent', [])])->probe();

        expect($ergebnis['ok'])->toBeTrue()
            ->and($ergebnis['message'])->toContain('2 Ordner');
    });

    it('gibt den Wortlaut des Servers weiter, statt ihn auszulegen', function () {
        // „Nein" ohne Grund schickt jemanden ins falsche Feld.
        $klient = new MailboxClient(
            account: MailAccount::fromRemote([
                'id' => 1, 'email' => 'post@example.test', 'password' => 'falsch',
                'imap_host' => 'imap.example.test', 'auth_type' => 'password',
                'oauth_access_token' => null, 'oauth_token_expires_at' => null,
            ]),
            retry: new RetryPolicy(maxRetries: 0, sleeper: fn () => null, jitter: fn () => 0.0),
            connector: fn () => throw new RuntimeException('AUTHENTICATIONFAILED Invalid credentials'),
        );

        $ergebnis = $klient->probe();

        expect($ergebnis['ok'])->toBeFalse()
            ->and($ergebnis['message'])->toContain('AUTHENTICATIONFAILED');
    });
});

describe('dieselben Verben ueber JMAP', function () {
    it('findet den Sonderordner an der Rolle', function () {
        expect(jmapKlient()->specialFolder('Sent'))->toBe('Sent Items');
    });

    it('laesst den Server zaehlen — aber nicht ueber die Obergrenze hinaus', function () {
        // Ein Produkt, das ueber IMAP hoechstens `scan` bekommt, darf ueber
        // JMAP nicht ploetzlich eine andere Zahl sehen.
        $klient = jmapKlient([
            'Mailbox/get' => [jmapOrdner()],
            'Email/query' => [['Email/query', ['total' => 999], 'c0']],
        ]);

        expect($klient->batch(fn ($p) => $p->countNewSince('Inbox', new DateTimeImmutable('2026-09-01'), scan: 50)))
            ->toBe(50);
    });

    it('archiviert mehrere in EINEM Aufruf', function () {
        $klient = jmapKlient([
            'Mailbox/get' => [jmapOrdner()],
            'Email/set' => [['Email/set', ['updated' => ['m1' => null, 'm2' => null]], 's0']],
        ], $protokoll);

        // „Archives" traegt keine Rolle im Fake — der Namensrueckfall greift.
        $ergebnis = $klient->batch(fn ($p) => $p->archive('Inbox', ['m1', 'm2']));

        $aufrufe = collect($protokoll)->last()['payload']['methodCalls'];

        expect($ergebnis)->toBe(['archived' => 2, 'failed' => 0])
            ->and($aufrufe)->toHaveCount(1)
            ->and(array_keys($aufrufe[0][1]['update']))->toBe(['m1', 'm2']);
    });

    it('meldet einen Verbindungsfehler mit dem Wortlaut des Servers', function () {
        $klient = jmapKlient(['Mailbox/get' => [['error', ['type' => 'accountNotFound'], 'f0']]]);

        $ergebnis = $klient->probe();

        expect($ergebnis['ok'])->toBeFalse()
            ->and($ergebnis['message'])->toContain('accountNotFound');
    });
});
