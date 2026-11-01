<?php

use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Mockery\MockInterface;
use Peppermint\Mailbox\Contracts\AccountStore;
use Peppermint\Mailbox\Contracts\Mailbox;
use Peppermint\Mailbox\Exceptions\AnhangNichtVerwendbar;
use Peppermint\Mailbox\Http\HandlesWerkzeuge;
use Peppermint\Mailbox\Models\MailAccount;
use Peppermint\Mailbox\Werkzeuge\Ergebnis;
use Peppermint\Mailbox\Werkzeuge\FremdeWerkzeuge;
use Peppermint\Mailbox\Werkzeuge\GesamtWerkzeuge;
use Peppermint\Mailbox\Werkzeuge\Nachrichtenfund;
use Peppermint\Mailbox\Werkzeuge\Vorgang;
use Peppermint\Mailbox\Werkzeuge\VorgangsNachricht;
use Peppermint\Mailbox\Werkzeuge\Vorgangswerkzeug;
use Peppermint\Mailbox\Werkzeuge\Werkzeug;
use Peppermint\Mailbox\Werkzeuge\Werkzeugquelle;
use Peppermint\Mailbox\Werkzeuge\Werkzeugverzeichnis;

/**
 * Die Mail eines Vorgangs finden — und der Anhang ueber seinen NAMEN (#9145).
 *
 * Warum das ueberhaupt noetig ist: Ein {@see Vorgang} benennt die Mail
 * fachlich (Adresse, Kette, `Message-ID`), und das ist richtig so. Drei Verben
 * brauchen aber die Mail selbst — „Als Eingangsrechnung" die Bytes eines
 * Anhangs, „Aufgabe anhaengen" Rumpf und Anhaenge, „Ticket erstellen" den
 * Rumpf. Genau deshalb fuehrten sie bis v0.203.0 nur ins andere System.
 *
 * Der teure Teil ist nicht das Finden, sondern die PRUEFUNG. Ordner und
 * Kennung sind mitgeteilt; wer sie ungeprueft benutzt, verbucht irgendwann
 * den Anhang einer anderen Mail, und niemand merkt es.
 */
function fundVorgang(array $zusatz = [], string $nachricht = '<a@example.test>'): Vorgang
{
    return new Vorgang(
        postfach: 'einkauf@peppermint-digital.de',
        kette: 'kette-1',
        nachricht: $nachricht,
        betreff: 'Rechnung 4711',
        absender: 'lieferant@example.test',
        zusatz: $zusatz,
    );
}

function fundKonto(): MailAccount
{
    return MailAccount::fromRemote([
        'id' => 1,
        'email' => 'einkauf@peppermint-digital.de',
        'label' => 'Einkauf',
        'auth_type' => 'password',
        'username' => 'einkauf',
        'password' => 'geheim',
    ]);
}

/** Eine Mail, wie `message()` sie herausgibt — nur die Felder, um die es hier geht. */
function fundMail(string $messageId = '<a@example.test>', array $anhaenge = []): array
{
    return [
        'uid' => 7,
        'message_id' => $messageId,
        'subject' => 'Rechnung 4711',
        'from_address' => 'lieferant@example.test',
        'body_text' => 'Im Anhang die Rechnung.',
        'attachments' => $anhaenge,
    ];
}

