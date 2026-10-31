<?php

use Peppermint\Mailbox\Schreiben\BrueckenSchreibhilfe;
use Peppermint\Mailbox\Schreiben\Kanalklient;
use Peppermint\Mailbox\Schreiben\Kanalstrecke;

/**
 * Der Kanal-Klient (31.10.2026).
 *
 * Umgezogen aus dem Projekt-Manager, wo er als Teil von
 * `AiBrainChannelService` lag — 241 Zeilen, von denen drei Produkte nichts
 * hatten. Hier steht die Teilmenge, die ein Dienst am Editor braucht.
 *
 * Geprueft wird, was OHNE Gegenstelle pruefbar ist: die Entscheidungen. Der
 * Lauf gegen ein echtes Brain gehoert in ein Produkt, nicht hierhin.
 */
it('wartet bei queued, running und needs_input — und sonst nicht', function () {
    /*
     * `needs_input` ist der, den man falsch macht: Der Agent fragt zurueck,
     * ist also noch NICHT fertig. Wer ihn als Ende behandelt, bekommt ein
     * leeres Ergebnis und meldet „antwortet nicht", obwohl jemand auf eine
     * Antwort wartet.
     */
    expect(Kanalklient::wartet(Kanalklient::QUEUED))->toBeTrue()
        ->and(Kanalklient::wartet(Kanalklient::RUNNING))->toBeTrue()
        ->and(Kanalklient::wartet(Kanalklient::NEEDS_INPUT))->toBeTrue()
        ->and(Kanalklient::wartet(Kanalklient::DONE))->toBeFalse()
        ->and(Kanalklient::wartet(Kanalklient::FAILED))->toBeFalse();
});

describe('das Ergebnis entpacken', function () {
    it('nimmt eine Liste, wie sie ist', function () {
        expect(Kanalklient::entpacken(['text' => 'Guten Tag']))->toBe(['text' => 'Guten Tag']);
    });

    it('entpackt eine JSON-Zeichenkette', function () {
        expect(Kanalklient::entpacken('{"result":"Guten Tag"}'))->toBe(['result' => 'Guten Tag']);
    });

    it('entpackt auch einen Markdown-Block — am anderen Ende sitzt ein Sprachmodell', function () {
        /*
         * Das ist der Fall, den man nicht erfindet, sondern erlebt: Ein Modell
         * antwortet mit ```json … ```, weil es gelernt hat, dass man Code so
         * zeigt. Ohne diese Zeile ist die Antwort „kaputt", obwohl sie
         * richtig ist.
         */
        expect(Kanalklient::entpacken("```json\n{\"result\":\"Guten Tag\"}\n```"))->toBe(['result' => 'Guten Tag']);
    });

    it('gibt null, wenn gar nichts Brauchbares kommt', function () {
        expect(Kanalklient::entpacken(''))->toBeNull()
            ->and(Kanalklient::entpacken('kein JSON'))->toBeNull()
            ->and(Kanalklient::entpacken(null))->toBeNull();
    });
});

describe('die Brueckenhilfe', function () {
    it('nennt den Kanal, den der Projekt-Manager seit je benutzt', function () {
        // Nicht neu erfunden: Dahinter steht derselbe Agent.
        expect(BrueckenSchreibhilfe::KANAL)->toBe('spell-check');
    });

    it('ist nicht verfuegbar, solange die Bruecke nicht eingerichtet ist', function () {
        /*
         * Die Klasse zu HABEN heisst nicht, sie benutzen zu koennen: AI Brain
         * traegt das Bruecken-Paket, ohne es einzurichten — es ist die Nabe.
         * Ein Produkt, das hier `true` saehe, bände einen Dienst, der ins
         * Leere ruft, und zeigte einen Knopf, der nie funktioniert.
         */
        config(['ai-brain-bridge.oauth.client_id' => null]);

        expect(Kanalklient::eingerichtet())->toBeFalse()
            ->and(BrueckenSchreibhilfe::verfuegbar())->toBeFalse();
    });
});

