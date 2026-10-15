<?php

use Carbon\Carbon;
use DirectoryTree\ImapEngine\BodyStructureCollection;
use DirectoryTree\ImapEngine\BodyStructurePart;
use DirectoryTree\ImapEngine\ContentDisposition;
use DirectoryTree\ImapEngine\Enums\ContentDispositionType;
use Peppermint\Mailbox\Imap\MessageFormatter;
use Peppermint\Mailbox\Jmap\JmapMessageFormatter;

/**
 * The two transports have to answer in the same rows (#5781).
 *
 * This is the test that makes a second transport safe. Everything above the
 * transports — threading, deduplication, paging, the React views — reads
 * fields by name. A JMAP mailbox that returned `sender` where IMAP returns
 * `from_address` would not fail here or anywhere else: the field would simply
 * be empty on screen, in one mailbox, for the people who use it.
 *
 * So the keys are compared directly, and they are compared for BOTH shapes —
 * the list row and the read view. Whoever adds a field to one formatter learns
 * here that the other one is missing it.
 */

/** The same mail, as each transport hands it over. */
function gleicheMailAlsJmap(): array
{
    return [
        'id' => 'm1',
        'subject' => 'Angebot',
        'from' => [['email' => 'kunde@example.test', 'name' => 'Kunde']],
        'to' => [],
        'cc' => [],
        'receivedAt' => '2026-09-01T10:00:00Z',
        'keywords' => ['$seen' => true],
        'messageId' => ['a@x'],
        'preview' => 'Guten Tag, anbei das Angebot.',
        'hasAttachment' => false,
        'attachments' => [],
        'textBody' => [['partId' => '1']],
        'bodyValues' => ['1' => ['value' => 'Guten Tag, anbei das Angebot.']],
    ];
}

it('liefert fuer die Liste dieselben Felder', function () {
    $imap = (new MessageFormatter)->summary(nachricht());
    $jmap = (new JmapMessageFormatter)->summary(gleicheMailAlsJmap());

    expect(array_keys($jmap))->toBe(array_keys($imap));
});

it('liefert fuer die Leseansicht dieselben Felder', function () {
    $imap = (new MessageFormatter)->full(nachricht(['attachments' => []]));
    $jmap = (new JmapMessageFormatter)->full(gleicheMailAlsJmap(), fn () => null);

    expect(array_keys($jmap))->toBe(array_keys($imap));
});

it('fuellt die Felder, die ein Leser vergleicht, auch gleich', function () {
    // Gleiche Schluessel mit anderer Bedeutung waeren der schlimmere Fall:
    // Er faellt nirgends auf, bis jemand zwei Postfaecher nebeneinander sieht.
    $imap = (new MessageFormatter)->summary(nachricht());
    $jmap = (new JmapMessageFormatter)->summary(gleicheMailAlsJmap());

    foreach (['subject', 'from_address', 'from_name', 'message_id', 'is_read', 'is_flagged', 'preview', 'has_attachments', 'attachment_count'] as $feld) {
        expect($jmap[$feld])->toBe($imap[$feld], "Feld {$feld} weicht ab");
    }

    // Das Datum in derselben Schreibweise, sonst sortiert eine gemischte Liste
    // falsch.
    expect(Carbon::parse($jmap['date'])->equalTo(Carbon::parse($imap['date'])))->toBeTrue();
});

it('haelt nur die Nachrichten-Kennung bewusst offen', function () {
    // IMAP zaehlt uids, JMAP vergibt Zeichenketten. Das ist der EINE
    // Unterschied, den der Vertrag zulaesst — und er ist im Interface
    // ausgeschrieben, damit ihn niemand fuer einen Fehler haelt.
    $imap = (new MessageFormatter)->summary(nachricht());
    $jmap = (new JmapMessageFormatter)->summary(gleicheMailAlsJmap());

    expect($imap['uid'])->toBeInt()
        ->and($jmap['uid'])->toBeString();
});

