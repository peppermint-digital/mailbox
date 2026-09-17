<?php

use Peppermint\Mailbox\Jmap\JmapClient;
use Peppermint\Mailbox\Models\MailAccount;

/**
 * The second transport (#5781).
 *
 * The shapes in here are not invented: they are what mail.peppermint-digital.com
 * answered on 17.09.2026, trimmed to what the client reads. A fake built from
 * a guess tests the guess.
 */

/** The session document, as Stalwart hands it out. */
function jmapSitzung(): array
{
    return [
        'username' => 'info@example.test',
        'apiUrl' => 'https://mail.example.test/jmap/',
        'downloadUrl' => 'https://mail.example.test/jmap/download/{accountId}/{blobId}/{name}?accept={type}',
        'primaryAccounts' => ['urn:ietf:params:jmap:mail' => 'c'],
        'accounts' => ['c' => ['name' => 'info@example.test']],
    ];
}

function jmapOrdner(): array
{
    return ['Mailbox/get', ['list' => [
        ['id' => 'a', 'name' => 'Inbox', 'parentId' => null, 'role' => 'inbox'],
        ['id' => 'e', 'name' => 'Sent Items', 'parentId' => null, 'role' => 'sent'],
        ['id' => 'b', 'name' => 'Deleted Items', 'parentId' => null, 'role' => 'trash'],
        ['id' => 'j', 'name' => '2025', 'parentId' => 'h', 'role' => null],
        ['id' => 'h', 'name' => 'Archives', 'parentId' => null, 'role' => null],
    ]], 'f0'];
}

/**
 * A client whose HTTP layer is a lookup table.
 *
 * @param  array<string, mixed>  $antworten  keyed by the first method call, plus 'blob'
 */
function jmapKlient(array $antworten = [], ?array &$protokoll = null, array $kontoWerte = []): JmapClient
{
    $protokoll = [];

    $konto = MailAccount::fromRemote(array_merge([
        'id' => 1, 'email' => 'info@example.test', 'username' => null, 'password' => 'geheim',
        'protocol' => 'jmap', 'imap_host' => 'mail.example.test', 'auth_type' => 'password',
        'oauth_access_token' => null, 'oauth_token_expires_at' => null,
    ], $kontoWerte));

    $sender = function (string $method, string $url, array $payload, array $headers) use ($antworten, &$protokoll): array {
        $protokoll[] = ['method' => $method, 'url' => $url, 'payload' => $payload, 'headers' => $headers];

        if ($method === 'GET' && str_contains($url, '/.well-known/jmap')) {
            return $antworten['session'] ?? jmapSitzung();
        }

        if ($method === 'GET') {
            return ['__raw' => $antworten['blob'] ?? 'BYTES'];
        }

        $ersterAufruf = $payload['methodCalls'][0][0] ?? '';

        return ['methodResponses' => $antworten[$ersterAufruf] ?? [jmapOrdner()]];
    };

    return new JmapClient(account: $konto, sender: $sender);
}