describe('die Kanalstrecke', function () {
    /*
     * Die Naht aus #9145-Umfeld (31.10.2026), vorgeschlagen von `mail-2b`.
     *
     * Was hier geprueft wird, ist genau das, was AI Brain braucht: dass der
     * Klient das WARTEN beitraegt und das Produkt nur den TRANSPORT. Mit einer
     * Attrappe als Strecke laeuft das ohne Gegenstelle und ohne Netz — und
     * damit ist zugesichert, dass die Teilung haelt und nicht beim naechsten
     * Umbau lautlos zurueckfaellt.
     */
    function strecke(array $staende, ?callable $beim = null): Kanalstrecke
    {
        return new class($staende, $beim) implements Kanalstrecke
        {
            public int $gefragt = 0;

            public array $hineingeschickt = [];

            public function __construct(private array $staende, private $beim) {}

            public function eingerichtet(): bool
            {
                return true;
            }

            public function hineinschicken(string $kanal, array $nutzlast): int
            {
                $this->hineingeschickt[] = [$kanal, $nutzlast];

                return 4711;
            }

            public function nachsehen(int $chatId): array
            {
                $this->gefragt++;

                if ($this->beim) {
                    ($this->beim)($chatId);
                }

                return array_shift($this->staende) ?? ['status' => Kanalklient::DONE, 'result' => null, 'error' => null];
            }
        };
    }

    it('nimmt den Weg, den das Produkt mitbringt — und nicht die Bruecke', function () {
        $weg = strecke([['status' => Kanalklient::DONE, 'result' => ['text' => 'Guten Tag'], 'error' => null]]);

        $ergebnis = (new Kanalklient(strecke: $weg))->fragen('spell-check', ['text' => 'guten tag']);

        expect($ergebnis)->toBe(['text' => 'Guten Tag'])
            ->and($weg->hineingeschickt)->toBe([['spell-check', ['text' => 'guten tag']]]);
    });

    it('pollt weiter, solange die Strecke queued, running oder needs_input sagt', function () {
        /*
         * Der eigentliche Gewinn der Naht: Diese Schleife existiert EINMAL.
         * `needs_input` in der Mitte ist absichtlich — es ist der Status, den
         * eine zweite, nachgebaute Schleife als Ende missversteht.
         */
        $weg = strecke([
            ['status' => Kanalklient::QUEUED, 'result' => null, 'error' => null],
            ['status' => Kanalklient::NEEDS_INPUT, 'result' => null, 'error' => null],
            ['status' => Kanalklient::RUNNING, 'result' => null, 'error' => null],
            ['status' => Kanalklient::DONE, 'result' => 'fertig', 'error' => null],
        ]);

        expect((new Kanalklient(taktSekunden: 0, strecke: $weg))->fragen('spell-check', []))->toBe('fertig')
            ->and($weg->gefragt)->toBe(4);
    });

    it('macht aus einem failed die Absage der Strecke, nicht einen Absturz', function () {
        $weg = strecke([['status' => Kanalklient::FAILED, 'result' => null, 'error' => 'Kanal kennt den Auftrag nicht']]);

        expect(fn () => (new Kanalklient(strecke: $weg))->fragen('spell-check', []))
            ->toThrow(RuntimeException::class, 'Kanal kennt den Auftrag nicht');
    });

    it('gibt einem failed ohne Grund trotzdem einen Satz', function () {
        /*
         * Sonst steht am Ende eine leere Fehlermeldung auf dem Schirm, und die
         * ist nicht von „nichts passiert" zu unterscheiden.
         */
        $weg = strecke([['status' => Kanalklient::FAILED, 'result' => null, 'error' => null]]);

        expect(fn () => (new Kanalklient(strecke: $weg))->fragen('spell-check', []))
            ->toThrow(RuntimeException::class, 'Die Anfrage konnte nicht verarbeitet werden.');
    });

    it('bricht nach der Frist ab, statt ewig zu warten', function () {
        /*
         * Mit einer Strecke, die nie fertig wird. Die Frist hat eine
         * Untergrenze von 5 Sekunden (`max(5, …)`), und der Takt eine von
         * einer — hier wird deshalb die UHR vorgestellt statt gewartet: Ein
         * Test, der fuenf Sekunden schlaeft, wird irgendwann uebersprungen,
         * und dann prueft ihn niemand mehr.
         */
        $weg = strecke(array_fill(0, 50, ['status' => Kanalklient::RUNNING, 'result' => null, 'error' => null]));

        $vorher = time();
        expect(fn () => (new Kanalklient(fristSekunden: 1, taktSekunden: 0, strecke: $weg))->fragen('spell-check', []))
            ->toThrow(RuntimeException::class, 'Zeitüberschreitung');

        // Der Abbruch kam ueber die Frist und nicht, weil die Attrappe leer war.
        expect($weg->gefragt)->toBeLessThan(50)
            ->and(time() - $vorher)->toBeLessThan(30);
    });

    it('fragt bei `nutzbar` die eigene Strecke und nicht die Bruecke', function () {
        /*
         * Der Fall, der die Naht ausgeloest hat: In AI Brain ist die Bruecke
         * installiert und NICHT eingerichtet. `Kanalklient::eingerichtet()`
         * sagt dort `false` — und wuerde einen Assistenten verstecken, den es
         * ueber die eigene Strecke gibt.
         */
        $klient = new Kanalklient(strecke: strecke([]));

        expect($klient->nutzbar())->toBeTrue()
            ->and(Kanalklient::eingerichtet())->toBeFalse();
    });

    it('reicht `nutzbar` durch die Schreibhilfe durch', function () {
        $hilfe = new BrueckenSchreibhilfe(new Kanalklient(strecke: strecke([])));

        expect($hilfe->nutzbar())->toBeTrue()
            ->and(BrueckenSchreibhilfe::verfuegbar())->toBeFalse();
    });

    it('holt den Text durch die eigene Strecke — die drei Gestalten gelten weiter', function () {
        /*
         * Zusammen gelesen: Das Produkt bringt den Transport, das Paket bringt
         * das Entpacken. Ein Kanal, der seine Antwort in einen Markdown-Block
         * legt, wird also auch ueber eine eigene Strecke verstanden.
         */
        $weg = strecke([['status' => Kanalklient::DONE, 'result' => "```json\n{\"result\":\"Guten Tag\"}\n```", 'error' => null]]);

        expect((new BrueckenSchreibhilfe(new Kanalklient(strecke: $weg)))->verarbeiten('guten tag', 'correct'))
            ->toBe('Guten Tag');
    });
});

