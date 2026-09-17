<?php

use Peppermint\Mailbox\Models\MailAccount;

/**
 * Which protocol a mailbox is reached with.
 *
 * The field decides between two transports. Everything here guards the one
 * property that makes it safe to add: a mailbox that says nothing keeps
 * behaving exactly as it did before the field existed.
 */
it('faellt auf imap zurueck, wenn nichts dasteht', function () {
    // Der Fall eines Produkts, das seine gewachsene Tabelle uebernommen und
    // die Spalte nie angelegt hat.
    $konto = new MailAccount;

    expect($konto->transport())->toBe('imap')
        ->and($konto->usesJmap())->toBeFalse();
});

it('faellt auch bei leerem Wert auf imap zurueck', function () {
    $konto = new MailAccount;
    $konto->setAttribute('protocol', '');

    expect($konto->transport())->toBe('imap');
});

it('liest das Feld durch den Spaltennamen des Produkts', function () {
    config()->set('mailbox.columns', ['protocol' => 'mail_protocol']);

    $konto = new MailAccount;
    $konto->setAttribute('mail_protocol', 'jmap');

    expect($konto->transport())->toBe('jmap')
        ->and($konto->usesJmap())->toBeTrue();
});

it('liest es bei zentralen Konten unter dem Paketnamen', function () {
    // Zentrale Daten tragen immer die Feldnamen des Pakets, auch wenn die
    // eigene Tabelle anders heisst — sonst waere jedes Feld still null.
    config()->set('mailbox.columns', ['protocol' => 'mail_protocol']);

    $konto = MailAccount::fromRemote(['protocol' => 'jmap']);

    expect($konto->transport())->toBe('jmap');
});

describe('die Sitzungsadresse', function () {
    it('leitet sie aus dem Mail-Host ab', function () {
        // Der Standard sagt, wo das Sitzungsdokument liegt. Sie zusaetzlich zu
        // speichern waere eine zweite Kopie des Hostnamens.
        $konto = MailAccount::fromRemote([
            'protocol' => 'jmap',
            'imap_host' => 'mail.beispiel.de',
        ]);

        expect($konto->jmapSessionUrl())->toBe('https://mail.beispiel.de/.well-known/jmap');
    });

    it('nimmt die hinterlegte Adresse, wenn es eine gibt', function () {
        // Anbieter, die API und IMAP auf verschiedene Hosts legen.
        $konto = MailAccount::fromRemote([
            'protocol' => 'jmap',
            'imap_host' => 'imap.beispiel.de',
            'jmap_url' => 'https://api.beispiel.de/jmap/session',
        ]);

        expect($konto->jmapSessionUrl())->toBe('https://api.beispiel.de/jmap/session');
    });

    it('ist null, wenn es gar keinen Host gibt', function () {
        // Lieber ein klares Nein als eine erfundene Adresse, die als
        // Verbindungsfehler zurueckkommt.
        expect(MailAccount::fromRemote(['protocol' => 'jmap'])->jmapSessionUrl())->toBeNull();
    });
});
