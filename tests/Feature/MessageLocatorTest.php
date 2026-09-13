<?php

use Peppermint\Mailbox\Index\MessageLocator;

/**
 * Finding a message the index misplaced, lifted from peppermint-manager
 * (#5488, originally its bug #587).
 *
 * The symptom this prevents is specific: search finds mails that will not
 * open. And it hits other people's mail hardest, because own sent replies come
 * from the database and never take this path.
 */
function sucher(array $vorhanden, array &$vergessen): MessageLocator
{
    return new MessageLocator(
        fn (string $folder, int $uid): ?array => $vorhanden[$folder.':'.$uid] ?? null,
        function (string $folder, int $uid) use (&$vergessen): void {
            $vergessen[] = $folder.':'.$uid;
        },
    );
}

it('takes the message from where the index says', function () {
    $vergessen = [];
    $treffer = sucher(['INBOX:7' => ['subject' => 'Da']], $vergessen)->locate('INBOX', 7);

    expect($treffer['folder'])->toBe('INBOX')
        ->and($treffer['message']['subject'])->toBe('Da')
        // Nothing was wrong, so nothing gets forgotten.
        ->and($vergessen)->toBe([]);
});

it('forgets an index row that points at nothing', function () {
    // Without this the same dead row produces the same unopenable hit on every
    // single search, forever.
    $vergessen = [];
    $treffer = sucher([], $vergessen)->locate('INBOX', 7);

    expect($treffer)->toBeNull()
        ->and($vergessen)->toBe(['INBOX:7']);
});

it('follows the message to where it was moved', function () {
    $vergessen = [];
    $treffer = sucher(['Archiv:99' => ['subject' => 'Umgezogen']], $vergessen)
        ->locate('INBOX', 7, [['folder' => 'Archiv', 'uid' => 99]]);

    expect($treffer['folder'])->toBe('Archiv')
        ->and($treffer['uid'])->toBe(99)
        ->and($vergessen)->toBe(['INBOX:7']);
});

it('clears out every dead place it passes', function () {
    $vergessen = [];
    $treffer = sucher(['Archiv:99' => ['subject' => 'Da']], $vergessen)->locate('INBOX', 7, [
        ['folder' => 'Gesendet', 'uid' => 12],
        ['folder' => 'Archiv', 'uid' => 99],
    ]);

    expect($treffer['folder'])->toBe('Archiv')
        // The dead intermediate stop is gone too, not just the first one.
        ->and($vergessen)->toBe(['INBOX:7', 'Gesendet:12']);
});

it('gives up honestly instead of searching the whole mailbox', function () {
    // A search across all folders costs seconds on a large mailbox and
    // providers throttle per mailbox. "Not found" is the honest answer — the
    // next index run makes it findable.
    $versuche = 0;
    $vergessen = [];

    $sucher = new MessageLocator(
        function () use (&$versuche): ?array {
            $versuche++;

            return null;
        },
        function () use (&$vergessen): void {
            $vergessen[] = 'x';
        },
    );

    expect($sucher->locate('INBOX', 7, [['folder' => 'Archiv', 'uid' => 99]]))->toBeNull()
        ->and($versuche)->toBe(2);
});

it('does not try the same place twice', function () {
    $versuche = 0;
    $vergessen = [];

    $sucher = new MessageLocator(
        function () use (&$versuche): ?array {
            $versuche++;

            return null;
        },
        function () use (&$vergessen): void {
            $vergessen[] = 'x';
        },
    );

    $sucher->locate('INBOX', 7, [['folder' => 'INBOX', 'uid' => 7]]);

    expect($versuche)->toBe(1);
});

it('steps over a broken candidate instead of failing', function () {
    // Index rows come from a table that grew over years; a missing folder or a
    // uid of zero must not cost the message.
    $vergessen = [];
    $treffer = sucher(['Archiv:99' => ['subject' => 'Da']], $vergessen)->locate('INBOX', 7, [
        ['folder' => '', 'uid' => 5],
        ['folder' => 'Archiv', 'uid' => 0],
        ['folder' => 'Archiv', 'uid' => 99],
    ]);

    expect($treffer['uid'])->toBe(99);
});
