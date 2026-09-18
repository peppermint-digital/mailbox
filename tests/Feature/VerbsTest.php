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
/** Wie verbKlient, aber mit dem losen Formatierer — fuer Zeilen statt Zustand. */
function verbKlientMitFormatierer(array $ordner): MailboxClient
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

describe('alle Anhaenge auf einmal', function () {
    it('laesst Inline-BILDER weg, aber nicht ein inline PDF', function () {
        // Das Bild steht schon im Rumpf; es noch einmal anzuhaengen wuerde es
        // beim Weiterleiten verdoppeln. Ein PDF ist eine Datei, die jemand
        // angehaengt hat — egal was die Disposition sagt.
        $mail = [
            'id' => 'm1',
            'attachments' => [
                ['blobId' => 'b1', 'name' => 'logo.png', 'type' => 'image/png', 'cid' => 'logo@x'],
                ['blobId' => 'b2', 'name' => 'Vertrag.pdf', 'type' => 'application/pdf', 'cid' => 'v@x'],
                ['blobId' => 'b3', 'name' => 'Angebot.pdf', 'type' => 'application/pdf', 'disposition' => 'attachment'],
            ],
        ];

        $klient = jmapKlient(['Email/get' => [['Email/get', ['list' => [$mail]], 'e0']], 'blob' => 'BYTES']);

        $anhaenge = $klient->attachments('Inbox', 'm1');

        expect(array_column($anhaenge, 'filename'))->toBe(['Vertrag.pdf', 'Angebot.pdf'])
            ->and($anhaenge[0]['mime_type'])->toBe('application/pdf')
            ->and($anhaenge[0]['contents'])->toBe('BYTES');
    });

    it('ist leer, wenn es die Nachricht nicht mehr gibt', function () {
        $klient = jmapKlient(['Email/get' => [['Email/get', ['list' => []], 'e0']]]);

        expect($klient->attachments('Inbox', 'weg'))->toBe([]);
    });
});

describe('eine Nachricht ablegen', function () {
    it('laedt sie hoch und legt sie dann ab — zwei Schritte, ein Verb', function () {
        // JMAP trennt beides. Genau deshalb gibt es das Verb: Ein Produkt soll
        // davon nichts wissen muessen.
        $klient = jmapKlient([
            'Mailbox/get' => [jmapOrdner()],
            'upload' => ['blobId' => 'blob-neu'],
            'Email/import' => [['Email/import', ['created' => ['neu' => ['id' => 'm-neu']]], 'i0']],
        ], $protokoll);

        $kennung = $klient->batch(fn ($p) => $p->append('Sent Items', "From: a@b\r\n\r\nText", ['\\Seen']));

        $hochladen = collect($protokoll)->first(fn (array $a): bool => $a['method'] === 'POST_RAW');
        $ablegen = collect($protokoll)->last()['payload']['methodCalls'][0][1];

        expect($kennung)->toBe('m-neu')
            ->and($hochladen['url'])->toContain('/jmap/upload/c/')
            ->and($hochladen['payload']['__raw'])->toContain('From: a@b')
            ->and($ablegen['emails']['neu']['blobId'])->toBe('blob-neu')
            ->and($ablegen['emails']['neu']['mailboxIds'])->toBe(['e' => true])
            // IMAP-Kennzeichen werden uebersetzt — der Aufrufer schreibt
            // weiter \Seen.
            ->and($ablegen['emails']['neu']['keywords'])->toBe(['$seen' => true]);
    });

    it('macht aus einer Absage beim Ablegen keinen stillen Erfolg', function () {
        $klient = jmapKlient([
            'Mailbox/get' => [jmapOrdner()],
            'Email/import' => [['Email/import', ['notCreated' => ['neu' => ['type' => 'tooLarge']]], 'i0']],
        ]);

        expect(fn () => $klient->batch(fn ($p) => $p->append('Sent Items', 'Text')))
            ->toThrow(RuntimeException::class, 'tooLarge');
    });

    it('legt nicht in einen Ordner ab, den es nicht gibt', function () {
        // Ohne das laege die gesendete Kopie irgendwo — oder nirgends.
        expect(fn () => jmapKlient()->append('Gibt-Es-Nicht', 'Text'))
            ->toThrow(RuntimeException::class, 'Folder not found');
    });
});

