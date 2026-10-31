<?php

use Peppermint\Mailbox\Folders\FolderNames;

/**
 * Judging folders by their name, lifted from peppermint-manager (#5488),
 * where the same list lived in two services word for word.
 */
it('keeps trash and junk out of a search', function () {
    // A hit from the trash is at best confusing and at worst a mail the user
    // believed was gone.
    expect(FolderNames::isExcludedFromSearch('Trash'))->toBeTrue()
        ->and(FolderNames::isExcludedFromSearch('Papierkorb'))->toBeTrue()
        ->and(FolderNames::isExcludedFromSearch('Junk E-Mail'))->toBeTrue();
});

it('laesst Entwuerfe aber FINDEN — sie sind der eigene Text', function () {
    /*
     * Hier stand bis zum 31.10.2026 das Gegenteil, in derselben Zusicherung
     * wie Papierkorb und Spam: „keeps trash and drafts out of a search".
     * Gebuendelt, und damit nie einzeln entschieden.
     *
     * Aufgefallen ist es, als eine Rollout-Session einen Pruefpunkt nicht
     * ausfuehren konnte: Suche nach den eigenen Testentwuerfen 0 Treffer in
     * 20 Ordnern, dieselbe Suche nach einem Wort aus dem Posteingang 34.
     *
     * Der Unterschied zu Papierkorb und Spam ist ein SICHERHEITSargument, das
     * es bei Entwuerfen nicht gibt: Dort koennte ein Treffer eine Mail sein,
     * die jemand geloescht glaubte. Ein Entwurf ist der eigene Text, nichts
     * daran ist verborgen — und seit v0.189.0 laesst er sich aus dem Treffer
     * heraus weiterbearbeiten.
     *
     * Entschieden am 31.10.2026.
     */
    expect(FolderNames::isExcludedFromSearch('Drafts'))->toBeFalse()
        ->and(FolderNames::isExcludedFromSearch('Entwürfe'))->toBeFalse()
        ->and(FolderNames::isExcludedFromSearch('INBOX.Drafts'))->toBeFalse()
        // Die kodierte Schreibweise von Office 365 — der Ordner, um den es ging.
        ->and(FolderNames::isExcludedFromSearch('Entw&APw-rfe'))->toBeFalse();
});

it('recognises the folder inside a path', function () {
    // Real folders are called this, not "Trash".
    expect(FolderNames::isExcludedFromSearch('INBOX.Trash'))->toBeTrue()
        ->and(FolderNames::isExcludedFromSearch('[Gmail]/Papierkorb'))->toBeTrue();
});

it('recognises the encoded German names Office 365 reports', function () {
    // IMAP modified UTF-7. A comparison against "gelöscht" finds nothing here,
    // and that is exactly how trashed mail ends up in search results.
    //
    // Die Entwuerfe sind aus dieser Zusicherung herausgenommen (31.10.2026);
    // dass ihre kodierte Schreibweise jetzt NICHT mehr ausschliesst, steht
    // oben als eigene Zusicherung.
    expect(FolderNames::isExcludedFromSearch('Gel&APY-schte Elemente'))->toBeTrue();
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