describe('die Mail finden', function () {
    it('nimmt Ordner und Kennung, die das fragende System mitgeschickt hat', function () {
        $postfach = Mockery::mock(Mailbox::class);
        $postfach->shouldReceive('message')->once()->with('INBOX', '7')->andReturn(fundMail());
        // Der sichere Weg darf NICHT suchen — sonst kostet jeder Aufruf eine
        // Volltextsuche, und das merkt niemand, bis das Postfach gross ist.
        $postfach->shouldNotReceive('findByToken');

        $fund = (new VorgangsNachricht)->finden(
            fundVorgang(['folder' => 'INBOX', 'uid' => '7']),
            fundKonto(),
            $postfach,
        );

        expect($fund)->not->toBeNull()
            ->and($fund->ordner)->toBe('INBOX')
            ->and($fund->kennung)->toBe('7')
            ->and($fund->nachricht['subject'])->toBe('Rechnung 4711');
    });

    it('verwirft die mitgeteilte Stelle, wenn dort eine ANDERE Mail liegt', function () {
        /*
         * Der Kern dieser Klasse. Eine Kennung aus einem fremden System kann
         * auf eine andere Mail zeigen (anderes Protokoll, neue
         * `UIDVALIDITY`). Wird das nicht geprueft, verbucht „Als
         * Eingangsrechnung" den Anhang der falschen Mail.
         *
         * Geprueft wird hier zugleich, dass dann der Rueckfall greift und
         * nicht einfach aufgegeben wird.
         */
        $postfach = Mockery::mock(Mailbox::class);
        $postfach->shouldReceive('message')->with('INBOX', '7')->andReturn(fundMail('<fremd@example.test>'));
        $postfach->shouldReceive('findByToken')->with('INBOX', 'a@example.test', 10)->andReturn([99]);
        $postfach->shouldReceive('message')->with('INBOX', 99)->andReturn(fundMail());

        $fund = (new VorgangsNachricht)->finden(
            fundVorgang(['folder' => 'INBOX', 'uid' => '7']),
            fundKonto(),
            $postfach,
        );

        expect($fund)->not->toBeNull()->and($fund->kennung)->toBe(99);
    });

    it('sucht die Message-ID, wenn keine Stelle mitgeteilt wurde', function () {
        $postfach = Mockery::mock(Mailbox::class);
        // Ohne spitze Klammern: Manche Server geben sie mit heraus, manche
        // nicht, und eine Volltextsuche nach `<…>` findet dann nichts.
        $postfach->shouldReceive('findByToken')->once()->with('INBOX', 'a@example.test', 10)->andReturn([7]);
        $postfach->shouldReceive('message')->with('INBOX', 7)->andReturn(fundMail());

        $fund = (new VorgangsNachricht)->finden(fundVorgang(), fundKonto(), $postfach);

        expect($fund)->not->toBeNull()->and($fund->kennung)->toBe(7);
    });

    it('ueberspringt beim Suchen jeden Treffer, der die Kennung nur ZITIERT', function () {
        /*
         * `findByToken` sucht im Volltext, weil IMAP nichts anderes anbietet.
         * Eine Antwort, die die `Message-ID` im Zitat traegt, ist ein Treffer
         * und nicht die Mail.
         */
        $postfach = Mockery::mock(Mailbox::class);
        $postfach->shouldReceive('findByToken')->andReturn([41, 42]);
        $postfach->shouldReceive('message')->with('INBOX', 41)->andReturn(fundMail('<antwort@example.test>'));
        $postfach->shouldReceive('message')->with('INBOX', 42)->andReturn(fundMail());

        $fund = (new VorgangsNachricht)->finden(fundVorgang(), fundKonto(), $postfach);

        expect($fund)->not->toBeNull()->and($fund->kennung)->toBe(42);
    });

    it('sucht in dem Ordner, der mitgeteilt wurde — nicht im Posteingang', function () {
        // Eine Mail, die jemand weggeraeumt hat: Der Ordner steht in der
        // Angabe, nur die Kennung passt nicht mehr.
        $postfach = Mockery::mock(Mailbox::class);
        $postfach->shouldReceive('findByToken')->once()->with('Archiv/2026', 'a@example.test', 10)->andReturn([5]);
        $postfach->shouldReceive('message')->with('Archiv/2026', 5)->andReturn(fundMail());

        $fund = (new VorgangsNachricht)->finden(fundVorgang(['folder' => 'Archiv/2026']), fundKonto(), $postfach);

        expect($fund->ordner)->toBe('Archiv/2026');
    });

    it('vergleicht Kennungen ohne spitze Klammern — sonst faellt der sichere Weg still aus', function () {
        $postfach = Mockery::mock(Mailbox::class);
        $postfach->shouldReceive('message')->with('INBOX', '7')->andReturn(fundMail('a@example.test'));
        $postfach->shouldNotReceive('findByToken');

        $fund = (new VorgangsNachricht)->finden(
            fundVorgang(['folder' => 'INBOX', 'uid' => '7'], '<a@example.test>'),
            fundKonto(),
            $postfach,
        );

        expect($fund)->not->toBeNull();
    });

    it('gibt null, wenn die Mail in diesem Postfach nicht zu finden ist', function () {
        $postfach = Mockery::mock(Mailbox::class);
        $postfach->shouldReceive('findByToken')->andReturn([]);

        expect((new VorgangsNachricht)->finden(fundVorgang(), fundKonto(), $postfach))->toBeNull();
    });

    it('fragt gar nicht, wenn der Vorgang keine Message-ID hat', function () {
        $postfach = Mockery::mock(Mailbox::class);
        $postfach->shouldNotReceive('message');
        $postfach->shouldNotReceive('findByToken');

        expect((new VorgangsNachricht)->finden(fundVorgang(nachricht: ''), fundKonto(), $postfach))->toBeNull();
    });

    it('nimmt eine leere Kennung nicht als Stelle', function () {
        // `uid: ''` ist keine Angabe. Sie als Stelle zu benutzen hiesse,
        // `message($ordner, '')` zu fragen — und IMAP macht daraus die 0.
        $postfach = Mockery::mock(Mailbox::class);
        $postfach->shouldReceive('findByToken')->once()->andReturn([]);
        $postfach->shouldNotReceive('message');

        expect((new VorgangsNachricht)->finden(
            fundVorgang(['folder' => 'INBOX', 'uid' => '']),
            fundKonto(),
            $postfach,
        ))->toBeNull();
    });
});