it('liefert fuer die billige Dreiergruppe dieselben Felder', function () {
    // newest/newerThan/olderThan gehen nicht durch den Formatierer-Vergleich
    // oben: Sie liefern bewusst WENIGER als eine Listenzeile. Ueber JMAP
    // kaeme die Vorschau gratis mit, ueber IMAP kostet sie einen Rumpfabruf —
    // also gibt die Dreiergruppe die Schnittmenge heraus, nicht das Maximum.
    // Sonst schriebe ein Index gegen IMAP Felder, die ueber JMAP fehlen.
    $imapZeile = verbKlientMitFormatierer([
        suchOrdner('INBOX', [], [kettenNachricht(9, 'Angebot', '2026-09-01T10:00:00+00:00', '<a@x>')]),
    ])->newest('INBOX', 10)[0];

    $jmapZeile = jmapKlient(['Email/query' => [
        ['Email/query', ['ids' => ['m1'], 'total' => 1], 'q0'],
        ['Email/get', ['list' => [gleicheMailAlsJmap()]], 'g0'],
    ]])->newest('Inbox', 10)[0];

    expect(array_keys($jmapZeile))->toBe(array_keys($imapZeile))
        ->and($jmapZeile)->not->toHaveKey('preview')
        ->and($imapZeile)->not->toHaveKey('preview');
});

it('gibt in der billigen Dreiergruppe die Kettenkopfzeilen mit', function () {
    // Ohne in_reply_to/references kann ein Verzeichnis hinterher nicht mehr
    // buendeln — und ein zweiter Abruf dafuer waere genau die Verschwendung,
    // die die Dreiergruppe vermeiden soll.
    $zeile = verbKlientMitFormatierer([
        suchOrdner('INBOX', [], [kettenNachricht(9, 'Re: Angebot', '2026-09-01T10:00:00+00:00', '<b@x>', '<a@x>')]),
    ])->newest('INBOX', 10)[0];

    expect($zeile['in_reply_to'])->toBe('<a@x>')
        ->and($zeile)->toHaveKey('references');
});

it('liefert fuer die Aufbewahrung dieselben Felder', function () {
    // Dritte Form neben Listenzeile und Leseansicht — und die einzige, in der
    // ein Produkt die Nachricht dauerhaft ablegt. Ein fehlendes Feld faellt
    // hier nicht auf, sondern in einem Archiv, das schon geschrieben ist.
    $imap = (new MessageFormatter)->verbatim(nachricht(['attachments' => []]));
    $jmap = (new JmapMessageFormatter)->verbatim(gleicheMailAlsJmap(), fn () => 'BYTES');

    expect(array_keys($jmap))->toBe(array_keys($imap));
});

it('haelt den Rumpf auf beiden Wegen roh, wenn er aufbewahrt wird', function () {
    $mail = gleicheMailAlsJmap();
    $mail['htmlBody'] = [['partId' => '2']];
    $mail['bodyValues'] = ['2' => ['value' => '<img src="cid:logo@x">']];
    $mail['attachments'] = [['blobId' => 'b1', 'name' => 'logo.png', 'type' => 'image/png', 'cid' => 'logo@x', 'disposition' => 'inline']];

    $anzeige = (new JmapMessageFormatter)->full($mail, fn () => 'PNG');
    $archiv = (new JmapMessageFormatter)->verbatim($mail, fn () => 'PNG');

    expect($anzeige['body_html'])->toContain('data:image/png;base64,')
        ->and($archiv['body_html'])->toBe('<img src="cid:logo@x">')
        ->and($archiv['files'][0]['content_id'])->toBe('logo@x')
        ->and($archiv['files'][0]['inline'])->toBeTrue()
        ->and($archiv['files'][0]['contents'])->toBe('PNG');
});

it('legt einen Teil ohne Bytes NICHT als leere Datei ab', function () {
    // Im Archiv saehe er aus wie ein Anhang, den jemand geleert hat — und
    // niemand wuesste, dass der Server ihn nie herausgegeben hat.
    $mail = gleicheMailAlsJmap();
    $mail['attachments'] = [['blobId' => 'weg', 'name' => 'Angebot.pdf', 'type' => 'application/pdf']];

    $archiv = (new JmapMessageFormatter)->verbatim($mail, fn () => null);

    expect($archiv['files'])->toBe([]);
});

/**
 * Dieselbe Teilform, einmal fuer jeden Transport gebaut.
 *
 * Aus EINER Beschreibung, damit die beiden Seiten nicht auseinanderlaufen,
 * waehrend der Test behauptet, sie zu vergleichen.
 */
function teilformAufBeidenWegen(array $form): array
{
    $imap = nachricht([
        'html' => '<p>Text</p><img src="cid:logo@x">',
        'attachments' => [anhang([
            'contentId' => $form['cid'] ?? null,
            'disposition' => $form['disposition'] ?? null,
            'type' => $form['type'],
            'filename' => $form['name'],
            'contents' => 'BYTES',
        ])],
    ]);

    $jmap = gleicheMailAlsJmap();
    $jmap['htmlBody'] = [['partId' => '2']];
    $jmap['bodyValues']['2'] = ['value' => '<p>Text</p><img src="cid:logo@x">'];
    $jmap['attachments'] = [array_filter([
        'blobId' => 'b1',
        'name' => $form['name'],
        'type' => $form['type'],
        'cid' => $form['cid'] ?? null,
        'disposition' => $form['disposition'] ?? null,
    ], fn ($wert) => $wert !== null)];

    return [$imap, $jmap];
}

