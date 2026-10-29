<?php

use Illuminate\Http\Request;
use Peppermint\Mailbox\Http\HandlesSending;
use Peppermint\Mailbox\Models\MailAccount;
use Peppermint\Mailbox\Sending\Mailer;
use Peppermint\Mailbox\Sending\Outgoing;
use Peppermint\Mailbox\Sending\RecordingTransport;
use Symfony\Component\Mailer\Envelope;
use Symfony\Component\Mailer\SentMessage;
use Symfony\Component\Mailer\Transport\Smtp\EsmtpTransport;
use Symfony\Component\Mailer\Transport\TransportInterface;
use Symfony\Component\Mime\RawMessage;

/**
 * Der Postausgang laesst sich in Tests ersetzen (29.10.2026).
 *
 * ## Was ohne diese Naht ungeprueft blieb
 *
 * Alles, was NACH dem Hinausgeben passiert. Die eigenen Versand-Tests dieses
 * Pakets kommen an `versenden()` nur vorbei: Ein Test-Controller ueberschreibt
 * die Methode. Damit ist geprueft, was vorher passiert — Freigabe, Pruefung der
 * Eingaben, Zusammenbau — und nichts von der Reihenfolge danach.
 *
 * Die Reihenfolge ist aber die Stelle mit der Regel: Ab dem Hinausgeben darf
 * nichts mehr als Fehlschlag beim Absender ankommen. Wer das verletzt, erzeugt
 * genau einen Fehler: Der Absender liest „nicht versendet" und schickt die Mail
 * ein zweites Mal. Das faellt beim Empfaenger auf, nicht bei uns.
 *
 * Dateieigene Helfernamen mit Absicht — Pest laedt alle Testdateien in denselben
 * Prozess, und `versandKonto` gibt es schon im HandlesSendingTest.
 */
class TestControllerDurchDieNaht
{
    use HandlesSending;

    /** @var list<string> */
    public array $buchfuehrung = [];

    public function __construct(
        private readonly MailAccount $konto,
        private readonly bool $eigeneAblage = true,
    ) {}

    protected function accountFor(int $account): MailAccount
    {
        return $this->konto;
    }

    protected function speichertGesendetSelbst(MailAccount $konto): bool
    {
        return $this->eigeneAblage;
    }

    protected function afterSending(MailAccount $konto, string $messageId, array $daten): void
    {
        $this->buchfuehrung[] = $messageId;
    }
}

function nahtKonto(): MailAccount
{
    return MailAccount::fromRemote([
        'id' => 1,
        'email' => 'buero@example.test',
        'label' => 'Büro',
        'auth_type' => 'password',
        'username' => 'buero',
        'password' => 'geheim',
        'smtp_host' => 'smtp.example.test',
        'smtp_port' => 587,
    ]);
}

function nahtAnfrage(array $daten): Request
{
    $r = Request::create('/', 'POST', $daten);
    $r->headers->set('Accept', 'application/json');

    return $r;
}

afterEach(function () {
    // Ohne das laeuft der naechste Test gegen ein Gespenst — und ist gruen,
    // ohne gesendet zu haben.
    Mailer::fake(null);
});

it('ersetzt den Postausgang und behaelt die Nachricht', function () {
    $postausgang = new RecordingTransport;
    Mailer::fake(fn () => $postausgang);

    (new Mailer)->send(nahtKonto(), new Outgoing(
        subject: 'Angebot',
        html: '<p>Guten Tag</p>',
        to: [['email' => 'kunde@example.test', 'name' => 'Kundin']],
        cc: [['email' => 'kopie@example.test']],
        bcc: [['email' => 'blind@example.test']],
    ));

    expect($postausgang->anzahl())->toBe(1);

    $nachricht = $postausgang->letzteNachricht();

    expect($nachricht)->not->toBeNull()
        ->and($nachricht->getHeaders()->get('Subject')?->getBodyAsString())->toBe('Angebot')
        ->and($nachricht->getHeaders()->get('To')?->getBodyAsString())->toContain('kunde@example.test')
        ->and($nachricht->getHeaders()->get('Cc')?->getBodyAsString())->toContain('kopie@example.test')
        // Die Blindkopie steht in der NACHRICHT — dass sie beim Versand nicht
        // in den sichtbaren Kopfzeilen landet, ist Sache des Transports.
        ->and($nachricht->getHeaders()->get('Bcc')?->getBodyAsString())->toContain('blind@example.test');
});

it('gibt die Message-ID zurueck, die wirklich in der Nachricht steht', function () {
    /*
     * Die Kennung ist das Band zwischen der gesendeten Mail und allem, was ein
     * Produkt daran haengt — Archiv, Unterhaltung, Zuordnung zu einem Vorgang.
     * Eine zurueckgegebene Kennung, die nicht in der Nachricht steht, macht
     * jede Antwort darauf unauffindbar.
     */
    $postausgang = new RecordingTransport;
    Mailer::fake(fn () => $postausgang);

    $kennung = (new Mailer)->send(nahtKonto(), new Outgoing(
        subject: 'x',
        html: '<p>x</p>',
        to: [['email' => 'kunde@example.test']],
    ));

    $inDerNachricht = (string) $postausgang->letzteNachricht()
        ?->getHeaders()->get('Message-ID')?->getBodyAsString();

    expect($kennung)->not->toBe('')
        ->and($inDerNachricht)->toBe($kennung)
        // Aus der Absenderdomain gebildet, nicht aus der des Empfaengers.
        ->and($kennung)->toContain('@example.test');
});

