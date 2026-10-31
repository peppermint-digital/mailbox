<?php

use Peppermint\Mailbox\Schreiben\BrueckenSchreibhilfe;
use Peppermint\Mailbox\Schreiben\Kanalklient;

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