describe('der Anhang ueber seinen Namen', function () {
    function fundMit(array $anhaenge, ?Mailbox $postfach = null): Nachrichtenfund
    {
        $postfach ??= Mockery::mock(Mailbox::class);

        if ($postfach instanceof MockInterface) {
            $postfach->shouldReceive('message')->with('INBOX', '7')->andReturn(fundMail(anhaenge: $anhaenge));
        }

        return (new VorgangsNachricht)->finden(
            fundVorgang(['folder' => 'INBOX', 'uid' => '7']),
            fundKonto(),
            $postfach,
        );
    }

    it('rechnet den Namen in die Stelle DIESER Mail um', function () {
        /*
         * Das ist die Falle, gegen die das Paket den Namen statt der Stelle
         * liefert: Das Logo in der Signatur steht vor der Rechnung, und eine
         * Zaehlung aus einem anderen System traefe es.
         */
        $postfach = Mockery::mock(Mailbox::class);
        $postfach->shouldReceive('message')->andReturn(fundMail(anhaenge: [
            ['index' => 0, 'filename' => 'logo.png'],
            ['index' => 1, 'filename' => 'rechnung.pdf'],
        ]));
        $postfach->shouldReceive('attachment')->once()->with('INBOX', '7', 1)
            ->andReturn(['filename' => 'rechnung.pdf', 'mime_type' => 'application/pdf', 'contents' => '%PDF']);

        $fund = fundMit([], $postfach);

        expect($fund->anhang('rechnung.pdf')['contents'])->toBe('%PDF');
    });

    it('nimmt die Stelle aus der Liste, nicht die Position in ihr', function () {
        /*
         * `attachments` zaehlt JEDEN Teil der Mail mit, eingebettete Bilder
         * eingeschlossen — die Liste auf dem Schirm laesst eingebettete
         * Bilder aber weg. Die Stellen sind also nicht fortlaufend, und wer
         * sie durchzaehlt, greift daneben.
         */
        $postfach = Mockery::mock(Mailbox::class);
        $postfach->shouldReceive('message')->andReturn(fundMail(anhaenge: [
            ['index' => 2, 'filename' => 'rechnung.pdf'],
            ['index' => 5, 'filename' => 'agb.pdf'],
        ]));
        $postfach->shouldReceive('attachment')->once()->with('INBOX', '7', 5)
            ->andReturn(['filename' => 'agb.pdf', 'mime_type' => 'application/pdf', 'contents' => 'AGB']);

        expect(fundMit([], $postfach)->anhang('agb.pdf')['contents'])->toBe('AGB');
    });

    it('weigert sich bei ZWEI gleichnamigen Anhaengen — statt einen zu nehmen', function () {
        /*
         * Die Leiste schluesselt ihre Anhangliste nach Dateinamen; zwei
         * gleichnamige sind dort EINE Zeile. Also weiss auch der Mensch davor
         * nicht, welchen er gewaehlt hat — und einen davon zu verbuchen ist
         * dieselbe Wette, gegen die der Name geliefert wird.
         */
        $postfach = Mockery::mock(Mailbox::class);
        $postfach->shouldReceive('message')->andReturn(fundMail(anhaenge: [
            ['index' => 0, 'filename' => 'rechnung.pdf'],
            ['index' => 1, 'filename' => 'rechnung.pdf'],
        ]));
        $postfach->shouldNotReceive('attachment');

        expect(fn () => fundMit([], $postfach)->anhang('rechnung.pdf'))
            ->toThrow(AnhangNichtVerwendbar::class, 'Zwei Anhaenge dieser Mail heissen „rechnung.pdf“');
    });

    it('sagt es, wenn diese Mail den Namen gar nicht traegt — und nennt ihn', function () {
        $postfach = Mockery::mock(Mailbox::class);
        $postfach->shouldReceive('message')->andReturn(fundMail(anhaenge: [['index' => 0, 'filename' => 'logo.png']]));

        expect(fn () => fundMit([], $postfach)->anhang('rechnung.pdf'))
            ->toThrow(AnhangNichtVerwendbar::class, 'keinen Anhang „rechnung.pdf“');
    });

    it('sagt es, wenn die Stelle keine Bytes mehr hergibt', function () {
        $postfach = Mockery::mock(Mailbox::class);
        $postfach->shouldReceive('message')->andReturn(fundMail(anhaenge: [['index' => 0, 'filename' => 'rechnung.pdf']]));
        $postfach->shouldReceive('attachment')->andReturn(null);

        expect(fn () => fundMit([], $postfach)->anhang('rechnung.pdf'))
            ->toThrow(AnhangNichtVerwendbar::class, 'liess sich nicht holen');
    });

    it('holt mehrere Anhaenge in der Reihenfolge der NAMEN', function () {
        $postfach = Mockery::mock(Mailbox::class);
        $postfach->shouldReceive('message')->andReturn(fundMail(anhaenge: [
            ['index' => 0, 'filename' => 'a.pdf'],
            ['index' => 1, 'filename' => 'b.pdf'],
        ]));
        $postfach->shouldReceive('attachment')->with('INBOX', '7', 0)
            ->andReturn(['filename' => 'a.pdf', 'mime_type' => 'application/pdf', 'contents' => 'A']);
        $postfach->shouldReceive('attachment')->with('INBOX', '7', 1)
            ->andReturn(['filename' => 'b.pdf', 'mime_type' => 'application/pdf', 'contents' => 'B']);

        $anhaenge = fundMit([], $postfach)->anhaenge(['b.pdf', 'a.pdf']);

        expect(array_column($anhaenge, 'contents'))->toBe(['B', 'A']);
    });

    it('scheitert ganz, wenn EINER von mehreren fehlt — nicht halb', function () {
        // Eine Aufgabe mit zwei von drei Dateien daran sieht vollstaendig aus
        // und ist es nicht.
        $postfach = Mockery::mock(Mailbox::class);
        $postfach->shouldReceive('message')->andReturn(fundMail(anhaenge: [['index' => 0, 'filename' => 'a.pdf']]));
        $postfach->shouldReceive('attachment')->andReturn(['filename' => 'a.pdf', 'mime_type' => 'application/pdf', 'contents' => 'A']);

        expect(fn () => fundMit([], $postfach)->anhaenge(['a.pdf', 'fehlt.pdf']))
            ->toThrow(AnhangNichtVerwendbar::class);
    });
});

