<?php

use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Peppermint\Mailbox\Contracts\Mailbox;
use Peppermint\Mailbox\Http\SpeichertEntwuerfe;
use Peppermint\Mailbox\Mailboxes;
use Peppermint\Mailbox\Models\MailAccount;

/**
 * Einen Entwurf ANLEGEN (31.10.2026).
 *
 * Gefunden von `mail-2b` beim Vergleich der vier Verfassen-Seiten:
 * `allowDraft`/`onSaveDraft` waren nur der KNOPF, den Serverweg hatte allein
 * der Projekt-Manager. CRM, Verwaltung und AI Brain konnten seit v0.189.0
 * einen Entwurf oeffnen und keinen anlegen.
 *
 * Geprueft wird vor allem die Reihenfolge, denn daran haengt der Verlust: Die
 * Anhaenge der Vorfassung werden ZUERST geholt, ein Fehlschlag dabei bricht
 * ab, und die Vorfassung weicht erst NACH dem erfolgreichen Ablegen. Das sind
 * die drei Lehren aus Bug #1155, hier fuer das Speichern statt fuer das
 * Senden — und die Gefahr ist dieselbe.
 */
class TestControllerFuerEntwurfSpeichern
{
    use SpeichertEntwuerfe;

    public function __construct(private MailAccount $konto) {}

    protected function accountFor(int $account): MailAccount
    {
        return $this->konto;
    }
}

function entwurfSpeicherKonto(): MailAccount
{
    return MailAccount::fromRemote([
        'id' => 1,
        'email' => 'henneberg@example.test',
        'label' => 'Henneberg',
        'auth_type' => 'password',
        'username' => 'henneberg',
        'password' => 'geheim',
        'smtp_host' => 'smtp.example.test',
        'smtp_port' => 587,
    ]);
}

/** @param array<string, mixed> $felder */
function entwurfSpeicherAnfrage(array $felder = [], array $dateien = []): Request
{
    return Request::create('/mailbox/1/draft', 'POST', $felder, [], $dateien);
}

afterEach(fn () => Mailboxes::fake(null));

/**
 * EIN Postfach fuer den ganzen Test, nicht eines je Aufruf.
 *
 * `Mailboxes::for()` wird hier zweimal gerufen — einmal zum Ablegen, einmal
 * zum Entfernen der Vorfassung. Gab die Fabrik bei jedem Aufruf eine NEUE
 * Attrappe heraus, sass eine Erwartung wie `->once()` auf der ersten, und die
 * zweite bekam den Aufruf: Der Test wurde rot, obwohl der Code richtig war.
 * Aufgefallen genau daran.
 */
function entwurfSpeicherPostfach(callable $einrichten): void
{
    $attrappe = Mockery::mock(Mailbox::class);
    $einrichten($attrappe);

    Mailboxes::fake(fn () => $attrappe);
}

it('legt den Entwurf im Entwurfsordner ab, mit den Marken \\Draft und \\Seen', function () {
    $gesehen = [];

    entwurfSpeicherPostfach(function ($attrappe) use (&$gesehen) {
        $attrappe->shouldReceive('specialFolder')->with('Drafts')->andReturn('Entw&APw-rfe');
        $attrappe->shouldReceive('append')->andReturnUsing(function (...$argumente) use (&$gesehen) {
            $gesehen = $argumente;

            return 99;
        });
    });

    $antwort = (new TestControllerFuerEntwurfSpeichern(entwurfSpeicherKonto()))->entwurfSpeichern(
        entwurfSpeicherAnfrage(['subject' => 'Angebot', 'body_html' => '<p>Guten Tag</p>']),
        1,
    );

    $inhalt = $antwort->getData(true);

    expect($inhalt['success'])->toBeTrue()
        ->and($inhalt['folder'])->toBe('Entw&APw-rfe')
        /*
         * Die Kennung gehoert in die Antwort: Ohne sie legt der ZWEITE Klick
         * eine zweite Fassung an statt zu ersetzen — das Produkt hat dann
         * nichts, was es als `replace_uid` zurueckschicken koennte.
         */
        ->and($inhalt['uid'])->toBe(99)
        ->and($gesehen[0])->toBe('Entw&APw-rfe');

    /*
     * `\Draft`, damit jeder Klient es als Entwurf zeigt. `\Seen`, weil der
     * eigene Entwurf nichts Ungelesenes ist — sonst meldet der Ordner dem
     * Verfasser seine eigene Arbeit als neue Post.
     */
    expect($gesehen[2])->toBe(['\\Draft', '\\Seen']);
    expect($gesehen[1])->toContain('Angebot');
});

