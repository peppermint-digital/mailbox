<?php

use Illuminate\Http\Request;
use Peppermint\Mailbox\Contracts\Mailbox;
use Peppermint\Mailbox\Exceptions\EntwurfsAnhaengeNichtLesbar;
use Peppermint\Mailbox\Http\BearbeitetEntwuerfe;
use Peppermint\Mailbox\Mailboxes;
use Peppermint\Mailbox\Models\MailAccount;

/**
 * Einen Entwurf weiterbearbeiten (31.10.2026).
 *
 * Gemeldet von Bastian: „In der Verwaltung lassen sich Entwuerfe im
 * Mail-Browser nicht weiterbearbeiten, im Manager schon." Die Erkennung des
 * Ordners war nie das Problem (siehe FolderNamesTest und folders.test.ts) —
 * es fehlte der Weg, einen Entwurf zu LADEN und ihn beim Senden abzuloesen.
 *
 * Geprueft wird hier genau dieser Weg, und besonders die drei Dinge, die im
 * Manager seit Bug #1155 teuer erkauft sind: der Rueckfall auf den Text, die
 * Anhaenge aus dem Entwurf, und dass der Entwurf erst nach erfolgreichem
 * Versand weicht.
 */
class TestControllerFuerEntwuerfe
{
    use BearbeitetEntwuerfe;

    public function laden(Request $request, MailAccount $konto): ?array
    {
        return $this->entwurfAusAnfrage($request, $konto);
    }

    public function anhaenge(MailAccount $konto, ?string $ordner, int|string|null $uid): array
    {
        return $this->entwurfsAnhaenge($konto, $ordner, $uid);
    }

    public function abloesen(MailAccount $konto, ?string $ordner, int|string|null $uid, ?bool $kopie): void
    {
        $this->entwurfAbloesen($konto, $ordner, $uid, $kopie);
    }
}

function entwurfsKonto(): MailAccount
{
    return MailAccount::fromRemote([
        'id' => 1,
        'email' => 'henneberg@example.test',
        'label' => 'Henneberg',
        'auth_type' => 'password',
        'username' => 'henneberg',
        'password' => 'geheim',
    ]);
}

function entwurfsAnfrage(string $ordner, int|string|null $uid): Request
{
    return Request::create('/mailbox/compose', 'GET', array_filter([
        'draft_folder' => $ordner,
        'draft_uid' => $uid,
    ], static fn ($w) => $w !== null));
}

afterEach(fn () => Mailboxes::fake(null));

/*
 * Die echten Ordnernamen, nicht die bequemen. `Entw&APw-rfe` ist die
 * Schreibweise, mit der Office 365 den deutschen Entwurfsordner meldet
 * (RFC 3501, modifiziertes UTF-7) — und genau das Postfach, um das es ging.
 */
it('laedt einen Entwurf aus jedem Ordner, der wirklich vorkommt', function (string $ordner) {
    Mailboxes::fake(function () use ($ordner) {
        $attrappe = Mockery::mock(Mailbox::class);
        $attrappe->shouldReceive('message')->with($ordner, 7)->andReturn([
            'to' => [['email' => 'kunde@example.test', 'name' => 'Kunde']],
            'cc' => [],
            'subject' => 'Angebot',
            'body_html' => '<p>Guten Tag</p>',
            'attachments' => [],
        ]);

        return $attrappe;
    });

    $entwurf = (new TestControllerFuerEntwuerfe)->laden(entwurfsAnfrage($ordner, 7), entwurfsKonto());

    expect($entwurf)->not->toBeNull()
        ->and($entwurf['folder'])->toBe($ordner)
        ->and($entwurf['uid'])->toBe(7)
        ->and($entwurf['subject'])->toBe('Angebot')
        ->and($entwurf['body_html'])->toBe('<p>Guten Tag</p>');
})->with(['Entwürfe', 'Entw&APw-rfe', 'Drafts', 'INBOX.Drafts']);

