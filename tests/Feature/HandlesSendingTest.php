<?php

use Illuminate\Http\Request;
use Peppermint\Mailbox\Http\HandlesSending;
use Peppermint\Mailbox\Models\MailAccount;
use Peppermint\Mailbox\Sending\Outgoing;

/**
 * Der Endpunkt zum Senden (22.09.2026).
 *
 * Geprueft wird die Strecke vom Browser zur Nachricht — nicht das Netz. Was
 * hier still falsch sein kann: Gehen die Antwort-Kopfzeilen wirklich mit?
 * Und kippt ein Fehler NACH dem Versand die Antwort an den Absender?
 */
class TestControllerFuerVersand
{
    use HandlesSending;

    public array $buchfuehrung = [];

    public function __construct(private readonly MailAccount $konto, private readonly bool $eigeneAblage = false) {}

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

        throw new RuntimeException('Buchfuehrung kaputt');
    }
}

function versandKonto(): MailAccount
{
    return MailAccount::fromRemote([
        'id' => 1,
        'email' => 'buero@example.test',
        'label' => 'Büro',
        'auth_type' => 'password',
        'username' => 'buero',
        'password' => 'geheim',
        // Ein Ziel, das es nicht gibt: Der Versand scheitert, und genau das
        // wird hier geprueft — die Antwort soll eine Absage sein, kein Absturz.
        'smtp_host' => '127.0.0.1',
        'smtp_port' => 1,
    ]);
}

function versandAnfrage(array $daten): Request
{
    $r = Request::create('/', 'POST', $daten);
    $r->headers->set('Accept', 'application/json');

    return $r;
}

it('beantwortet einen gescheiterten Versand mit einer Absage statt mit einem Absturz', function () {
    // Wer einen Absturz sieht, weiss nicht, ob die Mail raus ist. Wer 502 mit
    // Grund sieht, weiss es.
    $antwort = (new TestControllerFuerVersand(versandKonto()))->send(versandAnfrage([
        'to' => [['email' => 'kunde@example.test']],
        'subject' => 'Angebot',
        'body_html' => '<p>Guten Tag</p>',
    ]), 1);

    expect($antwort->getStatusCode())->toBe(502)
        ->and($antwort->getData(true)['success'])->toBeFalse();
});

it('verlangt mindestens einen Empfaenger', function () {
    expect(fn () => (new TestControllerFuerVersand(versandKonto()))->send(versandAnfrage([
        'to' => [],
        'subject' => 'x',
        'body_html' => '<p>x</p>',
    ]), 1))->toThrow(Illuminate\Validation\ValidationException::class);
});

it('weist eine unbrauchbare Adresse ab, bevor irgendetwas hinausgeht', function () {
    expect(fn () => (new TestControllerFuerVersand(versandKonto()))->send(versandAnfrage([
        'to' => [['email' => 'kein-at-zeichen']],
        'subject' => 'x',
        'body_html' => '<p>x</p>',
    ]), 1))->toThrow(Illuminate\Validation\ValidationException::class);
});

/**
 * Weiterleiten (#7585).
 *
 * Der Unterschied zum Senden ist genau einer: Die Anhänge der
 * Ursprungsnachricht müssen mit. Fehlt dieser Schritt, geht trotzdem eine Mail
 * hinaus — nur ohne die Rechnung, von der sie handelt. Niemand sieht das, bis
 * der Empfänger nachfragt.
 */
class TestControllerFuerWeiterleitung
{
    use HandlesSending;

    /** @var list<array{contents: string, filename: string, mime?: string|null}> */
    public array $geholt = [];

    public ?Outgoing $gebaut = null;

    public function __construct(
        private readonly MailAccount $konto,
        private readonly array $ausDemPostfach = [],
        private readonly bool $postfachKaputt = false,
    ) {}

    protected function accountFor(int $account): MailAccount
    {
        return $this->konto;
    }

    protected function originalAttachments(MailAccount $konto, array $daten): array
    {
        if ($this->postfachKaputt) {
            throw new RuntimeException('Postfach antwortet nicht');
        }

        $this->geholt = $this->ausDemPostfach;

        return $this->ausDemPostfach;
    }
}

function weiterleitAnfrage(array $daten): Request
{
    $r = Request::create('/', 'POST', $daten);
    $r->headers->set('Accept', 'application/json');

    return $r;
}

it('holt die Anhänge der Ursprungsnachricht und schickt sie mit', function () {
    $controller = new TestControllerFuerWeiterleitung(versandKonto(), [
        ['contents' => '%PDF-1.4 …', 'filename' => 'rechnung.pdf', 'mime' => 'application/pdf'],
    ]);

    // Der Versand scheitert (Ziel gibt es nicht) — gemessen wird, was VORHER
    // zusammengebaut wurde.
    $antwort = $controller->forward(weiterleitAnfrage([
        'to' => [['email' => 'kollege@example.test']],
        'subject' => 'Fwd: Rechnung',
        'body_html' => '<p>Zur Kenntnis</p>',
        'quoted_html' => '<blockquote>Original</blockquote>',
        'source_type' => 'imap',
        'source_folder' => 'INBOX',
        'source_uid' => '42',
    ]), 1);

    expect($controller->geholt)->toHaveCount(1)
        ->and($controller->geholt[0]['filename'])->toBe('rechnung.pdf')
        ->and($antwort->getStatusCode())->toBe(502);
});