describe('endgueltig loeschen', function () {
    it('nimmt nicht den Umweg ueber den Papierkorb', function () {
        // Fuer den ersetzten Entwurf: Ein Papierkorb, der sich mit jeder
        // Zwischenfassung fuellt, ist auch nicht das Gewuenschte.
        $klient = jmapKlient([
            'Mailbox/get' => [jmapOrdner()],
            'Email/set' => [['Email/set', ['destroyed' => ['m1']], 's0']],
        ], $protokoll);

        expect($klient->purge('Drafts', 'm1'))->toBeTrue();

        $aufruf = collect($protokoll)->last()['payload']['methodCalls'][0][1];

        expect($aufruf['destroy'])->toBe(['m1'])
            ->and($aufruf)->not->toHaveKey('update');
    });

    it('antwortet mit false, wenn die Nachricht schon weg ist', function () {
        $klient = jmapKlient(['Email/set' => [['Email/set', [
            'destroyed' => [],
            'notDestroyed' => ['m1' => ['type' => 'notFound']],
        ], 's0']]]);

        expect($klient->purge('Drafts', 'm1'))->toBeFalse();
    });
});

describe('was seit dem letzten Lauf dazukam', function () {
    it('grenzt ueber IMAP auf den uid-Bereich danach ein', function () {
        // Eine hoehere uid heisst: Der Server hat sie spaeter gesehen. Das ist
        // NICHT dasselbe wie ein spaeteres Datum — und fuer einen Index genau
        // das Richtige.
        $ordner = suchOrdner('INBOX', [], [
            kettenNachricht(11, 'neu', '2026-09-05T10:00:00+00:00', '<a@x>'),
        ]);

        $zeilen = verbKlientMitFormatierer([$ordner])->newerThan('INBOX', 10, 50);

        expect($ordner->gesucht['uid'] ?? null)->toBe([11, INF])
            ->and($zeilen)->toHaveCount(1)
            // Kein IMAP-Objekt nach draussen.
            ->and($zeilen[0])->not->toHaveKey('message');
    });

    it('geht rueckwaerts nicht unter uid 1 — und fragt dann gar nicht erst', function () {
        // Sonst fragt ein frischer Index den Server nach dem Bereich 1 bis 0.
        // Der Ordner ist absichtlich NICHT leer: Ohne den Riegel kaeme hier
        // etwas zurueck.
        $ordner = suchOrdner('INBOX', [], [kettenNachricht(1, 'die einzige', '2026-09-05T10:00:00+00:00', '<a@x>')]);

        expect(verbKlientMitFormatierer([$ordner])->olderThan('INBOX', 1))->toBe([])
            ->and($ordner->gesucht)->toBe([]);
    });

    it('fragt ueber JMAP nach der Ankunftszeit der Bezugsnachricht', function () {
        // JMAP kennt keine uids. Es kennt, wann etwas ankam.
        $klient = jmapKlient([
            'Mailbox/get' => [jmapOrdner()],
            'Email/get' => [['Email/get', ['list' => [['id' => 'm5', 'receivedAt' => '2026-09-05T10:00:00Z']]], 'e0']],
            'Email/query' => [
                ['Email/query', ['ids' => ['m5', 'm6'], 'total' => 2], 'q0'],
                ['Email/get', ['list' => [
                    ['id' => 'm5', 'subject' => 'die Bezugsnachricht', 'attachments' => []],
                    ['id' => 'm6', 'subject' => 'danach', 'attachments' => []],
                ]], 'g0'],
            ],
        ], $protokoll);

        $zeilen = $klient->batch(fn ($p) => $p->newerThan('Inbox', 'm5', 20));

        $filter = collect($protokoll)->last()['payload']['methodCalls'][0][1]['filter'];

        expect($filter['after'])->toBe('2026-09-05T10:00:00Z')
            // Die Grenze ist einschliessend — die Bezugsnachricht selbst
            // gehoert nicht ins Ergebnis.
            ->and(array_column($zeilen, 'subject'))->toBe(['danach']);
    });

    it('raet nicht, wenn es die Bezugsnachricht nicht mehr gibt', function () {
        // Ein Index, der raet, verdoppelt Zeilen. Geprueft wird deshalb nicht
        // nur das leere Ergebnis, sondern dass gar keine Abfrage rausging.
        $klient = jmapKlient([
            'Mailbox/get' => [jmapOrdner()],
            'Email/get' => [['Email/get', ['list' => []], 'e0']],
        ], $protokoll);

        expect($klient->batch(fn ($p) => $p->newerThan('Inbox', 'weg')))->toBe([]);

        $abfragen = collect($protokoll)->filter(
            fn (array $a): bool => ($a['payload']['methodCalls'][0][0] ?? '') === 'Email/query'
        );

        expect($abfragen)->toBeEmpty();
    });
});
