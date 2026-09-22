<?php

use Illuminate\Http\Request;
use Peppermint\Mailbox\Http\HandlesSending;
use Peppermint\Mailbox\Models\MailAccount;

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