it('sagt es, wenn das Postfach gar keinen Entwurfsordner hat', function () {
    /*
     * Eine Lage und kein Absturz — und vor allem KEIN Erfolg: Der Mensch soll
     * erfahren, dass sein Text nicht gesichert ist, statt es zu glauben.
     */
    entwurfSpeicherPostfach(function ($attrappe) {
        $attrappe->shouldReceive('specialFolder')->with('Drafts')->andReturn(null);
        $attrappe->shouldNotReceive('append');
    });

    $antwort = (new TestControllerFuerEntwurfSpeichern(entwurfSpeicherKonto()))->entwurfSpeichern(
        entwurfSpeicherAnfrage(['body_html' => '<p>Text</p>']),
        1,
    );

    expect($antwort->getStatusCode())->toBe(422)
        ->and($antwort->getData(true)['success'])->toBeFalse()
        ->and($antwort->getData(true)['message'])->toContain('keinen Entwurfsordner');
});

it('speichert auch einen Entwurf ohne Empfaenger, Betreff und Text', function () {
    /*
     * Ein Entwurf ist der Zustand „noch nicht fertig". Ihn an denselben
     * Pflichtfeldern wie das Senden zu messen hiesse, dass man erst speichern
     * darf, wenn man nicht mehr muss.
     */
    entwurfSpeicherPostfach(function ($attrappe) {
        $attrappe->shouldReceive('specialFolder')->with('Drafts')->andReturn('Drafts');
        $attrappe->shouldReceive('append')->once()->andReturn(1);
    });

    $antwort = (new TestControllerFuerEntwurfSpeichern(entwurfSpeicherKonto()))->entwurfSpeichern(entwurfSpeicherAnfrage(), 1);

    expect($antwort->getData(true)['success'])->toBeTrue();
});