describe('die Sitzung', function () {
    it('leitet die Adresse aus dem Mail-Host ab und meldet sich mit Basic an', function () {
        jmapKlient([], $protokoll)->folders();

        expect($protokoll[0]['url'])->toBe('https://mail.example.test/.well-known/jmap')
            ->and($protokoll[0]['headers']['Authorization'])->toBe('Basic '.base64_encode('info@example.test:geheim'));
    });

    it('nimmt das Konto aus primaryAccounts, nicht das erstbeste', function () {
        // Ein Zugang mit Stellvertretung sieht mehrere Konten. Das erste zu
        // nehmen hiesse, still im Postfach einer anderen Person zu lesen.
        $sitzung = jmapSitzung();
        $sitzung['accounts'] = ['zzz' => ['name' => 'fremd@example.test'], 'c' => ['name' => 'info@example.test']];

        jmapKlient(['session' => $sitzung], $protokoll)->folders();

        expect($protokoll[1]['payload']['methodCalls'][0][1]['accountId'])->toBe('c');
    });

    it('holt das Dokument nur einmal je Klient', function () {
        $klient = jmapKlient([], $protokoll);
        $klient->folders();
        $klient->folders();

        $sitzungsaufrufe = array_filter($protokoll, fn (array $a): bool => str_contains($a['url'], '.well-known'));

        expect($sitzungsaufrufe)->toHaveCount(1);
    });

    it('bricht ab, wenn der Server gar kein Postfach anbietet', function () {
        $sitzung = jmapSitzung();
        $sitzung['primaryAccounts'] = [];

        expect(fn () => jmapKlient(['session' => $sitzung])->folders())
            ->toThrow(RuntimeException::class, 'no mail account');
    });

    it('bricht bei einem OAuth-Konto ohne Token ab, statt als niemand zu verbinden', function () {
        expect(fn () => jmapKlient([], $protokoll, [
            'auth_type' => 'oauth',
            'oauth_access_token' => null,
        ])->folders())->toThrow(RuntimeException::class, 'no access token');
    });
});

describe('Ordner', function () {
    it('macht aus der Rolle des Servers die Sonderkennzeichnung, die das Paket schon liest', function () {
        // Ueber IMAP wird der Papierkorb an seinem NAMEN erkannt, in der
        // Sprache des Servers. Hier sagt der Server, was er ist.
        $zeilen = collect(jmapKlient()->folders());

        expect($zeilen->firstWhere('name', 'Sent Items')['flags'])->toBe(['\\Sent'])
            ->and($zeilen->firstWhere('name', 'Deleted Items')['flags'])->toBe(['\\Trash'])
            ->and($zeilen->firstWhere('name', 'Inbox')['flags'])->toBe(['\\Inbox'])
            ->and($zeilen->firstWhere('name', '2025')['flags'])->toBe([]);
    });

    it('baut aus den Eltern einen Pfad, weil oben drueber alles mit Zeichenketten adressiert', function () {
        $zeilen = collect(jmapKlient()->folders());

        expect($zeilen->firstWhere('name', '2025')['path'])->toBe('Archives/2025')
            ->and($zeilen->firstWhere('name', 'Inbox')['path'])->toBe('Inbox');
    });

    it('holt die Ordner in einem Stapel nur einmal', function () {
        $klient = jmapKlient([], $protokoll);

        $klient->batch(function ($postfach) {
            $postfach->folders();
            $postfach->folders();
            $postfach->folders();
        });

        $ordneraufrufe = array_filter(
            $protokoll,
            fn (array $a): bool => ($a['payload']['methodCalls'][0][0] ?? '') === 'Mailbox/get'
        );

        expect($ordneraufrufe)->toHaveCount(1);
    });
});