describe('Bug #1023 — eine Regel fuer Liste und Download, auf beiden Wegen', function () {
    it('haelt eine Datei in der Liste UND herunterladbar', function (array $form, string $name) {
        [$imapNachricht, $jmapMail] = teilformAufBeidenWegen($form);

        $imap = (new MessageFormatter)->full($imapNachricht);
        $jmap = (new JmapMessageFormatter)->full($jmapMail, fn () => 'BYTES');

        // In der Liste, auf beiden Wegen, unter demselben Namen.
        expect(array_column($imap['attachments'], 'filename'))->toBe([$name])
            ->and(array_column($jmap['attachments'], 'filename'))->toBe([$name]);

        // Und ueber denselben Index auch abholbar: Eine Zeile, die niemand
        // oeffnen kann, ist keine Behebung.
        expect((new MessageFormatter)->attachmentAt($imapNachricht, 0))->not->toBeNull()
            ->and((new JmapMessageFormatter)->partAt($jmapMail, 0))->not->toBeNull();

        // Und NICHT im Rumpf — dort gibt es nichts, worauf ein cid: zeigt.
        expect($imap['body_html'])->not->toContain('data:')
            ->and($jmap['body_html'])->not->toContain('data:');
    })->with([
        // Die Form, die Outlook und diverse ERP-Mailer verschicken.
        'Bild als inline markiert, ohne Content-ID' => [['name' => 'foto.jpg', 'type' => 'image/jpeg', 'disposition' => 'inline'], 'foto.jpg'],
        // Ein Anhang bleibt ein Anhang, auch mit Content-ID.
        'PDF mit Content-ID, ohne disposition' => [['name' => 'Angebot.pdf', 'type' => 'application/pdf', 'cid' => 'pdf@x'], 'Angebot.pdf'],
        'PDF ausdruecklich als inline markiert' => [['name' => 'Angebot.pdf', 'type' => 'application/pdf', 'cid' => 'pdf@x', 'disposition' => 'inline'], 'Angebot.pdf'],
    ]);

    it('nimmt ein eingebettetes Bild aus der Liste und gibt es auch nicht heraus', function () {
        // Die Gegenprobe zum Test darueber: Die Regel darf nicht einfach alles
        // in die Liste schieben. Wer Anhang 0 anfordert, meint nicht das Logo.
        [$imapNachricht, $jmapMail] = teilformAufBeidenWegen([
            'name' => 'logo.png', 'type' => 'image/png', 'cid' => 'logo@x', 'disposition' => 'inline',
        ]);

        $imap = (new MessageFormatter)->full($imapNachricht);
        $jmap = (new JmapMessageFormatter)->full($jmapMail, fn () => 'PNG');

        expect($imap['attachments'])->toBe([])
            ->and($jmap['attachments'])->toBe([])
            ->and($imap['body_html'])->toContain('data:image/png;base64,')
            ->and($jmap['body_html'])->toContain('data:image/png;base64,')
            ->and((new MessageFormatter)->attachmentAt($imapNachricht, 0))->toBeNull()
            ->and((new JmapMessageFormatter)->partAt($jmapMail, 0))->toBeNull();
    });

    it('laesst einen Teil in der Liste, dessen Bytes der JMAP-Server nicht hergibt', function () {
        // Sonst ist er nirgends: nicht im Rumpf (das cid: bleibt stehen, und
        // die Anzeige laesst nur data: zu) und nicht in der Liste.
        [, $jmapMail] = teilformAufBeidenWegen([
            'name' => 'logo.png', 'type' => 'image/png', 'cid' => 'logo@x', 'disposition' => 'inline',
        ]);

        $jmap = (new JmapMessageFormatter)->full($jmapMail, fn () => null);

        expect(array_column($jmap['attachments'], 'filename'))->toBe(['logo.png'])
            ->and($jmap['body_html'])->toContain('cid:logo@x');
    });

    it('kennzeichnet im Archiv nur als inline, was wirklich im Rumpf steht', function (array $form, bool $imRumpf) {
        // Die Produkte schliessen beim Weiterleiten genau nach diesem Feld aus.
        // Ein falsches „inline" hier laesst die Datei eine Schicht hoeher
        // verschwinden — die Behebung endete sonst an der Paketgrenze.
        [$imapNachricht, $jmapMail] = teilformAufBeidenWegen($form);

        $imap = (new MessageFormatter)->verbatim($imapNachricht);
        $jmap = (new JmapMessageFormatter)->verbatim($jmapMail, fn () => 'BYTES');

        expect($imap['files'][0]['inline'])->toBe($imRumpf)
            ->and($jmap['files'][0]['inline'])->toBe($imRumpf);
    })->with([
        'Bild mit Content-ID gehoert in den Rumpf' => [['name' => 'logo.png', 'type' => 'image/png', 'cid' => 'logo@x', 'disposition' => 'inline'], true],
        'Bild inline ohne Content-ID reist mit' => [['name' => 'foto.jpg', 'type' => 'image/jpeg', 'disposition' => 'inline'], false],
        'Inline-PDF reist mit' => [['name' => 'Angebot.pdf', 'type' => 'application/pdf', 'cid' => 'pdf@x', 'disposition' => 'inline'], false],
    ]);
});

