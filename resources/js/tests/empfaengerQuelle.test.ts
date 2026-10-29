import { afterEach, describe, expect, it, vi } from 'vitest';
import { empfaengerQuelle, empfaengerQuelleFuer } from '../src/VerfassenFeld';

/**
 * Das Adressbuch des Verfassen-Feldes liegt unter dem Routen-Vorsatz des
 * Produkts (#9116).
 *
 * Drei Produkte haben es unter `/mailbox/address-book`, der Projekt-Manager
 * unter `/emails/address-book`. Eine feste Adresse hier hiesse: Entweder der
 * Manager kann dieses Feld nicht benutzen, oder er baut es nach — und
 * Nachbauten laufen auseinander, leise.
 */
function mitFetch(): { gesehen: string[] } {
    const gesehen: string[] = [];

    vi.stubGlobal(
        'fetch',
        vi.fn(async (url: string) => {
            gesehen.push(String(url));

            return { ok: true, json: async () => ({ recipients: [] }) };
        }),
    );

    return { gesehen };
}

afterEach(() => vi.unstubAllGlobals());

describe('empfaengerQuelleFuer', () => {
    it('baut beide Wege unter dem gegebenen Vorsatz', async () => {
        const { gesehen } = mitFetch();
        const quelle = empfaengerQuelleFuer('/emails');
        const signal = new AbortController().signal;

        await quelle.all(signal);
        await quelle.search('mül ler', signal);

        expect(gesehen[0]).toBe('/emails/address-book/colleagues');
        // Und die Frage wird kodiert — sonst bricht ein Leerzeichen die Adresse.
        expect(gesehen[1]).toBe('/emails/address-book/search?q=m%C3%BCl%20ler');
    });

    it('bleibt bei /mailbox, wo nichts anderes gesagt ist', async () => {
        /*
         * Die wichtigere Richtung: CRM, Verwaltung und AI Brain geben nichts
         * an. Waere die Vorgabe falsch, fiele dort das Adressbuch aus — und
         * zwar erst in Produktion, denn an einer Zeichenkette hat die
         * Typpruefung nichts zu beanstanden.
         */
        const { gesehen } = mitFetch();

        await empfaengerQuelle.all(new AbortController().signal);

        expect(gesehen[0]).toBe('/mailbox/address-book/colleagues');
    });

    it('gibt fuer denselben Vorsatz DASSELBE Objekt zurueck', () => {
        /*
         * Nicht Feinschliff, sondern der Grund, warum es eine gemerkte Fabrik
         * und keine einfache Funktion ist: Das Formular haengt seine
         * Verzoegerung an die Identitaet der Quelle. Eine bei jedem Zeichnen
         * neue Quelle stellt sie zurueck — und dann sucht das Feld bei jedem
         * Tastendruck von vorn.
         */
        expect(empfaengerQuelleFuer('/emails')).toBe(empfaengerQuelleFuer('/emails'));
        expect(empfaengerQuelleFuer('/emails')).not.toBe(empfaengerQuelleFuer('/mailbox'));
        expect(empfaengerQuelleFuer('/mailbox')).toBe(empfaengerQuelle);
    });
});