describe('Kopfzeilen-Zeilen', function () {
    it('schickt Abfrage und Zeilen in EINEM Aufruf, per Rueckverweis', function () {
        // Das ist der ganze Unterschied zu IMAP: Der Server loest die Kennungen
        // selbst auf, wir lernen sie nie.
        $klient = jmapKlient([
            'Mailbox/get' => [jmapOrdner()],
            'Email/query' => [
                ['Email/query', ['ids' => ['m1'], 'total' => 12], 'q0'],
                ['Email/get', ['list' => [[
                    'id' => 'm1', 'subject' => 'Angebot', 'from' => [['email' => 'kunde@example.test', 'name' => 'Kunde']],
                    'receivedAt' => '2026-09-16T10:55:09Z', 'keywords' => ['$seen' => true],
                    'messageId' => ['a@x'], 'preview' => 'Guten Tag', 'hasAttachment' => false, 'attachments' => [],
                ]]], 'g0'],
            ],
        ], $protokoll);

        [$zeilen, $gesamt] = $klient->batch(fn ($postfach) => $postfach->headerRows('Inbox'));

        $abfrage = collect($protokoll)->last()['payload']['methodCalls'];

        expect($abfrage)->toHaveCount(2)
            ->and($abfrage[1][1]['#ids'])->toBe(['resultOf' => 'q0', 'name' => 'Email/query', 'path' => '/ids'])
            ->and($gesamt)->toBe(12)
            ->and($zeilen[0]['subject'])->toBe('Angebot')
            ->and($zeilen[0]['uid'])->toBe('m1')
            ->and($zeilen[0]['is_read'])->toBeTrue();
    });

    it('findet INBOX, obwohl der Ordner Inbox heisst', function () {
        // Beide Produkte fragen per Vorgabe nach "INBOX" — das ist der Name,
        // den IMAP garantiert. JMAP kennt ihn nicht; unser Server sagt "Inbox".
        // Ohne diese Bruecke kam die Liste leer zurueck, ohne jeden Fehler:
        // live gemessen am 17.09.2026.
        $klient = jmapKlient([
            'Mailbox/get' => [jmapOrdner()],
            'Email/query' => [
                ['Email/query', ['ids' => ['m1'], 'total' => 13], 'q0'],
                ['Email/get', ['list' => [['id' => 'm1', 'subject' => 'Da', 'attachments' => []]]], 'g0'],
            ],
        ]);

        [$zeilen, $gesamt] = $klient->headerRows('INBOX');

        expect($gesamt)->toBe(13)->and($zeilen)->toHaveCount(1);
    });

    it('findet INBOX auch, wenn der Ordner ganz anders heisst — ueber die Rolle', function () {
        // Ein Server auf Deutsch nennt ihn "Posteingang". Da hilft keine
        // Schreibweise mehr; nur die Rolle sagt, welcher Ordner gemeint ist.
        $deutsch = ['Mailbox/get', ['list' => [
            ['id' => 'a', 'name' => 'Posteingang', 'parentId' => null, 'role' => 'inbox'],
            ['id' => 'e', 'name' => 'Gesendet', 'parentId' => null, 'role' => 'sent'],
        ]], 'f0'];

        $klient = jmapKlient([
            'Mailbox/get' => [$deutsch],
            'Email/query' => [
                ['Email/query', ['ids' => [], 'total' => 7], 'q0'],
                ['Email/get', ['list' => []], 'g0'],
            ],
        ], $protokoll);

        [, $gesamt] = $klient->headerRows('INBOX');

        expect($gesamt)->toBe(7)
            ->and(collect($protokoll)->last()['payload']['methodCalls'][0][1]['filter'])
            ->toBe(['inMailbox' => 'a']);
    });

    it('findet einen Ordner auch bei abweichender Gross-/Kleinschreibung', function () {
        $klient = jmapKlient([
            'Mailbox/get' => [jmapOrdner()],
            'Email/query' => [
                ['Email/query', ['ids' => [], 'total' => 180], 'q0'],
                ['Email/get', ['list' => []], 'g0'],
            ],
        ]);

        [, $gesamt] = $klient->headerRows('archives/2025');

        expect($gesamt)->toBe(180);
    });

    it('antwortet auf einen unbekannten Ordner mit leer, nicht mit einem Fehler', function () {
        [$zeilen, $gesamt] = jmapKlient()->headerRows('Gibt-Es-Nicht');

        expect($zeilen)->toBe([])->and($gesamt)->toBe(0);
    });
});

