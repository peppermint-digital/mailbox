<?php

use Peppermint\Mailbox\Folders\FolderNames;

/**
 * Judging folders by their name, lifted from peppermint-manager (#5488),
 * where the same list lived in two services word for word.
 */
it('keeps trash and drafts out of a search', function () {
    // A hit from the trash is at best confusing and at worst a mail the user
    // believed was gone.
    expect(FolderNames::isExcludedFromSearch('Trash'))->toBeTrue()
        ->and(FolderNames::isExcludedFromSearch('Papierkorb'))->toBeTrue()
        ->and(FolderNames::isExcludedFromSearch('Drafts'))->toBeTrue()
        ->and(FolderNames::isExcludedFromSearch('Junk E-Mail'))->toBeTrue();
});

it('recognises the folder inside a path', function () {
    // Real folders are called this, not "Trash".
    expect(FolderNames::isExcludedFromSearch('INBOX.Trash'))->toBeTrue()
        ->and(FolderNames::isExcludedFromSearch('[Gmail]/Papierkorb'))->toBeTrue();
});

it('recognises the encoded German names Office 365 reports', function () {
    // IMAP modified UTF-7. A comparison against "gelöscht" finds nothing here,
    // and that is exactly how trashed mail ends up in search results.
    expect(FolderNames::isExcludedFromSearch('Gel&APY-schte Elemente'))->toBeTrue()
        ->and(FolderNames::isExcludedFromSearch('Entw&APw-rfe'))->toBeTrue();
});

it('leaves ordinary folders alone', function () {
    expect(FolderNames::isExcludedFromSearch('INBOX'))->toBeFalse()
        ->and(FolderNames::isExcludedFromSearch('Kunden/2026'))->toBeFalse()
        ->and(FolderNames::isExcludedFromSearch('Gesendete Elemente'))->toBeFalse();
});

it('tells which standard folder a name stands for', function () {
    expect(FolderNames::classify('Gesendete Objekte'))->toBe('Sent')
        ->and(FolderNames::classify('INBOX.Archiv'))->toBe('Archive')
        ->and(FolderNames::classify('Deleted Items'))->toBe('Trash')
        ->and(FolderNames::classify('Kunden'))->toBeNull();
});

it('answers for a single kind without walking all of them', function () {
    expect(FolderNames::looksLike('Sent Mail', 'Sent'))->toBeTrue()
        ->and(FolderNames::looksLike('Sent Mail', 'Trash'))->toBeFalse()
        // An unknown kind is false, not an error: the caller asked something
        // this class has no opinion about.
        ->and(FolderNames::looksLike('Sent Mail', 'Gibtsnicht'))->toBeFalse();
});
