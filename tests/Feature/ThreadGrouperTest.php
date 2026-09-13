<?php

use Peppermint\Mailbox\Threading\ThreadGrouper;

/**
 * Grouping messages into conversations, lifted from peppermint-manager (#5488).
 *
 * Every case here is something a mailbox list has to get right for the list to
 * feel correct — order, naming, unread state. None of it needs a mailbox to
 * check, which is the whole point of the cut.
 */
function zeile(array $werte = []): array
{
    return array_merge([
        'uid' => 1,
        'message_id' => '<a@x>',
        'subject' => 'Angebot',
        'date' => '2026-09-01T10:00:00+00:00',
        'is_read' => true,
    ], $werte);
}

it('puts messages of one chain into one bundle', function () {
    $ketten = (new ThreadGrouper)->group([
        zeile(['uid' => 1, 'message_id' => '<a@x>']),
        zeile(['uid' => 2, 'message_id' => '<b@x>', 'in_reply_to' => '<a@x>', 'subject' => 'Re: Angebot']),
    ]);

    expect($ketten)->toHaveCount(1)
        ->and($ketten[0]['message_count'])->toBe(2);
});

it('names a chain after its beginning, not its last reply', function () {
    // Otherwise a conversation renames itself with every "Re:" and nobody
    // finds it again.
    $ketten = (new ThreadGrouper)->group([
        zeile(['uid' => 1, 'message_id' => '<a@x>', 'subject' => 'Angebot', 'date' => '2026-09-01T10:00:00+00:00']),
        zeile(['uid' => 2, 'message_id' => '<b@x>', 'in_reply_to' => '<a@x>', 'subject' => 'Re: Angebot', 'date' => '2026-09-02T10:00:00+00:00']),
    ]);

    expect($ketten[0]['subject'])->toBe('Angebot');
});

it('orders messages within a chain chronologically', function () {
    $ketten = (new ThreadGrouper)->group([
        zeile(['uid' => 2, 'message_id' => '<b@x>', 'in_reply_to' => '<a@x>', 'date' => '2026-09-02T10:00:00+00:00']),
        zeile(['uid' => 1, 'message_id' => '<a@x>', 'date' => '2026-09-01T10:00:00+00:00']),
    ]);

    expect(array_column($ketten[0]['messages'], 'uid'))->toBe([1, 2]);
});

it('puts the most recent chain on top', function () {
    $ketten = (new ThreadGrouper)->group([
        zeile(['uid' => 1, 'message_id' => '<alt@x>', 'subject' => 'Alt', 'date' => '2026-09-01T10:00:00+00:00']),
        zeile(['uid' => 2, 'message_id' => '<neu@x>', 'subject' => 'Neu', 'date' => '2026-09-05T10:00:00+00:00']),
    ]);

    expect($ketten[0]['subject'])->toBe('Neu');
});

it('falls back to the subject when the headers give nothing', function () {
    $ketten = (new ThreadGrouper)->group([
        zeile(['uid' => 1, 'message_id' => null, 'subject' => 'Angebot']),
        zeile(['uid' => 2, 'message_id' => null, 'subject' => 'Re: Angebot']),
    ]);

    expect($ketten)->toHaveCount(1);
});

it('marks the whole chain unread when one message is', function () {
    $ketten = (new ThreadGrouper)->group([
        zeile(['uid' => 1, 'message_id' => '<a@x>', 'is_read' => true]),
        zeile(['uid' => 2, 'message_id' => '<b@x>', 'in_reply_to' => '<a@x>', 'is_read' => false]),
    ]);

    expect($ketten[0]['has_unread'])->toBeTrue();
});

it('files a stored reply into its chain', function () {
    // The mailbox only carries own replies in the sent folder. Without this
    // the chain is a row of other people's messages with gaps.
    $ketten = (new ThreadGrouper)->group(
        [zeile(['uid' => 1, 'message_id' => '<a@x>'])],
        [['thread_id' => 'a@x', 'subject' => 'Re: Angebot', 'date' => '2026-09-03T10:00:00+00:00']],
    );

    expect($ketten[0]['message_count'])->toBe(2)
        ->and(end($ketten[0]['messages'])['source'])->toBe('stored')
        ->and(end($ketten[0]['messages'])['direction'])->toBe('outbound');
});

it('lets a stored reply without a visible chain open no chain of its own', function () {
    // Otherwise conversations appear in the inbox that hold nothing from the
    // inbox.
    $ketten = (new ThreadGrouper)->group(
        [zeile(['uid' => 1, 'message_id' => '<a@x>', 'subject' => 'Angebot'])],
        [['thread_id' => 'voellig@anderes', 'subject' => 'Woanders']],
    );

    expect($ketten)->toHaveCount(1)
        ->and($ketten[0]['message_count'])->toBe(1);
});

it('treats a single message as a chain of one', function () {
    $ketten = (new ThreadGrouper)->group([zeile()]);

    expect($ketten)->toHaveCount(1)
        ->and($ketten[0]['message_count'])->toBe(1);
});

it('leaves a chain without any subject unnamed', function () {
    // No label from the package: what a reader sees instead is the product's
    // decision — and its language.
    $ketten = (new ThreadGrouper)->group([zeile(['message_id' => '<a@x>', 'subject' => null])]);

    expect($ketten[0]['subject'])->toBeNull();
});