describe('eine Nachricht lesen', function () {
    $mail = [
        'id' => 'm1',
        'subject' => 'Muster',
        'from' => [['email' => 'kunde@example.test', 'name' => 'Kunde']],
        'to' => [['email' => 'info@example.test', 'name' => null]],
        'cc' => [],
        'receivedAt' => '2026-09-16T10:55:09Z',
        'keywords' => ['$seen' => true],
        'messageId' => ['a@x'],
        'references' => ['b@x', 'c@x'],
        'hasAttachment' => true,
        'htmlBody' => [['partId' => '3']],
        'textBody' => [['partId' => '2']],
        'bodyValues' => [
            '2' => ['value' => 'Guten Tag'],
            '3' => ['value' => '<p>Guten Tag <img src="cid:logo@x"></p>'],
        ],
        'attachments' => [
            ['partId' => '4', 'blobId' => 'blob-logo', 'name' => 'logo.png', 'type' => 'image/png', 'cid' => 'logo@x', 'size' => 10],
            ['partId' => '5', 'blobId' => 'blob-pdf', 'name' => 'Angebot.pdf', 'type' => 'application/pdf', 'disposition' => 'attachment', 'size' => 2048],
        ],
    ];

    it('loest Inline-Bilder in den Rumpf auf und laesst sie aus der Anhangsliste', function () use ($mail) {
        $klient = jmapKlient(['Email/get' => [['Email/get', ['list' => [$mail]], 'e0']], 'blob' => 'PNGBYTES']);

        $nachricht = $klient->message('Inbox', 'm1');

        expect($nachricht['body_html'])->toContain('data:image/png;base64,'.base64_encode('PNGBYTES'))
            ->and($nachricht['body_html'])->not->toContain('cid:logo@x')
            ->and($nachricht['attachments'])->toHaveCount(1)
            ->and($nachricht['attachments'][0]['filename'])->toBe('Angebot.pdf')
            // Die Stelle im TEILEVERZEICHNIS, nicht in der sichtbaren Liste.
            ->and($nachricht['attachments'][0]['index'])->toBe(1);
    });

    it('gibt die Kopfzeilen fuer die Verkettung so heraus, wie die Verkettung sie liest', function () use ($mail) {
        $klient = jmapKlient(['Email/get' => [['Email/get', ['list' => [$mail]], 'e0']]]);

        $nachricht = $klient->message('Inbox', 'm1');

        // Mit Spitzklammern, wie die Kopfzeile sie traegt und wie der
        // IMAP-Transport sie weiterreicht — daran haengen Entdopplung und
        // gespeicherte Zuordnungen.
        expect($nachricht['message_id'])->toBe('<a@x>')
            ->and($nachricht['references'])->toBe('<b@x> <c@x>');
    });

    it('ist null, wenn es die Nachricht nicht mehr gibt', function () {
        $klient = jmapKlient(['Email/get' => [['Email/get', ['list' => []], 'e0']]]);

        expect($klient->message('Inbox', 'weg'))->toBeNull();
    });

    it('veraendert beim Lesen keine Kennzeichen', function () use ($mail) {
        // Ueber IMAP setzt ein Abruf ohne PEEK das Gelesen-Kennzeichen. Hier
        // gibt es diese ganze Fehlerklasse nicht — und das soll auch so
        // bleiben, wenn jemand spaeter etwas hinzufuegt.
        $klient = jmapKlient(['Email/get' => [['Email/get', ['list' => [$mail]], 'e0']]], $protokoll);

        $klient->message('Inbox', 'm1');

        $schreibend = collect($protokoll)->filter(
            fn (array $a): bool => str_contains(json_encode($a['payload']), 'Email/set')
        );

        expect($schreibend)->toBeEmpty();
    });
});

