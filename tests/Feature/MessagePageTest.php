<?php

use Peppermint\Mailbox\Imap\MessagePage;

function seitenZeile(array $werte = []): array
{
    return array_merge(['uid' => 1, 'message_id' => '<a@b>', 'subject' => 'B', 'date' => '2026-09-14 10:00:00', 'from_address' => 'a@b.de'], $werte);
}

describe('Sortierung', function () {
    it('sortiert nach dem Datum, nicht nach der Kennung', function () {
        // Eine UID sagt, wann der Server die Mail zuerst gesehen hat — nicht,
        // wann sie geschrieben wurde. Ein gestern empfangener alter Brief
        // stuende sonst oben.
        $sortiert = MessagePage::sortByDateDesc([
            seitenZeile(['uid' => 1, 'date' => '2026-09-10 08:00:00']),
            seitenZeile(['uid' => 2, 'date' => '2026-09-14 08:00:00']),
            seitenZeile(['uid' => 3, 'date' => '2026-09-12 08:00:00']),
        ]);

        expect(array_column($sortiert, 'uid'))->toBe([2, 3, 1]);
    });

    it('stellt Zeilen ohne Datum hinten an, statt sie zu verlieren', function () {
        $sortiert = MessagePage::sortByDateDesc([
            seitenZeile(['uid' => 1, 'date' => null]),
            seitenZeile(['uid' => 2, 'date' => '2026-09-14 08:00:00']),
        ]);

        expect(array_column($sortiert, 'uid'))->toBe([2, 1]);
    });
});

describe('Doppelte aus dem Gesendet-Ordner', function () {
    it('wirft die zweite Fassung derselben Mail weg', function () {
        // Viele Server legen beim Senden selbst eine Kopie ab, waehrend der
        // Client seine eigene anhaengt. Zwei Zeilen, eine Mail.
        $zeilen = MessagePage::dedupe([
            seitenZeile(['uid' => 1, 'message_id' => '<x@y>']),
            seitenZeile(['uid' => 2, 'message_id' => '<x@y>']),
            seitenZeile(['uid' => 3, 'message_id' => '<z@y>']),
        ]);

        expect(array_column($zeilen, 'uid'))->toBe([1, 3]);
    });

    it('behaelt die ERSTE Fassung', function () {
        $zeilen = MessagePage::dedupe([seitenZeile(['uid' => 7, 'message_id' => '<x@y>']), seitenZeile(['uid' => 9, 'message_id' => '<x@y>'])]);

        expect($zeilen[0]['uid'])->toBe(7);
    });

    it('nimmt Betreff, Datum und Absender nur, wenn es keine Message-ID gibt', function () {
        $zeilen = MessagePage::dedupe([
            seitenZeile(['uid' => 1, 'message_id' => null, 'subject' => 'Gleich']),
            seitenZeile(['uid' => 2, 'message_id' => null, 'subject' => 'Gleich']),
        ]);

        expect($zeilen)->toHaveCount(1);
    });

    it('wirft NICHTS weg, wenn die Message-IDs verschieden sind — auch bei gleichem Betreff', function () {
        // Der Ersatzschluessel ist eine Vermutung. Wo es eine Message-ID gibt,
        // entscheidet sie, und zwei Mails mit gleichem Betreff bleiben zwei.
        $zeilen = MessagePage::dedupe([
            seitenZeile(['uid' => 1, 'message_id' => '<a@y>', 'subject' => 'Rechnung']),
            seitenZeile(['uid' => 2, 'message_id' => '<b@y>', 'subject' => 'Rechnung']),
        ]);

        expect($zeilen)->toHaveCount(2);
    });
});

describe('Seiten schneiden', function () {
    it('gibt die Zeilen der gewuenschten Seite', function () {
        $alle = array_map(fn ($i) => seitenZeile(['uid' => $i]), range(1, 10));

        expect(array_column(MessagePage::slice($alle, 2, 3), 'uid'))->toBe([4, 5, 6]);
    });

    it('gibt die erste Seite, wenn eine unsinnige Seitenzahl kommt', function () {
        $alle = array_map(fn ($i) => seitenZeile(['uid' => $i]), range(1, 5));

        expect(array_column(MessagePage::slice($alle, 0, 2), 'uid'))->toBe([1, 2]);
    });

    it('gibt nichts hinter dem Ende zurueck, statt zu stolpern', function () {
        expect(MessagePage::slice([seitenZeile()], 9, 25))->toBe([]);
    });
});

describe('wie viele Nachrichten die Liste behauptet zu kennen', function () {
    it('nennt nie mehr, als sie ausliefern kann', function () {
        // Die Zahl des Servers wuerde Seiten anbieten, die leer zurueckkommen.
        expect(MessagePage::effectiveTotal(5000, 100))->toBe(100);
    });

    it('nennt die echte Zahl, wenn sie kleiner ist als das Geholte', function () {
        expect(MessagePage::effectiveTotal(12, 100))->toBe(12);
    });
});

describe('Gesendet-Ordner erkennen', function () {
    it('erkennt die gebraeuchlichen Namen', function (string $pfad) {
        expect(MessagePage::isSentFolder($pfad))->toBeTrue();
    })->with(['Sent', 'INBOX.Sent', 'Gesendete Elemente', 'INBOX/Gesendet', '[Gmail]/Sent Mail']);

    it('haelt den Posteingang nicht dafuer', function () {
        expect(MessagePage::isSentFolder('INBOX'))->toBeFalse();
        expect(MessagePage::isSentFolder('Archiv'))->toBeFalse();
    });
});