/** Ein Strukturteil fuer die IMAP-Seite, wie ihn BODYSTRUCTURE liefert. */
function vertragsTeil(string $typ, string $untertyp, ?string $cid = null, ?string $disposition = null, ?string $dateiname = null): BodyStructurePart
{
    return new BodyStructurePart(
        partNumber: '1',
        type: $typ,
        subtype: $untertyp,
        id: $cid,
        disposition: $disposition === null
            ? null
            : new ContentDisposition(
                ContentDispositionType::from($disposition),
                $dateiname === null ? [] : ['filename' => $dateiname],
            ),
    );
}

it('zaehlt die Anhaenge einer Listenzeile auf beiden Wegen gleich', function () {
    // Dieselbe Mail: ein Logo im Rumpf, eine Rechnung als Datei. Sichtbar ist
    // genau EINE — und das muss in beiden Listen dieselbe Zahl und dieselbe
    // Klammer ergeben.
    //
    // Genau dieser Vergleich fehlte: attachment_count und has_attachments
    // standen in keinem Wertevergleich, und so blieb jahrelang unbemerkt, dass
    // der IMAP-Weg die Zahl aus einem Rumpf las, den er gar nicht laedt — er
    // meldete IMMER 0 (Bug #1025).
    $imap = (new MessageFormatter)->summary(nachricht([
        'bodyStructure' => new BodyStructureCollection(parts: [
            vertragsTeil('text', 'html'),
            vertragsTeil('image', 'png', 'logo@x', 'inline', 'logo.png'),
            vertragsTeil('application', 'pdf', null, 'attachment', 'Rechnung.pdf'),
        ]),
    ]));

    $mail = gleicheMailAlsJmap();
    $mail['hasAttachment'] = true;
    $mail['attachments'] = [
        ['blobId' => 'b1', 'name' => 'logo.png', 'type' => 'image/png', 'cid' => 'logo@x', 'disposition' => 'inline'],
        ['blobId' => 'b2', 'name' => 'Rechnung.pdf', 'type' => 'application/pdf', 'disposition' => 'attachment'],
    ];
    $jmap = (new JmapMessageFormatter)->summary($mail);

    expect($imap['attachment_count'])->toBe(1)
        ->and($jmap['attachment_count'])->toBe(1)
        ->and($imap['has_attachments'])->toBeTrue()
        ->and($jmap['has_attachments'])->toBeTrue();
});

it('setzt die Klammer auf keinem Weg, wenn nur ein Logo im Rumpf steckt', function () {
    // Der Server meldet fuer so eine Mail `hasAttachment: true` — die Liste
    // zeigte eine Klammer, und die geoeffnete Nachricht hatte nichts.
    $imap = (new MessageFormatter)->summary(nachricht([
        'bodyStructure' => new BodyStructureCollection(parts: [
            vertragsTeil('text', 'html'),
            vertragsTeil('image', 'png', 'logo@x', 'inline', 'logo.png'),
        ]),
    ]));

    $mail = gleicheMailAlsJmap();
    $mail['hasAttachment'] = true;
    $mail['attachments'] = [['blobId' => 'b1', 'name' => 'logo.png', 'type' => 'image/png', 'cid' => 'logo@x', 'disposition' => 'inline']];
    $jmap = (new JmapMessageFormatter)->summary($mail);

    expect($imap['has_attachments'])->toBeFalse()
        ->and($jmap['has_attachments'])->toBeFalse()
        ->and($imap['attachment_count'])->toBe(0)
        ->and($jmap['attachment_count'])->toBe(0);
});