describe('die Vorfassung ersetzen', function () {
    it('holt ihre Anhaenge ZUERST und legt sie der neuen Fassung bei', function () {
        /*
         * Lehre 1 aus #1155, hier in ihrer Speicher-Form: Beim zweiten
         * Speichern ist die alte Fassung die einzige Kopie ihrer Dateien. Wer
         * erst die neue anlegt und dann holt, holt aus einem Ordner, den er
         * gerade geleert hat.
         */
        $reihenfolge = [];

        entwurfSpeicherPostfach(function ($attrappe) use (&$reihenfolge) {
            $attrappe->shouldReceive('specialFolder')->with('Drafts')->andReturn('Drafts');
            $attrappe->shouldReceive('message')->andReturn(['subject' => 'Vorfassung']);
            $attrappe->shouldReceive('attachments')->with('Drafts', 7)->andReturnUsing(function () use (&$reihenfolge) {
                $reihenfolge[] = 'holen';

                return [['filename' => 'Rechnung.pdf', 'mime_type' => 'application/pdf', 'contents' => 'PDF']];
            });
            $attrappe->shouldReceive('append')->andReturnUsing(function (...$argumente) use (&$reihenfolge) {
                $reihenfolge[] = 'ablegen';
                $reihenfolge[] = str_contains($argumente[1], 'Rechnung.pdf') ? 'anhang dabei' : 'anhang FEHLT';

                return 8;
            });
            $attrappe->shouldReceive('purge')->with('Drafts', 7)->andReturnUsing(function () use (&$reihenfolge) {
                $reihenfolge[] = 'vorfassung weg';

                return true;
            });
        });

        (new TestControllerFuerEntwurfSpeichern(entwurfSpeicherKonto()))->entwurfSpeichern(
            entwurfSpeicherAnfrage(['body_html' => '<p>Text</p>', 'replace_folder' => 'Drafts', 'replace_uid' => 7]),
            1,
        );

        expect($reihenfolge)->toBe(['holen', 'ablegen', 'anhang dabei', 'vorfassung weg']);
    });

    it('bricht ab, wenn die Anhaenge der Vorfassung nicht lesbar sind', function () {
        /*
         * Lehre 2: Lieber nicht gespeichert als ohne die Dateien. Einen
         * Entwurf OHNE seine Anhaenge abzulegen und Erfolg zu melden waere der
         * schlimmere Ausgang — die Dateien sind dann weg, und der Mensch
         * erfaehrt es Tage spaeter.
         */
        entwurfSpeicherPostfach(function ($attrappe) {
            $attrappe->shouldReceive('specialFolder')->with('Drafts')->andReturn('Drafts');
            $attrappe->shouldReceive('message')->andReturn(['subject' => 'Vorfassung']);
            $attrappe->shouldReceive('attachments')->andThrow(new \RuntimeException('Postfach weg'));
            $attrappe->shouldNotReceive('append');
            $attrappe->shouldNotReceive('purge');
        });

        $antwort = (new TestControllerFuerEntwurfSpeichern(entwurfSpeicherKonto()))->entwurfSpeichern(
            entwurfSpeicherAnfrage(['body_html' => '<p>Text</p>', 'replace_folder' => 'Drafts', 'replace_uid' => 7]),
            1,
        );

        expect($antwort->getStatusCode())->toBe(502)
            ->and($antwort->getData(true)['success'])->toBeFalse();
    });

    it('laesst die Vorfassung stehen, wenn das Ablegen scheitert', function () {
        /*
         * Lehre 3 von der anderen Seite: Das Ergebnis eines gescheiterten
         * Speicherns darf nicht ein geloeschter Entwurf sein.
         */
        entwurfSpeicherPostfach(function ($attrappe) {
            $attrappe->shouldReceive('specialFolder')->with('Drafts')->andReturn('Drafts');
            $attrappe->shouldReceive('message')->andReturn(['subject' => 'Vorfassung']);
            $attrappe->shouldReceive('attachments')->andReturn([]);
            $attrappe->shouldReceive('append')->andThrow(new \RuntimeException('APPEND verweigert'));
            $attrappe->shouldNotReceive('purge');
        });

        $antwort = (new TestControllerFuerEntwurfSpeichern(entwurfSpeicherKonto()))->entwurfSpeichern(
            entwurfSpeicherAnfrage(['body_html' => '<p>Text</p>', 'replace_folder' => 'Drafts', 'replace_uid' => 7]),
            1,
        );

        expect($antwort->getStatusCode())->toBe(502);
    });

    it('meldet Erfolg, auch wenn das Entfernen der Vorfassung scheitert', function () {
        /*
         * Die neue Fassung liegt. Wer jetzt eine Fehlermeldung bekommt, haelt
         * den Entwurf fuer nicht gespeichert und speichert noch einmal — und
         * hat dann drei Fassungen. Zwei Fassungen im Ordner sind haesslich;
         * ein verlorener Text ist schlimmer.
         */
        entwurfSpeicherPostfach(function ($attrappe) {
            $attrappe->shouldReceive('specialFolder')->with('Drafts')->andReturn('Drafts');
            $attrappe->shouldReceive('message')->andReturn(['subject' => 'Vorfassung']);
            $attrappe->shouldReceive('attachments')->andReturn([]);
            $attrappe->shouldReceive('append')->andReturn(5);
            $attrappe->shouldReceive('purge')->andThrow(new \RuntimeException('UID weg'));
        });

        $antwort = (new TestControllerFuerEntwurfSpeichern(entwurfSpeicherKonto()))->entwurfSpeichern(
            entwurfSpeicherAnfrage(['body_html' => '<p>Text</p>', 'replace_folder' => 'Drafts', 'replace_uid' => 7]),
            1,
        );

        expect($antwort->getData(true)['success'])->toBeTrue()
            ->and($antwort->getData(true)['uid'])->toBe(5);
    });

    it('entfernt sie endgueltig und legt sie NICHT in den Papierkorb', function () {
        /*
         * `purge` und nicht `delete`: Ein Papierkorb, der sich mit jeder
         * Zwischenfassung eines Entwurfs fuellt, ist nicht, was jemand mit
         * „loeschen" meint.
         */
        $gerufen = [];

        entwurfSpeicherPostfach(function ($attrappe) use (&$gerufen) {
            $attrappe->shouldReceive('specialFolder')->with('Drafts')->andReturn('Drafts');
            $attrappe->shouldReceive('message')->andReturn(['subject' => 'Vorfassung']);
            $attrappe->shouldReceive('attachments')->andReturn([]);
            $attrappe->shouldReceive('append')->andReturn(5);
            $attrappe->shouldReceive('purge')->andReturnUsing(function () use (&$gerufen) {
                $gerufen[] = 'purge';

                return true;
            });
            $attrappe->shouldReceive('delete')->andReturnUsing(function () use (&$gerufen) {
                $gerufen[] = 'delete';

                return true;
            });
        });

        (new TestControllerFuerEntwurfSpeichern(entwurfSpeicherKonto()))->entwurfSpeichern(
            entwurfSpeicherAnfrage(['body_html' => '<p>Text</p>', 'replace_folder' => 'Drafts', 'replace_uid' => 7]),
            1,
        );

        expect($gerufen)->toBe(['purge']);
    });
});

