<?php

use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Peppermint\Mailbox\Http\HandlesSending;
use Peppermint\Mailbox\Http\SpeichertEntwuerfe;
use Peppermint\Mailbox\Models\MailAccount;

/**
 * Die Feldnamen im Fehlersatz (1.11.2026).
 *
 * Gemeldet von `mail-2b`, nachdem die Feldfehler aus v0.197.0 in drei
 * Produkten live waren: Am Betreff stand „subject darf höchstens 500 Zeichen
 * lang sein." Die Zuordnung traf das richtige Feld — nur der Satz darin sprach
 * von der Datenbankspalte.
 */
class TestControllerFuerFeldnamen
{
    use HandlesSending;
    use SpeichertEntwuerfe;

    protected function accountFor(int $account): MailAccount
    {
        throw new RuntimeException('Hier kommt kein Test hin — die Pruefung schlaegt vorher fehl.');
    }
}

function feldnamenAnfrage(array $felder): Request
{
    $anfrage = Request::create('/mailbox/1/send', 'POST', $felder);
    $anfrage->headers->set('Accept', 'application/json');

    return $anfrage;
}

/** Den Satz zu einem Feld aus der Pruefung holen. */
function satzZu(callable $aufruf, string $feld): string
{
    try {
        $aufruf();
    } catch (ValidationException $e) {
        return (string) ($e->errors()[$feld][0] ?? '');
    }

    return '';
}

it('nennt den Betreff „Betreff" und nicht `subject`', function () {
    $satz = satzZu(
        fn () => (new TestControllerFuerFeldnamen)->send(
            feldnamenAnfrage([
                'to' => [['email' => 'kunde@example.test']],
                'subject' => str_repeat('a', 501),
                'body_html' => '<p>x</p>',
            ]),
            1,
        ),
        'subject',
    );

    expect($satz)->toContain('Betreff')
        ->and($satz)->not->toContain('subject');
});

it('nennt die Empfaengeradresse beim Namen — der Schluessel mit dem Sternchen', function () {
    /*
     * Der Punkt, an dem eine halbe Liste nichts bringt: Der Fehler entsteht
     * unter `to.0.email`. Steht in der Liste nur `to`, bleibt „to.0.email ist
     * keine gültige E-Mail-Adresse" stehen — und zwar unveraendert, obwohl man
     * etwas eingetragen hat.
     */
    $satz = satzZu(
        fn () => (new TestControllerFuerFeldnamen)->send(
            feldnamenAnfrage([
                'to' => [['email' => 'kein-at-zeichen']],
                'subject' => 'Angebot',
                'body_html' => '<p>x</p>',
            ]),
            1,
        ),
        'to.0.email',
    );

    expect($satz)->toContain('E-Mail-Adresse des Empfängers')
        ->and($satz)->not->toContain('to.0.email');
});

it('gilt auch beim Speichern eines Entwurfs', function () {
    // Zwei Zuege, eine Liste. Sonst heisst dasselbe Feld je Knopf anders.
    $satz = satzZu(
        fn () => (new TestControllerFuerFeldnamen)->entwurfSpeichern(
            feldnamenAnfrage(['subject' => str_repeat('a', 501)]),
            1,
        ),
        'subject',
    );

    expect($satz)->toContain('Betreff');
});

it('laesst sich vom Produkt ueberschreiben', function () {
    /*
     * Eine Methode und keine Konstante, genau dafuer: Ein Produkt, das nicht
     * deutsch laeuft, soll die Woerter austauschen koennen, ohne den Zug
     * nachzubauen.
     */
    $controller = new class
    {
        use HandlesSending;

        protected function accountFor(int $account): MailAccount
        {
            throw new RuntimeException('kommt hier nicht hin');
        }

        protected function feldnamen(): array
        {
            return ['subject' => 'Subject line'];
        }
    };

    $satz = satzZu(
        fn () => $controller->send(feldnamenAnfrage(['to' => [['email' => 'a@b.de']], 'subject' => str_repeat('a', 501), 'body_html' => 'x']), 1),
        'subject',
    );

    expect($satz)->toContain('Subject line');
});