it('laesst die Maske nicht leer, wenn der Entwurf nur Text hat', function () {
    /*
     * Ein Entwurf ohne HTML-Teil. Ohne Rueckfall sieht der Mensch eine leere
     * Maske und schreibt alles noch einmal.
     *
     * NICHT belegt ist, dass das haeufig vorkommt — hier stand bis zum
     * 31.10.2026 „nanoclaw-Entwuerfe haben oft kein body_html".
     * `mail-rollout3` hat an henneberg@ vier Entwuerfe nachgesehen, darunter
     * zwei von nanoclaw: ALLE trugen `body_html`. Die Zusicherung bleibt
     * trotzdem — sie sichert einen Fall ab, den ein per IMAP-`append`
     * abgelegter Entwurf aus einem Skript jederzeit erzeugen kann, und sie
     * ist der einzige Ort, an dem er ueberhaupt geprueft wird.
     */
    Mailboxes::fake(function () {
        $attrappe = Mockery::mock(Mailbox::class);
        $attrappe->shouldReceive('message')->andReturn([
            'subject' => 'Ohne HTML',
            'body_html' => '',
            'body_text' => "Zeile eins\nZeile zwei & mehr",
        ]);

        return $attrappe;
    });

    $entwurf = (new TestControllerFuerEntwuerfe)->laden(entwurfsAnfrage('Drafts', 1), entwurfsKonto());

    // Erst entschaerft, dann umgebrochen — andersherum waere das `<br>` Text.
    expect($entwurf['body_html'])->toBe("Zeile eins<br />\nZeile zwei &amp; mehr");
});

it('gibt eine leere Maske statt einer Fehlerseite, wenn der Entwurf weg ist', function () {
    // Wer den Entwurf inzwischen woanders geloescht hat, soll weiterschreiben
    // koennen und nicht vor einem Fehler stehen.
    Mailboxes::fake(function () {
        $attrappe = Mockery::mock(Mailbox::class);
        $attrappe->shouldReceive('message')->andReturn(null);

        return $attrappe;
    });

    expect((new TestControllerFuerEntwuerfe)->laden(entwurfsAnfrage('Drafts', 1), entwurfsKonto()))->toBeNull();
});

it('fragt gar nicht erst, wenn nur eines von beiden mitkommt', function () {
    Mailboxes::fake(function () {
        $attrappe = Mockery::mock(Mailbox::class);
        $attrappe->shouldNotReceive('message');

        return $attrappe;
    });

    $controller = new TestControllerFuerEntwuerfe;

    expect($controller->laden(entwurfsAnfrage('Drafts', null), entwurfsKonto()))->toBeNull()
        ->and($controller->laden(entwurfsAnfrage('', 7), entwurfsKonto()))->toBeNull();
});

it('reicht von den Anhaengen nur Name und Groesse heraus, nicht den Inhalt', function () {
    /*
     * Bug #1155, zweite Haelfte: Zu SEHEN waren die Anhaenge eines Entwurfs
     * nicht, obwohl sie beim Senden mitgingen. Der Inhalt bleibt trotzdem
     * draussen — er blaehte die Seitenantwort um die volle Dateigroesse auf.
     */
    Mailboxes::fake(function () {
        $attrappe = Mockery::mock(Mailbox::class);
        $attrappe->shouldReceive('message')->andReturn([
            'attachments' => [
                ['filename' => 'Rechnung.pdf', 'size' => 12345, 'contents' => 'GEHEIM'],
            ],
        ]);

        return $attrappe;
    });

    $entwurf = (new TestControllerFuerEntwuerfe)->laden(entwurfsAnfrage('Drafts', 1), entwurfsKonto());

    expect($entwurf['attachments'])->toBe([['filename' => 'Rechnung.pdf', 'size' => 12345]]);
});

it('holt die Anhaenge mit Bytes aus dem Entwurf', function () {
    Mailboxes::fake(function () {
        $attrappe = Mockery::mock(Mailbox::class);
        $attrappe->shouldReceive('attachments')->with('Drafts', 9)->andReturn([
            ['filename' => 'Rechnung.pdf', 'mime_type' => 'application/pdf', 'contents' => 'PDF'],
        ]);

        return $attrappe;
    });

    expect((new TestControllerFuerEntwuerfe)->anhaenge(entwurfsKonto(), 'Drafts', 9))
        ->toBe([['contents' => 'PDF', 'filename' => 'Rechnung.pdf', 'mime' => 'application/pdf']]);
});

it('loescht den abgeloesten Entwurf endgueltig, wenn eine Kopie liegt', function (?bool $kopie) {
    /*
     * Der Vertrag nennt genau diesen Fall beim Namen: „a draft that was just
     * replaced". Ein Papierkorb, der sich mit jeder Zwischenfassung eines
     * Entwurfs fuellt, ist nichts, was jemand bestellt hat.
     *
     * `true` heisst „Kopie liegt in Gesendet", `null` heisst „der Anbieter
     * legt sie selbst ab" — in beiden Faellen ist der Entwurf entbehrlich.
     */
    Mailboxes::fake(function () {
        $attrappe = Mockery::mock(Mailbox::class);
        $attrappe->shouldReceive('purge')->once()->with('Drafts', 9)->andReturn(true);
        $attrappe->shouldNotReceive('delete');

        return $attrappe;
    });

    (new TestControllerFuerEntwuerfe)->abloesen(entwurfsKonto(), 'Drafts', 9, $kopie);
})->with([true, null]);

