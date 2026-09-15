<?php

use Peppermint\Mailbox\Imap\FolderPaths;

describe('unter welchem Praefix ein Postfach seine Ordner haelt', function () {
    it('erkennt ein Postfach, das alles unter INBOX haelt', function () {
        expect(FolderPaths::inboxPrefix(['INBOX', 'INBOX.Sent', 'INBOX.Trash']))->toBe('INBOX.');
    });

    it('liest das Trennzeichen vom Postfach, statt es anzunehmen', function () {
        // Manche Server trennen mit Punkt, andere mit Schraegstrich.
        expect(FolderPaths::inboxPrefix(['INBOX', 'INBOX/Sent']))->toBe('INBOX/');
    });

    it('meldet keinen Praefix bei einem flachen Postfach', function () {
        expect(FolderPaths::inboxPrefix(['INBOX', 'Sent', 'Archiv']))->toBeNull();
    });

    it('laesst sich von INBOX allein nicht taeuschen', function () {
        // „INBOX" ohne Trennzeichen dahinter sagt nichts ueber die Struktur.
        expect(FolderPaths::inboxPrefix(['INBOX']))->toBeNull();
    });
});

describe('wo ein Standardordner angelegt wird', function () {
    it('versucht zuerst unter dem Praefix, wenn das Postfach einen hat', function () {
        expect(FolderPaths::standardCandidates('Sent', 'INBOX.'))->toBe(['INBOX.Sent', 'Sent']);
    });

    it('versucht zuerst oben, wenn es keinen Praefix gibt', function () {
        expect(FolderPaths::standardCandidates('Sent', null))->toBe(['Sent', 'INBOX.Sent']);
    });

    it('bietet immer beide Moeglichkeiten an', function () {
        // Ein abgelehntes Anlegen ist billig; ein Ordner an der falschen
        // Stelle ist es nicht.
        expect(FolderPaths::standardCandidates('Trash', 'INBOX.'))->toHaveCount(2);
    });
});

describe('welchen Pfad ein umbenannter Ordner bekommt', function () {
    it('aendert nur den letzten Teil', function () {
        // Ein blosses „2027" wuerde den Ordner nicht umbenennen, sondern nach
        // ganz oben verschieben — stillschweigend, samt seiner Post.
        expect(FolderPaths::renamed('Projekte/2026', '2027'))->toBe('Projekte/2027');
    });

    it('kommt mit dem Punkt als Trennzeichen zurecht', function () {
        expect(FolderPaths::renamed('INBOX.Alt', 'Neu', '.'))->toBe('INBOX.Neu');
    });

    it('benennt einen Ordner der obersten Ebene einfach um', function () {
        expect(FolderPaths::renamed('Archiv', 'Ablage'))->toBe('Ablage');
    });

    it('laesst tiefe Verschachtelung unangetastet', function () {
        expect(FolderPaths::renamed('A/B/C/D', 'E'))->toBe('A/B/C/E');
    });
});

describe('ob ein Ordner auf einen Namen hoert', function () {
    it('erkennt ihn am Namen', function () {
        expect(FolderPaths::matchesAny('Gesendet', 'INBOX.X', ['gesendet']))->toBeTrue();
    });

    it('erkennt ihn am letzten Teil des Pfades', function () {
        expect(FolderPaths::matchesAny('X', 'INBOX.Sent', ['sent']))->toBeTrue();
        expect(FolderPaths::matchesAny('X', '[Gmail]/Sent', ['sent']))->toBeTrue();
    });

    it('verwechselt keine Ordner, die nur aehnlich heissen', function () {
        expect(FolderPaths::matchesAny('Sentimentales', 'Sentimentales', ['sent']))->toBeFalse();
    });
});

describe('welche Standardordner fehlen', function () {
    it('meldet alle bei einem frischen Postfach', function () {
        // Ohne Gesendet kann die Anwendung nicht ablegen, was sie verschickt;
        // ohne Archiv nicht archivieren. Beides faellt spaeter auf, weit weg
        // von der Ursache.
        $fehlend = FolderPaths::missingStandardFolders([['name' => 'INBOX', 'path' => 'INBOX']]);

        expect($fehlend)->toBe(['Sent', 'Drafts', 'Trash', 'Archive']);
    });

    it('meldet nichts, wenn alle da sind', function () {
        $fehlend = FolderPaths::missingStandardFolders([
            ['name' => 'Gesendete Elemente', 'path' => 'INBOX.Gesendete Elemente'],
            ['name' => 'Entwürfe', 'path' => 'INBOX.Entwürfe'],
            ['name' => 'Papierkorb', 'path' => 'INBOX.Papierkorb'],
            ['name' => 'Archiv', 'path' => 'INBOX.Archiv'],
        ]);

        expect($fehlend)->toBe([]);
    });

    it('meldet nur das, was wirklich fehlt', function () {
        $fehlend = FolderPaths::missingStandardFolders([
            ['name' => 'Sent', 'path' => 'Sent'],
            ['name' => 'Trash', 'path' => 'Trash'],
        ]);

        expect($fehlend)->toBe(['Drafts', 'Archive']);
    });
});