describe('ein Anhang', function () {
    $mail = [
        'id' => 'm1',
        'attachments' => [
            ['blobId' => 'blob-logo', 'name' => 'logo.png', 'type' => 'image/png', 'cid' => 'logo@x'],
            ['blobId' => 'blob-pdf', 'name' => 'Angebot.pdf', 'type' => 'application/pdf', 'disposition' => 'attachment'],
        ],
    ];

    it('wird ueber dieselbe Stelle geholt, die die Leseansicht ausgibt', function () use ($mail) {
        $klient = jmapKlient(['Email/get' => [['Email/get', ['list' => [$mail]], 'e0']], 'blob' => 'PDFBYTES'], $protokoll);

        $anhang = $klient->attachment('Inbox', 'm1', 1);

        expect($anhang)->toBe([
            'filename' => 'Angebot.pdf',
            'mime_type' => 'application/pdf',
            'contents' => 'PDFBYTES',
        ]);

        expect(collect($protokoll)->last()['url'])->toContain('blob-pdf');
    });

    it('gibt ein Inline-Bild nicht als Anhang heraus', function () use ($mail) {
        // Die Stelle gibt es, sie gehoert aber in den Rumpf. Wer sie anfordert,
        // meint etwas anderes.
        $klient = jmapKlient(['Email/get' => [['Email/get', ['list' => [$mail]], 'e0']]]);

        expect($klient->attachment('Inbox', 'm1', 0))->toBeNull();
    });
});

describe('Fehler', function () {
    it('erkennt eine Absage des Servers, obwohl sie mit HTTP 200 kommt', function () {
        // Ohne diese Pruefung kaeme eine Liste einfach leer zurueck, und der
        // Ausfall saehe aus wie ein leeres Postfach.
        $klient = jmapKlient(['Mailbox/get' => [['error', ['type' => 'unknownMethod'], 'f0']]]);

        expect(fn () => $klient->folders())->toThrow(RuntimeException::class, 'unknownMethod');
    });

    it('macht aus einer Absage des Servers keinen stillen Erfolg', function () {
        // Ein Fehlschlag, der wie ein gueltiges Ergebnis aussieht, ist die
        // teuerste Sorte: Der Klick sieht aus, als haette er gewirkt.
        $klient = jmapKlient(['Email/set' => [['Email/set', [
            'updated' => [],
            'notUpdated' => ['m1' => ['type' => 'forbidden', 'description' => 'read-only mailbox']],
        ], 's0']]]);

        expect(fn () => $klient->setSeen('Inbox', 'm1', true))
            ->toThrow(RuntimeException::class, 'forbidden');
    });
});

