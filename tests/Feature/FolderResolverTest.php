<?php

use Peppermint\Mailbox\Imap\FolderResolver;

/** Ein Ordner, wie ihn eine IMAP-Bibliothek herausgibt: Pfad und Anzeigename. */
function ordner(string $pfad, string $name): object
{
    return new class($pfad, $name)
    {
        public function __construct(public string $pfad, public string $name) {}
    };
}

function aufloesen(array $ordner, string $gesucht): ?object
{
    return FolderResolver::resolve($ordner, $gesucht, fn ($o) => $o->pfad, fn ($o) => $o->name);
}

describe('welcher Ordner gemeint ist', function () {
    it('findet den Ordner ueber seinen Pfad', function () {
        $treffer = aufloesen([ordner('INBOX', 'Posteingang'), ordner('INBOX.Sent', 'Gesendet')], 'INBOX.Sent');

        expect($treffer->name)->toBe('Gesendet');
    });

    it('nimmt den ECHTEN Ordner, nicht den gleichnamigen Geisterordner', function () {
        // Der echte Entwuerfe-Ordner hat den IMAP-Pfad `Entw&APw-rfe`.
        // Daneben existiert ein leerer Ordner, der diesen String als
        // ANZEIGENAMEN traegt — eine Doppelkodierung aus seiner Vergangenheit.
        // Eine unscharfe Suche nimmt mal den einen, mal den anderen, und das
        // Postfach sieht dann einfach leer aus.
        $geist = ordner('INBOX.Junk', 'Entw&APw-rfe');
        $echt = ordner('Entw&APw-rfe', 'Entwürfe');

        expect(aufloesen([$geist, $echt], 'Entw&APw-rfe')->name)->toBe('Entwürfe');
    });

    it('nimmt den Pfad auch dann, wenn der Geisterordner zuerst in der Liste steht', function () {
        $geist = ordner('A', 'Ziel');
        $echt = ordner('Ziel', 'Etwas anderes');

        expect(aufloesen([$geist, $echt], 'Ziel')->pfad)->toBe('Ziel');
    });

    it('faellt auf den Anzeigenamen zurueck, wenn kein Pfad passt', function () {
        // Fuer Aufrufer, die einen Namen statt eines Pfades hereinreichen.
        $treffer = aufloesen([ordner('INBOX.Drafts', 'Entwürfe')], 'Entwürfe');

        expect($treffer->pfad)->toBe('INBOX.Drafts');
    });

    it('gibt nichts zurueck, wenn es den Ordner nicht gibt', function () {
        expect(aufloesen([ordner('INBOX', 'Posteingang')], 'Gibtsnicht'))->toBeNull();
    });

    it('kommt mit einer leeren Liste zurecht', function () {
        expect(aufloesen([], 'INBOX'))->toBeNull();
    });
});
