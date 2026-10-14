<?php

use Peppermint\Mailbox\Folders\FolderNames;

/**
 * Der Archiv-Ordner darf nicht von der Reihenfolge des Servers abhaengen.
 *
 * `looksLike` vergleicht per Teilzeichenkette — auf „Archive" passt deshalb
 * auch `Archives/2024`. Beide Uebertragungswege nahmen bisher den ZUERST
 * gelieferten Treffer. Am 14.10.2026 an info@crewtex.de gemessen: Vor dem
 * Zusammenlegen zweier Archiv-Ordner nannte der Server `Archives` zuerst,
 * danach `Archives/2024` — dieselbe Anfrage, dieselbe Fassung, anderes Ziel.
 * Archiviertes waere im Jahresordner 2024 gelandet.
 */
test('der Elternordner schlaegt seinen Jahres-Unterordner', function () {
    // Genau die Reihenfolge, die der Server an diesem Tag lieferte.
    $ordner = ['Archives/2024', 'Drafts', 'Sent Items', 'Archives', 'Archives/2026', 'Deleted Items', 'Archives/2025', 'Junk Mail', 'Inbox'];

    expect(FolderNames::bestMatch($ordner, 'Archive'))->toBe('Archives');
});

test('das Ergebnis haengt NICHT an der Reihenfolge', function () {
    $ordner = ['Archives/2024', 'Archives', 'Archives/2026', 'Archives/2025'];

    expect(FolderNames::bestMatch($ordner, 'Archive'))->toBe('Archives')
        ->and(FolderNames::bestMatch(array_reverse($ordner), 'Archive'))->toBe('Archives')
        ->and(FolderNames::bestMatch(['Archives/2025', 'Archives/2024', 'Archives'], 'Archive'))->toBe('Archives');
});

test('bei gleich langen Treffern entscheidet die Schreibweise, nicht der Zufall', function () {
    // Zwei gleichwertige Namen: Das Ergebnis muss wenigstens reproduzierbar
    // sein, sonst wandert dieselbe Ablage mal hierhin, mal dorthin.
    $a = FolderNames::bestMatch(['Archiv', 'Ablage'], 'Archive');
    $b = FolderNames::bestMatch(['Ablage', 'Archiv'], 'Archive');

    expect($a)->toBe($b)->and($a)->toBe('Archiv');
});

test('ohne jeden Treffer bleibt es null', function () {
    expect(FolderNames::bestMatch(['Inbox', 'Drafts', 'Junk Mail'], 'Archive'))->toBeNull();
});

test('die Regel gilt fuer jede Ordnerart, nicht nur fuers Archiv', function () {
    expect(FolderNames::bestMatch(['Sent Items/2024', 'Sent Items'], 'Sent'))->toBe('Sent Items');
});