it('reicht das Konto an die Closure durch', function () {
    // Damit ein Test zwei Konten unterschiedlich behandeln kann — eines, dessen
    // Versand scheitert, und eines, das durchgeht.
    $gesehen = [];
    Mailer::fake(function (MailAccount $konto) use (&$gesehen): TransportInterface {
        $gesehen[] = (string) $konto->field('email');

        return new RecordingTransport;
    });

    (new Mailer)->send(nahtKonto(), new Outgoing(
        subject: 'x',
        html: '<p>x</p>',
        to: [['email' => 'kunde@example.test']],
    ));

    expect($gesehen)->toBe(['buero@example.test']);
});

it('baut ohne Naht wieder den echten Postausgang', function () {
    /*
     * Die Gegenprobe, und die wichtigste: Ein Paket, das nach einem Test
     * dauerhaft in den Attrappen-Modus faellt, wuerde in Produktion nichts mehr
     * verschicken — und zwar lautlos, denn ein mitschreibender Transport meldet
     * Erfolg.
     *
     * Hier wird nur gebaut, nicht verbunden: Ein `EsmtpTransport` nimmt beim
     * Erzeugen kein Netz in Anspruch.
     */
    Mailer::fake(fn () => new RecordingTransport);
    Mailer::fake(null);

    expect((new Mailer)->transportFor(nahtKonto()))->toBeInstanceOf(EsmtpTransport::class);
});

it('fuehrt die Buchfuehrung des Produkts NACH dem Versand aus', function () {
    /*
     * Jetzt durch `versenden()` hindurch statt daran vorbei. Geprueft wird die
     * Reihenfolge: Erst ist die Nachricht draussen, dann wird gebucht — und die
     * gebuchte Kennung ist die der Nachricht, die wirklich hinausging.
     */
    $postausgang = new RecordingTransport;
    Mailer::fake(fn () => $postausgang);

    $controller = new TestControllerDurchDieNaht(nahtKonto());

    $antwort = $controller->send(nahtAnfrage([
        'to' => [['email' => 'kunde@example.test']],
        'subject' => 'Angebot',
        'body_html' => '<p>Guten Tag</p>',
    ]), 1);

    $ergebnis = $antwort->getData(true);

    expect($ergebnis['success'])->toBeTrue()
        ->and($postausgang->anzahl())->toBe(1)
        ->and($controller->buchfuehrung)->toHaveCount(1)
        ->and($controller->buchfuehrung[0])->toBe($ergebnis['message_id']);
});

it('bucht NICHT, wenn der Versand scheitert', function () {
    /*
     * Die Gegenrichtung, und ohne diese Naht war sie nicht pruefbar. Ein Archiv
     * mit Nachrichten, die nie hinausgegangen sind, ist schlimmer als keins:
     * Man sieht die Mail im Ausgang stehen und schickt sie nicht nach.
     */
    Mailer::fake(fn () => new class implements TransportInterface
    {
        public function send(RawMessage $message, ?Envelope $envelope = null): ?SentMessage
        {
            throw new RuntimeException('SMTP sagt nein');
        }

        public function __toString(): string
        {
            return 'kaputt://';
        }
    });

    $controller = new TestControllerDurchDieNaht(nahtKonto());

    $antwort = $controller->send(nahtAnfrage([
        'to' => [['email' => 'kunde@example.test']],
        'subject' => 'Angebot',
        'body_html' => '<p>Guten Tag</p>',
    ]), 1);

    expect($antwort->getStatusCode())->toBe(502)
        ->and($antwort->getData(true)['success'])->toBeFalse()
        ->and($controller->buchfuehrung)->toBe([]);
});

it('meldet die Kopie in „Gesendet" als nicht versucht, wenn der Anbieter sie selbst legt', function () {
    /*
     * `copied_to_sent: null` heisst „gar nicht versucht" und ist nicht dasselbe
     * wie `false` („versucht, ging nicht"). Die Unterscheidung ist der Kern von
     * Bug #1117 im Projekt-Manager: Wer sie verliert, haengt neben die Kopie von
     * Microsoft eine zweite.
     */
    Mailer::fake(fn () => new RecordingTransport);

    $antwort = (new TestControllerDurchDieNaht(nahtKonto(), eigeneAblage: true))->send(nahtAnfrage([
        'to' => [['email' => 'kunde@example.test']],
        'subject' => 'x',
        'body_html' => '<p>x</p>',
    ]), 1);

    expect($antwort->getData(true)['copied_to_sent'])->toBeNull();
});
