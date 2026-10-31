<?php

use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Validation\ValidationException;
use Peppermint\Mailbox\Contracts\Diktat;
use Peppermint\Mailbox\Contracts\Schreibhilfe;
use Peppermint\Mailbox\Http\HilftBeimSchreiben;

/**
 * Schreibassistent und Spracheingabe am Editor (31.10.2026).
 *
 * Bis dahin hatte beides nur der Projekt-Manager, in seiner eigenen
 * Editor-Huelle. Bastians Regel: in jedem System die Funktionen aller Systeme.
 *
 * Geprueft wird die NAHT, nicht der Dienst. Das Paket bringt keinen mit — es
 * soll ohne Modell laufen, ohne Schluessel, ohne fremden Dienst.
 */
class TestControllerOhneDienste
{
    use HilftBeimSchreiben;
}

class TestControllerMitDiensten
{
    use HilftBeimSchreiben;

    public function __construct(
        private readonly ?Schreibhilfe $hilfe = null,
        private readonly ?Diktat $stimme = null,
    ) {}

    protected function schreibhilfe(): ?Schreibhilfe
    {
        return $this->hilfe;
    }

    protected function diktat(): ?Diktat
    {
        return $this->stimme;
    }
}

function schreibAnfrage(array $daten): Request
{
    $r = Request::create('/', 'POST', $daten);
    $r->headers->set('Accept', 'application/json');

    return $r;
}

it('sagt der Oberflaeche, dass es hier nichts gibt — BEVOR sie zeichnet', function () {
    /*
     * Die Alternative waere, den Knopf immer zu zeigen und beim ersten Klick
     * 503 zu antworten. Genau das ist die Regel, die das Paket seit dem
     * 22.09.2026 fuer Mail-Knoepfe ohne Sendeweg hat: Ein Knopf ohne Wirkung
     * sieht aus wie ein Defekt, nicht wie eine fehlende Funktion.
     */
    expect((new TestControllerOhneDienste)->editorDienste()->getData(true))
        ->toBe(['schreibhilfe' => false, 'diktat' => false]);
});

it('meldet beide, sobald ein Produkt sie bindet', function () {
    $controller = new TestControllerMitDiensten(
        new class implements Schreibhilfe
        {
            public function verarbeiten(string $text, string $modus, ?string $stil = null): string
            {
                return $text;
            }
        },
        new class implements Diktat
        {
            public function abtippen(string $pfad, string $mimeTyp): string
            {
                return '';
            }
        },
    );

    expect($controller->editorDienste()->getData(true))->toBe(['schreibhilfe' => true, 'diktat' => true]);
});

it('reicht Text, Modus und Stil durch und gibt das Ergebnis heraus', function () {
    $gesehen = [];

    $controller = new TestControllerMitDiensten(new class($gesehen) implements Schreibhilfe
    {
        public function __construct(private array &$gesehen) {}

        public function verarbeiten(string $text, string $modus, ?string $stil = null): string
        {
            $this->gesehen = [$text, $modus, $stil];

            return 'Sehr geehrte Damen und Herren,';
        }
    });

    $antwort = $controller->textVerbessern(schreibAnfrage([
        'text' => 'hallo zusammen',
        'mode' => 'rephrase',
        'style' => 'formal',
    ]));

    expect($antwort->getData(true))->toBe(['result' => 'Sehr geehrte Damen und Herren,'])
        ->and($gesehen)->toBe(['hallo zusammen', 'rephrase', 'formal']);
});

it('antwortet mit 503, wenn kein Dienst gebunden ist — und sendet nichts ins Leere', function () {
    $antwort = (new TestControllerOhneDienste)->textVerbessern(schreibAnfrage([
        'text' => 'hallo',
        'mode' => 'correct',
    ]));

    expect($antwort->getStatusCode())->toBe(503)
        ->and($antwort->getData(true)['message'])->toContain('nicht eingerichtet');
});

it('gibt den Satz des Dienstes weiter, statt „Fehler" zu sagen', function () {
    /*
     * Ein Modell, das „zu lang" oder „gerade ueberlastet" sagt, sagt etwas
     * Brauchbares. „Fehler" sagt nichts — und der Mensch weiss nicht, ob er
     * es gleich noch einmal versuchen soll.
     */
    $controller = new TestControllerMitDiensten(new class implements Schreibhilfe
    {
        public function verarbeiten(string $text, string $modus, ?string $stil = null): string
        {
            throw new RuntimeException('Der Text ist zu lang.');
        }
    });

    $antwort = $controller->textVerbessern(schreibAnfrage(['text' => 'x', 'mode' => 'correct']));

    expect($antwort->getStatusCode())->toBe(502)
        ->and($antwort->getData(true)['message'])->toBe('Der Text ist zu lang.');
});

it('weist einen unbekannten Modus ab, statt ihn weiterzureichen', function () {
    expect(fn () => (new TestControllerOhneDienste)->textVerbessern(schreibAnfrage([
        'text' => 'x',
        'mode' => 'erfinde-etwas',
    ])))->toThrow(ValidationException::class);
});

it('tippt eine Aufnahme ab und legt NICHTS davon ab', function () {
    /*
     * Was gesprochen wurde, gehoert dem Menschen, der es gesprochen hat. Die
     * hochgeladene Datei raeumt Laravel am Ende der Anfrage weg — hier wird
     * geprueft, dass der Zug sie auch nirgends hinkopiert.
     */
    $gesehen = null;

    $controller = new TestControllerMitDiensten(null, new class($gesehen) implements Diktat
    {
        public function __construct(private mixed &$gesehen) {}

        public function abtippen(string $pfad, string $mimeTyp): string
        {
            $this->gesehen = $pfad;

            return 'Guten Tag, anbei die Rechnung.';
        }
    });

    $datei = UploadedFile::fake()->create('aufnahme.webm', 12, 'audio/webm');
    $anfrage = Request::create('/', 'POST', [], [], ['audio' => $datei]);
    $anfrage->headers->set('Accept', 'application/json');

    $antwort = $controller->diktatAbtippen($anfrage);

    expect($antwort->getData(true))->toBe(['text' => 'Guten Tag, anbei die Rechnung.'])
        ->and($gesehen)->toBeString();
});

it('antwortet auch bei der Spracheingabe mit 503 statt mit einem Absturz', function () {
    $datei = UploadedFile::fake()->create('aufnahme.webm', 12, 'audio/webm');
    $anfrage = Request::create('/', 'POST', [], [], ['audio' => $datei]);
    $anfrage->headers->set('Accept', 'application/json');

    expect((new TestControllerOhneDienste)->diktatAbtippen($anfrage)->getStatusCode())->toBe(503);
});