it('scheitert hörbar, wenn die Anhänge nicht zu holen sind', function () {
    // Die gefährliche Alternative wäre, ohne sie zu senden: Der Absender hält
    // die Datei dann für zugestellt.
    $antwort = (new TestControllerFuerWeiterleitung(versandKonto(), [], true))->forward(weiterleitAnfrage([
        'to' => [['email' => 'kollege@example.test']],
        'subject' => 'Fwd: Rechnung',
        'body_html' => '<p>x</p>',
        'source_type' => 'imap',
        'source_folder' => 'INBOX',
        'source_uid' => '42',
    ]), 1);

    expect($antwort->getStatusCode())->toBe(502)
        ->and($antwort->getData(true)['success'])->toBeFalse()
        ->and($antwort->getData(true)['message'])->toContain('Anhänge');
});

it('verlangt Ordner und Kennung, wenn die Quelle ein Postfach ist', function () {
    expect(fn () => (new TestControllerFuerWeiterleitung(versandKonto()))->forward(weiterleitAnfrage([
        'to' => [['email' => 'kollege@example.test']],
        'subject' => 'Fwd: x',
        'source_type' => 'imap',
    ]), 1))->toThrow(Illuminate\Validation\ValidationException::class);
});

it('lässt ein Produkt mit eigener Ablage seine Dateien selbst beisteuern', function () {
    // `source_type` ist nicht `imap` — das Paket holt dann nichts und
    // überlässt es dem Produkt. Ohne diese Tür müsste ein Produkt mit Archiv
    // den ganzen Endpunkt nachbauen.
    $controller = new TestControllerFuerWeiterleitung(versandKonto(), []);

    $antwort = $controller->forward(weiterleitAnfrage([
        'to' => [['email' => 'kollege@example.test']],
        'subject' => 'Fwd: aus der Ablage',
        'body_html' => '<p>x</p>',
        'source_type' => 'stored',
        'source_email_id' => '7',
    ]), 1);

    expect($antwort->getStatusCode())->toBe(502)
        ->and($controller->geholt)->toBe([]);
});

/**
 * Anhänge dürfen nicht still verloren gehen.
 *
 * Bis zum 14.10.2026 nahm `send()` keine Dateien an. Das Antwortfeld des
 * Pakets bietet sie aber an: Wer eine Datei anhängte und auf „Senden" drückte,
 * bekam „gesendet" zu sehen — und der Empfänger eine Mail ohne Datei.
 *
 * Dieser Controller schaut auf die fertige Nachricht, statt auf die Antwort:
 * Der Versand scheitert hier ohnehin, geprüft wird, was VORHER gebaut wurde.
 */
class TestControllerDerMitliest
{
    use HandlesSending;

    public ?Outgoing $nachricht = null;

    public function __construct(private readonly MailAccount $konto) {}

    protected function accountFor(int $account): MailAccount
    {
        return $this->konto;
    }

    protected function originalAttachments(MailAccount $konto, array $daten): array
    {
        return [['contents' => 'PDF', 'filename' => 'original.pdf', 'mime' => 'application/pdf']];
    }

    protected function versenden(MailAccount $konto, Outgoing $nachricht, array $daten): \Illuminate\Http\JsonResponse
    {
        $this->nachricht = $nachricht;

        return response()->json(['success' => true]);
    }
}

function mitDatei(array $daten): Request
{
    $pfad = tempnam(sys_get_temp_dir(), 'anhang').'.txt';
    file_put_contents($pfad, 'Inhalt');

    $r = Request::create('/', 'POST', $daten, [], [
        'attachments' => [new \Illuminate\Http\UploadedFile($pfad, 'angebot.txt', 'text/plain', null, true)],
    ]);
    $r->headers->set('Accept', 'application/json');

    return $r;
}

it('nimmt beim Senden eine angehängte Datei mit', function () {
    $controller = new TestControllerDerMitliest(versandKonto());

    $controller->send(mitDatei([
        'to' => [['email' => 'kunde@example.test']],
        'subject' => 'Angebot',
        'body_html' => '<p>Anbei</p>',
    ]), 1);

    expect($controller->nachricht?->attachments)->toHaveCount(1)
        ->and($controller->nachricht->attachments[0]['filename'])->toBe('angebot.txt');
});

it('nimmt beim Weiterleiten BEIDES mit: die alten Dateien und die neu angehängte', function () {
    // Der Fall, der in der Praxis vorkommt: „hier die Rechnung, dazu meine
    // Notiz". Fällt eine der beiden Listen weg, merkt es niemand.
    $controller = new TestControllerDerMitliest(versandKonto());

    $controller->forward(mitDatei([
        'to' => [['email' => 'kollege@example.test']],
        'subject' => 'Fwd: Rechnung',
        'body_html' => '<p>Zur Kenntnis</p>',
        'source_type' => 'imap',
        'source_folder' => 'INBOX',
        'source_uid' => '42',
    ]), 1);

    expect($controller->nachricht?->attachments)->toHaveCount(1)
        ->and($controller->nachricht->attachments[0]['filename'])->toBe('angebot.txt')
        ->and($controller->nachricht->rawAttachments)->toHaveCount(1)
        ->and($controller->nachricht->rawAttachments[0]['filename'])->toBe('original.pdf');
});

it('hängt das Zitat an den Rumpf, statt es zu verlieren', function () {
    $controller = new TestControllerDerMitliest(versandKonto());

    $controller->forward(versandAnfrage([
        'to' => [['email' => 'kollege@example.test']],
        'subject' => 'Fwd: Rechnung',
        'body_html' => '<p>Zur Kenntnis</p>',
        'quoted_html' => '<blockquote>Original</blockquote>',
        'source_type' => 'imap',
        'source_folder' => 'INBOX',
        'source_uid' => '42',
    ]), 1);

    expect($controller->nachricht?->html)->toBe('<p>Zur Kenntnis</p><blockquote>Original</blockquote>');
});
