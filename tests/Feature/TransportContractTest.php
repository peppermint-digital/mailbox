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