it('legt den Entwurf in den Papierkorb, wenn die Kopie in Gesendet ausgefallen ist', function () {
    /*
     * DIE Stelle, an der sonst alles weg waere: Die Kopie in „Gesendet" darf
     * fehlschlagen, ohne den Versand zu kippen. Wuerde der Entwurf dann
     * trotzdem endgueltig geloescht, haette der Mensch die Nachricht
     * verschickt und besaesse sie nirgends mehr.
     */
    Mailboxes::fake(function () {
        $attrappe = Mockery::mock(Mailbox::class);
        // Zwei Argumente, nicht drei: Die Papierkorb-Namen sind die Vorgabe
        // des Vertrags, und der Zug soll sie NICHT selbst mitbringen — sonst
        // haette das Paket die Liste an zwei Stellen.
        $attrappe->shouldReceive('delete')->once()->with('Drafts', 9)->andReturn(true);
        $attrappe->shouldNotReceive('purge');

        return $attrappe;
    });

    (new TestControllerFuerEntwuerfe)->abloesen(entwurfsKonto(), 'Drafts', 9, false);
});

it('verschluckt einen Fehlschlag beim Abloesen — die Nachricht ist drausssen', function () {
    Mailboxes::fake(function () {
        $attrappe = Mockery::mock(Mailbox::class);
        $attrappe->shouldReceive('purge')->andThrow(new RuntimeException('IMAP weg'));

        return $attrappe;
    });

    (new TestControllerFuerEntwuerfe)->abloesen(entwurfsKonto(), 'Drafts', 9, true);
})->throwsNoExceptions();

describe('die zwei Befunde aus dem Rollout von v0.189.0 (31.10.2026)', function () {
    it('gibt das Konto des Entwurfs mit heraus', function () {
        /*
         * Die Verfassen-Seiten setzten ihr Konto auf `accounts[0]`. Bei einem
         * Entwurf aus einem ZWEITEN Postfach ging die Nachricht ueber das
         * falsche Konto hinaus — und abgeloest wurde der Entwurf auch nicht,
         * weil das Abloesen dasselbe falsche Postfach fragt.
         *
         * Wer den Entwurf hat, hat auch sein Konto. Also reist es mit, statt
         * dass drei Produkte es sich zusammensuchen.
         */
        Mailboxes::fake(function () {
            $attrappe = Mockery::mock(Mailbox::class);
            $attrappe->shouldReceive('message')->andReturn(['subject' => 'Aus dem zweiten Postfach']);

            return $attrappe;
        });

        $entwurf = (new TestControllerFuerEntwuerfe)->laden(entwurfsAnfrage('Drafts', 3), entwurfsKonto());

        expect($entwurf['account_id'])->toBe(entwurfsKonto()->id);
    });

    it('bricht ab, statt ohne die Anhaenge des Entwurfs zu senden', function () {
        /*
         * Regression gegen den Projekt-Manager, gefunden von mail-rollout3.
         *
         * Der Zug verschluckte einen Fehlschlag beim Holen und lieferte eine
         * leere Liste — die Nachricht ging ohne ihre Anhaenge hinaus, und der
         * Absender erfuhr es nicht. Der Manager bricht ab, und das ist
         * richtig: Lieber nicht gesendet als ohne die Rechnung. Eine Mail
         * laesst sich nicht zurueckholen.
         */
        Mailboxes::fake(function () {
            $attrappe = Mockery::mock(Mailbox::class);
            $attrappe->shouldReceive('attachments')->andThrow(new RuntimeException('IMAP weg'));

            return $attrappe;
        });

        expect(fn () => (new TestControllerFuerEntwuerfe)->anhaenge(entwurfsKonto(), 'Drafts', 9))
            ->toThrow(EntwurfsAnhaengeNichtLesbar::class);
    });

    it('unterscheidet „keine Anhaenge" von „nicht ladbar"', function () {
        // Das Erste ist der Normalfall und voellig in Ordnung. Nur das Zweite
        // darf den Versand aufhalten.
        Mailboxes::fake(function () {
            $attrappe = Mockery::mock(Mailbox::class);
            $attrappe->shouldReceive('attachments')->andReturn([]);

            return $attrappe;
        });

        expect((new TestControllerFuerEntwuerfe)->anhaenge(entwurfsKonto(), 'Drafts', 9))->toBe([]);
    });
});
