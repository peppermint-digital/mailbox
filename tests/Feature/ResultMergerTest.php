<?php

use Peppermint\Mailbox\Search\ResultMerger;

/**
 * Merging search hits, lifted from peppermint-manager (#5488).
 *
 * The two sources overlap, and what happens in the overlap decides whether a
 * search result is usable: a duplicate looks like a bug, and keeping the wrong
 * copy produces a hit nobody can open.
 */
it('keeps the mailbox hit when both sources have the message', function () {
    // Only the mailbox hit has a UID, so only it can be opened. Keeping the
    // stored copy would produce a result that does nothing when clicked.
    $treffer = (new ResultMerger)->merge(
        [['message_id' => '<a@x>', 'uid' => 7, 'subject' => 'Angebot']],
        [['message_id' => '<a@x>', 'stored_id' => 3, 'subject' => 'Angebot']],
    );

    expect($treffer)->toHaveCount(1)
        ->and($treffer[0]['uid'])->toBe(7)
        ->and($treffer[0]['source'])->toBe('mailbox')
        // The reference to the archived copy survives the merge.
        ->and($treffer[0]['also_stored_id'])->toBe(3);
});

it('takes a stored hit the mailbox does not have', function () {
    // The archive also knows what was sent or filed against a task — that is
    // the whole reason for the second source.
    $treffer = (new ResultMerger)->merge(
        [['message_id' => '<a@x>', 'subject' => 'Angebot']],
        [['message_id' => '<b@x>', 'stored_id' => 3, 'subject' => 'Antwort']],
    );

    expect($treffer)->toHaveCount(2);
});

it('matches both spellings of a message id', function () {
    // Stored ids carry angle brackets, mailbox ids often do not. Without
    // normalising, every message in both sources shows up twice.
    $treffer = (new ResultMerger)->merge(
        [['message_id' => 'a@x', 'uid' => 7]],
        [['message_id' => '<a@x>', 'stored_id' => 3]],
    );

    expect($treffer)->toHaveCount(1);
});

it('falls back to subject and time when a message id is missing', function () {
    $treffer = (new ResultMerger)->merge(
        [['message_id' => null, 'subject' => 'Angebot', 'date' => '2026-09-01T10:00:00+00:00', 'uid' => 7]],
        [['message_id' => null, 'subject' => 'Angebot', 'date' => '2026-09-01T10:00:00+00:00', 'stored_id' => 3]],
    );

    expect($treffer)->toHaveCount(1)
        ->and($treffer[0]['uid'])->toBe(7);
});

it('puts the newest hit first', function () {
    $treffer = (new ResultMerger)->merge([
        ['message_id' => '<alt@x>', 'date' => '2026-09-01T10:00:00+00:00'],
        ['message_id' => '<neu@x>', 'date' => '2026-09-05T10:00:00+00:00'],
    ]);

    expect($treffer[0]['message_id'])->toBe('<neu@x>');
});

it('cuts to the limit after merging, not before', function () {
    // Cutting each source first would drop a hit that belongs in the top ten.
    $postfach = [['message_id' => '<a@x>', 'date' => '2026-09-01T10:00:00+00:00']];
    $archiv = [['message_id' => '<b@x>', 'stored_id' => 1, 'date' => '2026-09-09T10:00:00+00:00']];

    $treffer = (new ResultMerger)->merge($postfach, $archiv, 1);

    expect($treffer)->toHaveCount(1)
        ->and($treffer[0]['message_id'])->toBe('<b@x>');
});

it('marks stored hits as read and without a folder', function () {
    $treffer = (new ResultMerger)->merge([], [['message_id' => '<b@x>', 'stored_id' => 1]]);

    expect($treffer[0]['source'])->toBe('stored')
        ->and($treffer[0]['is_read'])->toBeTrue()
        ->and($treffer[0]['folder'])->toBeNull();
});

it('does not override what a stored hit already says', function () {
    // `+` keeps the left-hand side: a hit that brings its own state must not
    // be talked over by the defaults.
    $treffer = (new ResultMerger)->merge([], [['message_id' => '<b@x>', 'is_read' => false, 'folder' => 'Gesendet']]);

    expect($treffer[0]['is_read'])->toBeFalse()
        ->and($treffer[0]['folder'])->toBe('Gesendet');
});