describe('der Platz fuer Ordner und Kennung in der Drahtform', function () {
    /*
     * `Vorgang::zusatz` stand seit dem ersten Tag im Vertrag und wurde von
     * NIEMANDEM gefuellt — nicht von der HTTP-Seite, nicht von den
     * MCP-Werkzeugen. „Ticket erstellen" liest seine Beschreibung daraus und
     * legte deshalb Tickets ohne Beschreibung an, sobald es aus der
     * Werkzeugleiste kam. Diese Tests sind der Abrufer, der gefehlt hat.
     */
    class ZusatzProbeWerkzeug implements Vorgangswerkzeug
    {
        public ?Vorgang $gesehen = null;

        public function werkzeug(): Werkzeug
        {
            return new Werkzeug(schluessel: 'beleg', wort: 'Als Eingangsrechnung');
        }

        public function anwendbar(Vorgang $vorgang, Model $person): bool
        {
            $this->gesehen = $vorgang;

            return true;
        }

        public function zustand(Vorgang $vorgang, Model $person): ?array
        {
            return null;
        }

        public function verweis(Vorgang $vorgang, Model $person): ?string
        {
            return null;
        }

        public function ausfuehren(Vorgang $vorgang, array $daten, Model $person): Ergebnis
        {
            $this->gesehen = $vorgang;

            return Ergebnis::gelungen('Verbucht.');
        }
    }

    class StummeZusatzQuelle implements Werkzeugquelle
    {
        public function anbieter(): array
        {
            return [];
        }

        public function holen(string $slug, Vorgang $vorgang, string $adresse): array
        {
            return ['tools' => [], 'failed' => []];
        }

        public function ausloesen(string $slug, string $schluessel, Vorgang $vorgang, array $daten, string $adresse): array
        {
            return ['ok' => true, 'message' => 'Erledigt.'];
        }
    }

    function zusatzController(ZusatzProbeWerkzeug $werkzeug): object
    {
        $verzeichnis = new Werkzeugverzeichnis(app());
        $verzeichnis->vergessen();
        $verzeichnis->anmelden($werkzeug);

        app()->instance(GesamtWerkzeuge::class, new GesamtWerkzeuge($verzeichnis, new FremdeWerkzeuge(new StummeZusatzQuelle)));
        app()->instance(AccountStore::class, new class implements AccountStore
        {
            public function all(?int $ownerId = null): Collection
            {
                return collect();
            }

            public function find(string|int $id): ?MailAccount
            {
                return fundKonto();
            }

            public function isWritable(): bool
            {
                return false;
            }
        });

        return new class
        {
            use HandlesWerkzeuge;

            protected function werkzeugPerson(): ?Model
            {
                $person = new class extends Model
                {
                    protected $guarded = [];
                };
                $person->setAttribute('email', 'stelljes@peppermint-digital.de');

                return $person;
            }

            protected function guardWerkzeuge(int $account): void {}
        };
    }

    it('reicht Ordner und Kennung aus dem Auslösen bis ins Verb durch', function () {
        $werkzeug = new ZusatzProbeWerkzeug;
        $anfrage = Request::create('/mailbox/1/tools/run', 'POST', [
            'action' => 'beleg',
            'message_id' => '<a@example.test>',
            'extra' => ['folder' => 'INBOX', 'uid' => '7'],
            'input' => ['anhang' => 'rechnung.pdf'],
        ]);
        $anfrage->headers->set('Accept', 'application/json');

        zusatzController($werkzeug)->runTool($anfrage, 1);

        expect($werkzeug->gesehen->zusatz['folder'])->toBe('INBOX')
            ->and($werkzeug->gesehen->zusatz['uid'])->toBe('7');
    });

    it('reicht auch beim AUFLISTEN durch — als Abfragezeichenfolge', function () {
        $werkzeug = new ZusatzProbeWerkzeug;
        $anfrage = Request::create('/mailbox/1/tools', 'GET', [
            'message_id' => '<a@example.test>',
            'extra' => ['folder' => 'Archiv/2026', 'uid' => '99'],
        ]);
        $anfrage->headers->set('Accept', 'application/json');

        zusatzController($werkzeug)->tools($anfrage, 1);

        expect($werkzeug->gesehen->zusatz['folder'])->toBe('Archiv/2026');
    });

    it('nimmt NUR die Schluessel an, die es kennt', function () {
        /*
         * Eine Weissliste, nicht eine offene Tuer. Was ein Browser sonst
         * hineinlegt, darf kein Verb erreichen — es koennte es fuer geprueft
         * halten.
         */
        $werkzeug = new ZusatzProbeWerkzeug;
        $anfrage = Request::create('/mailbox/1/tools/run', 'POST', [
            'action' => 'beleg',
            'message_id' => '<a@example.test>',
            'extra' => ['folder' => 'INBOX', 'uid' => '7', 'lieferant_id' => '666'],
        ]);
        $anfrage->headers->set('Accept', 'application/json');

        zusatzController($werkzeug)->runTool($anfrage, 1);

        expect($werkzeug->gesehen->zusatz)->not->toHaveKey('lieferant_id');
    });

    it('legt in_reply_to und references dazu — sie kommen ohnehin an', function () {
        $werkzeug = new ZusatzProbeWerkzeug;
        $anfrage = Request::create('/mailbox/1/tools/run', 'POST', [
            'action' => 'beleg',
            'message_id' => '<a@example.test>',
            'in_reply_to' => '<vorher@example.test>',
            'references' => '<ganz-vorher@example.test> <vorher@example.test>',
        ]);
        $anfrage->headers->set('Accept', 'application/json');

        zusatzController($werkzeug)->runTool($anfrage, 1);

        expect($werkzeug->gesehen->zusatz['in_reply_to'])->toBe('<vorher@example.test>')
            ->and($werkzeug->gesehen->zusatz['references'])->toBe('<ganz-vorher@example.test> <vorher@example.test>');
    });

    it('laesst den Zusatz leer, wenn nichts mitkam — und bricht nicht ab', function () {
        $werkzeug = new ZusatzProbeWerkzeug;
        $anfrage = Request::create('/mailbox/1/tools/run', 'POST', [
            'action' => 'beleg',
            'message_id' => '<a@example.test>',
        ]);
        $anfrage->headers->set('Accept', 'application/json');

        $antwort = zusatzController($werkzeug)->runTool($anfrage, 1);

        expect($antwort->getData(true)['ok'])->toBeTrue()
            ->and($werkzeug->gesehen->zusatz)->toBe([]);
    });
});