describe('die Blindkopie', function () {
    it('kommt NICHT in den abgelegten Entwurf', function () {
        /*
         * Siehe Klassentext: `Contracts\Mailbox` gibt zu einer Nachricht kein
         * `bcc` heraus. Es mitzuschreiben und beim Wiederaufnehmen nicht lesen
         * zu koennen waere schlimmer als es gar nicht zu schreiben — dann
         * stuende es im Postfach und waere fuer den Verfasser doch verloren.
         * Dazu sind die Entwurfsordner hier teils GETEILTE Postfaecher.
         */
        $abgelegt = '';

        entwurfSpeicherPostfach(function ($attrappe) use (&$abgelegt) {
            $attrappe->shouldReceive('specialFolder')->with('Drafts')->andReturn('Drafts');
            $attrappe->shouldReceive('append')->andReturnUsing(function (...$argumente) use (&$abgelegt) {
                $abgelegt = $argumente[1];

                return 1;
            });
        });

        $antwort = (new TestControllerFuerEntwurfSpeichern(entwurfSpeicherKonto()))->entwurfSpeichern(
            entwurfSpeicherAnfrage([
                'body_html' => '<p>Text</p>',
                'to' => [['email' => 'kunde@example.test']],
                'bcc' => [['email' => 'still@example.test']],
            ]),
            1,
        );

        expect($abgelegt)->not->toContain('still@example.test')
            // Und gesagt wird es auch, statt es zu verschweigen.
            ->and($antwort->getData(true)['bcc_verworfen'])->toBeTrue();
    });

    it('meldet nichts verworfen, wenn gar kein BCC dabei war', function () {
        entwurfSpeicherPostfach(function ($attrappe) {
            $attrappe->shouldReceive('specialFolder')->with('Drafts')->andReturn('Drafts');
            $attrappe->shouldReceive('append')->andReturn(1);
        });

        $antwort = (new TestControllerFuerEntwurfSpeichern(entwurfSpeicherKonto()))->entwurfSpeichern(
            entwurfSpeicherAnfrage(['body_html' => '<p>Text</p>']),
            1,
        );

        expect($antwort->getData(true)['bcc_verworfen'])->toBeFalse();
    });
});

it('nimmt die Koepfe einer Antwort mit in den Entwurf', function () {
    /*
     * Ein Entwurf, der `In-Reply-To` verliert, beginnt beim Empfaenger einen
     * neuen Strang — und das faellt erst auf, wenn die Antwort drei Tage
     * spaeter allein dasteht.
     */
    $abgelegt = '';

    entwurfSpeicherPostfach(function ($attrappe) use (&$abgelegt) {
        $attrappe->shouldReceive('specialFolder')->with('Drafts')->andReturn('Drafts');
        $attrappe->shouldReceive('append')->andReturnUsing(function (...$argumente) use (&$abgelegt) {
            $abgelegt = $argumente[1];

            return 1;
        });
    });

    (new TestControllerFuerEntwurfSpeichern(entwurfSpeicherKonto()))->entwurfSpeichern(
        entwurfSpeicherAnfrage([
            'body_html' => '<p>Text</p>',
            'in_reply_to' => '<urspruenglich@example.test>',
            'references' => '<eins@example.test> <urspruenglich@example.test>',
        ]),
        1,
    );

    expect($abgelegt)->toContain('urspruenglich@example.test');
});

it('legt die Dateien aus dem Formular bei', function () {
    $abgelegt = '';

    entwurfSpeicherPostfach(function ($attrappe) use (&$abgelegt) {
        $attrappe->shouldReceive('specialFolder')->with('Drafts')->andReturn('Drafts');
        $attrappe->shouldReceive('append')->andReturnUsing(function (...$argumente) use (&$abgelegt) {
            $abgelegt = $argumente[1];

            return 1;
        });
    });

    (new TestControllerFuerEntwurfSpeichern(entwurfSpeicherKonto()))->entwurfSpeichern(
        entwurfSpeicherAnfrage(['body_html' => '<p>Text</p>'], [
            'attachments' => [UploadedFile::fake()->createWithContent('Angebot.txt', 'Inhalt')],
        ]),
        1,
    );

    expect($abgelegt)->toContain('Angebot.txt');
});
