import { describe, expect, it } from 'vitest';
import { germanLabels } from '../src/germanLabels';

/**
 * Zwei Woerter, die beim Zusammenlegen still verloren gegangen waeren
 * (01.11.2026, Signatur-Umzug Stufe 6).
 *
 * Beide stammen aus derselben Arbeit: Der Projekt-Manager gibt seine eigene
 * Woerterliste auf und nimmt die des Pakets. Dabei faellt auf, was das Paket
 * noch nicht kann — und was es sich selbst widerspricht.
 */
describe('die Herkunft eines Empfaengervorschlags', () => {
    it('nennt einen zuletzt benutzten Empfaenger „Zuletzt"', () => {
        /*
         * DIE Zusicherung. Die Quelle `recent` gab es hier bis heute nicht,
         * und der Rueckfall nannte sie wie jede unbekannte: „Verlauf". Der
         * Projekt-Manager hatte dafuer ein eigenes Wort — es waere beim
         * Angleichen verschwunden, ohne dass jemand es entschieden haette.
         */
        expect(germanLabels.verfassen.source('recent')).toBe('Zuletzt');
    });

    it('laesst die drei bekannten Quellen unveraendert', () => {
        expect(germanLabels.verfassen.source('colleague')).toBe('Kollege');
        expect(germanLabels.verfassen.source('adressbuch')).toBe('Adressbuch');
        expect(germanLabels.verfassen.source('crm')).toBe('CRM');
    });

    it('faellt bei einer unbekannten Quelle weiterhin auf „Verlauf" zurueck', () => {
        // Der Rueckfall bleibt. Er ist richtig fuer das, was er heisst — er
        // war nur fuer `recent` die falsche Antwort.
        expect(germanLabels.verfassen.source('etwas-neues')).toBe('Verlauf');
    });
});

describe('der Nachrichtenrumpf', () => {
    it('heisst in der Verfassen-Vorschau genauso wie in Antwort und Weiterleitung', () => {
        /*
         * Das Paket fuehrte zwei Woerterlisten fuer denselben Gegenstand:
         * `verfassen.body` sagte „Kein Inhalt" und „Vorschau", `rumpf` sagte
         * „Diese E-Mail hat keinen Inhalt." und „E-Mail Inhalt".
         *
         * Niemand hatte das entschieden — die Bloecke sind zu verschiedenen
         * Zeiten entstanden. Aufgefallen ist es erst, als ein Produkt seine
         * eigenen Woerter aufgab und zwei davon sich NUR wegen dieses
         * Widerspruchs geaendert haetten.
         *
         * Die Zusicherung vergleicht die beiden Bloecke miteinander statt
         * gegen feste Zeichenketten: Wer eines der Woerter umformuliert, soll
         * gezwungen sein, beide anzufassen.
         */
        expect(germanLabels.verfassen.body).toEqual(germanLabels.rumpf);
    });
});