describe('die Huelle verwerfen, die Antwort nicht', function () {
    /*
     * Gefunden am 31.10.2026 beim Zusichern der Kanalstrecke.
     *
     * `entpacken()` kennt den Markdown-Fall seit je — aber `herausholen()`
     * fragte vorher die blanke Zeichenkette ZUERST, und eine Zeichenkette mit
     * einem Zaun drumherum ist eine nicht-leere Zeichenkette. Der Fall war
     * also von dort aus unerreichbar, und der Mensch bekam den Zaun in seine
     * Mail.
     */
    function mitErgebnis(mixed $ergebnis): BrueckenSchreibhilfe
    {
        $weg = new class($ergebnis) implements Kanalstrecke
        {
            public function __construct(private mixed $ergebnis) {}

            public function eingerichtet(): bool
            {
                return true;
            }

            public function hineinschicken(string $kanal, array $nutzlast): int
            {
                return 1;
            }

            public function nachsehen(int $chatId): array
            {
                return ['status' => Kanalklient::DONE, 'result' => $this->ergebnis, 'error' => null];
            }
        };

        return new BrueckenSchreibhilfe(new Kanalklient(strecke: $weg));
    }

    it('schaelt einen Markdown-Block ab, statt ihn in die Mail zu schreiben', function () {
        expect(mitErgebnis("```json\n{\"result\":\"Guten Tag\"}\n```")->verarbeiten('guten tag', 'correct'))
            ->toBe('Guten Tag');
    });

    it('nimmt eine blanke Antwort unveraendert — das ist der Normalfall', function () {
        expect(mitErgebnis('Guten Tag, Herr Meier.')->verarbeiten('guten tag herr meier', 'correct'))
            ->toBe('Guten Tag, Herr Meier.');
    });

    it('laesst einen Text durch, der zufaellig gueltiges JSON ist', function () {
        /*
         * Die Gegenprobe zur neuen Reihenfolge: Entpacken allein darf nicht
         * entscheiden. `{"a":1}` entpackt sich zu einer Liste — aber ohne
         * `result` und ohne `text`, also war es keine Huelle, sondern der
         * Text. Wer hier nur auf „ist eine Liste" prueft, verschluckt ihn.
         */
        expect(mitErgebnis('{"a":1}')->verarbeiten('{"a":1}', 'correct'))->toBe('{"a":1}');
    });

    it('sagt ab, wenn gar nichts Brauchbares kommt — und leert den Editor nicht', function () {
        expect(fn () => mitErgebnis(null)->verarbeiten('guten tag', 'correct'))
            ->toThrow(RuntimeException::class, 'Der Schreibassistent hat nichts zurückgegeben.');
    });
});