describe('schreiben', function () {
    it('setzt ein Kennzeichen mit true und entfernt es mit null', function () {
        // JSON kennt hier keinen dritten Wert: false wuerde ein Kennzeichen
        // ABLEGEN, das es gibt und das false ist — das versteht kein anderer
        // Klient.
        $klient = jmapKlient(['Email/set' => [['Email/set', ['updated' => ['m1' => null]], 's0']]], $protokoll);

        expect($klient->setSeen('Inbox', 'm1', true))->toBeTrue();
        expect(collect($protokoll)->last()['payload']['methodCalls'][0][1]['update'])
            ->toBe(['m1' => ['keywords/$seen' => true]]);

        $klient->setSeen('Inbox', 'm1', false);
        expect(collect($protokoll)->last()['payload']['methodCalls'][0][1]['update'])
            ->toBe(['m1' => ['keywords/$seen' => null]]);
    });

    it('antwortet mit false, wenn die Nachricht weg ist — ohne Ausnahme', function () {
        // Ein geteiltes Postfach bewegt sich unter dem, der hineinsieht.
        $klient = jmapKlient(['Email/set' => [['Email/set', [
            'updated' => [],
            'notUpdated' => ['m1' => ['type' => 'notFound']],
        ], 's0']]]);

        expect($klient->setFlagged('Inbox', 'm1', true))->toBeFalse();
    });

    it('ERSETZT beim Verschieben die Ordnerzuordnung, statt sie zu ergaenzen', function () {
        // In JMAP kann eine Mail in mehreren Ordnern liegen. Ein Patch wuerde
        // das Ziel HINZUFUEGEN — die Mail laege danach auch noch im Eingang.
        $klient = jmapKlient([
            'Mailbox/get' => [jmapOrdner()],
            'Email/set' => [['Email/set', ['updated' => ['m1' => null]], 's0']],
        ], $protokoll);

        expect($klient->move('Inbox', 'm1', 'Sent Items'))->toBeTrue();

        expect(collect($protokoll)->last()['payload']['methodCalls'][0][1]['update'])
            ->toBe(['m1' => ['mailboxIds' => ['e' => true]]]);
    });

    it('fragt beim Verschieben in denselben Ordner gar nicht erst', function () {
        $klient = jmapKlient([], $protokoll);

        expect($klient->move('Inbox', 'm1', 'Inbox'))->toBeTrue()
            ->and($protokoll)->toBeEmpty();
    });

    it('findet den Papierkorb an der Rolle, nicht am Namen', function () {
        // Ueber IMAP wird er an einer Namensliste erkannt — in der Sprache des
        // Servers. Hier steht die Rolle dran, und die gibt es in jeder Sprache.
        $klient = jmapKlient([
            'Mailbox/get' => [jmapOrdner()],
            'Email/set' => [['Email/set', ['updated' => ['m1' => null]], 's0']],
        ], $protokoll);

        expect($klient->delete('Inbox', 'm1', ['papierkorb']))->toBeTrue();

        expect(collect($protokoll)->last()['payload']['methodCalls'][0][1]['update'])
            ->toBe(['m1' => ['mailboxIds' => ['b' => true]]]);
    });

    it('loescht endgueltig, wenn die Nachricht schon im Papierkorb liegt', function () {
        $klient = jmapKlient([
            'Mailbox/get' => [jmapOrdner()],
            'Email/set' => [['Email/set', ['destroyed' => ['m1']], 's0']],
        ], $protokoll);

        expect($klient->delete('Deleted Items', 'm1'))->toBeTrue();

        expect(collect($protokoll)->last()['payload']['methodCalls'][0][1]['destroy'])->toBe(['m1']);
    });
});

describe('Ordner aendern', function () {
    it('legt verschachtelt an, indem es den Elternordner nachschlaegt', function () {
        $klient = jmapKlient([
            'Mailbox/get' => [jmapOrdner()],
            'Mailbox/set' => [['Mailbox/set', ['created' => ['neu' => ['id' => 'neu1']]], 's0']],
        ], $protokoll);

        $klient->createFolder('Archives/2027');

        expect(collect($protokoll)->last()['payload']['methodCalls'][0][1]['create'])
            ->toBe(['neu' => ['name' => '2027', 'parentId' => 'h']]);
    });

    it('erfindet keinen Elternordner, den es nicht gibt', function () {
        // Sonst wird aus einem Tippfehler ein Ordnerbaum.
        expect(fn () => jmapKlient()->createFolder('Gibt-Es-Nicht/2027'))
            ->toThrow(RuntimeException::class, 'Folder not found');
    });

    it('weigert sich, einen Systemordner umzubenennen', function () {
        // Dieselbe Weigerung wie ueber IMAP, damit ein Produkt nicht zwei
        // Verhalten kennen muss.
        expect(fn () => jmapKlient()->renameFolder('Sent Items', 'Raus'))
            ->toThrow(RuntimeException::class, 'System folders');
    });

    it('reicht die Weigerung des Servers durch, statt sie zu schlucken', function () {
        // Ein voller Ordner wird abgelehnt — und das soll man sehen. Ueber IMAP
        // nimmt derselbe Aufruf die Mail mit.
        $klient = jmapKlient([
            'Mailbox/get' => [jmapOrdner()],
            'Mailbox/set' => [['Mailbox/set', [
                'notDestroyed' => ['j' => ['type' => 'mailboxHasEmail', 'description' => '180 Nachrichten']],
            ], 's0']],
        ]);

        expect(fn () => $klient->deleteFolder('Archives/2025'))
            ->toThrow(RuntimeException::class, 'mailboxHasEmail');
    });
});
