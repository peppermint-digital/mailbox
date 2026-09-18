<?php

use Carbon\Carbon;
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

    foreach (['subject', 'from_address', 'from_name', 'message_id', 'is_read', 'is_flagged', 'preview'] as $feld) {
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
