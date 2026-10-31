import { beforeEach, describe, expect, it, vi } from 'vitest';
import { leereSignaturQuelle, signaturQuelleFuer } from '../src/VerfassenFeld';

/**
 * Die Vorgabe-Quelle fuer Signaturen (31.10.2026).
 *
 * Gefunden von `mail-2b`: Eine `signatureSource` gab nur der Projekt-Manager
 * mit; die anderen drei uebergaben die leere Quelle, und das Formular zeichnete
 * die Zeile gar nicht.
 *
 * Geprueft wird das, was an einer gemerkten, ihren Inhalt aendernden Quelle
 * still schiefgehen kann — und das ist mehr, als es aussieht.
 */
describe('signaturQuelleFuer', () => {
    beforeEach(() => {
        vi.unstubAllGlobals();
    });

    it('fragt die Route des Vorsatzes und fuellt `signatures`', async () => {
        const holen = vi.fn(async () => ({
            ok: true,
            json: async () => ({ signatures: [{ id: 1, name: 'Kurz', body_html: '<p>Gruss</p>' }], available: true }),
        }));
        vi.stubGlobal('fetch', holen);

        const quelle = signaturQuelleFuer('/emails');
        await quelle.load(7);

        expect(holen.mock.calls[0]![0]).toBe('/emails/7/signatures');
        expect(quelle.signatures).toHaveLength(1);
    });

    it('gibt DASSELBE Objekt fuer denselben Vorsatz heraus', () => {
        /*
         * Nicht Sparsamkeit, sondern der Unterschied zwischen „laedt einmal"
         * und einer Schleife: `load` steht in der Abhaengigkeitsliste eines
         * Effekts im Formular. Ein neues Objekt heisst eine neue Funktion,
         * heisst der Effekt laeuft erneut, heisst `load()` — und das setzt am
         * Ende den Zustand, der das naechste Zeichnen ausloest.
         */
        expect(signaturQuelleFuer('/mailbox')).toBe(signaturQuelleFuer('/mailbox'));
        expect(signaturQuelleFuer('/mailbox')).not.toBe(signaturQuelleFuer('/emails'));
    });

    it('fragt GAR NICHT, solange kein Postfach gewaehlt ist', async () => {
        /*
         * Das Formular ruft `load(undefined)`, solange keines gewaehlt ist.
         * Eine Anfrage an `…/undefined/signatures` waere eine 404 — und die
         * sieht aus wie „keine Signaturen".
         */
        const holen = vi.fn();
        vi.stubGlobal('fetch', holen);

        const quelle = signaturQuelleFuer('/ohne-konto');
        await quelle.load(undefined);

        expect(holen).not.toHaveBeenCalled();
        expect(quelle.signatures).toEqual([]);
    });

    it('laesst die Liste leer, wenn die Antwort nicht gut ist — und wirft nicht', async () => {
        // Ohne Signatur kann man eine Mail schreiben, ohne Formular nicht.
        vi.stubGlobal('fetch', vi.fn(async () => ({ ok: false, status: 500, json: async () => ({}) })));

        const quelle = signaturQuelleFuer('/fehler');
        await quelle.load(1);

        expect(quelle.signatures).toEqual([]);
    });

    it('uebersteht einen Netzfehler', async () => {
        vi.stubGlobal(
            'fetch',
            vi.fn(async () => {
                throw new TypeError('offline');
            }),
        );

        const quelle = signaturQuelleFuer('/offline');

        await expect(quelle.load(1)).resolves.toBeUndefined();
        expect(quelle.signatures).toEqual([]);
    });

    it('nimmt die erste Vorgabe des Produkts als vorausgewaehlte', async () => {
        vi.stubGlobal(
            'fetch',
            vi.fn(async () => ({
                ok: true,
                json: async () => ({
                    signatures: [
                        { id: 1, name: 'Kurz', body_html: '<p>a</p>' },
                        { id: 2, name: 'Lang', body_html: '<p>b</p>', is_default: true },
                        { id: 3, name: 'Noch eine', body_html: '<p>c</p>', is_default: true },
                    ],
                }),
            })),
        );

        const quelle = signaturQuelleFuer('/vorgabe');
        await quelle.load(1);

        expect(quelle.defaultSignature()?.id).toBe(2);
    });

    it('hat ohne Vorgabe keine vorausgewaehlte, statt die erste zu nehmen', async () => {
        /*
         * Die erste zu nehmen waere bequem und falsch: Dann unterschreibt
         * jemand mit einer Signatur, die niemand gewaehlt hat.
         */
        vi.stubGlobal(
            'fetch',
            vi.fn(async () => ({
                ok: true,
                json: async () => ({ signatures: [{ id: 1, name: 'Kurz', body_html: '<p>a</p>' }] }),
            })),
        );

        const quelle = signaturQuelleFuer('/ohne-vorgabe');
        await quelle.load(1);

        expect(quelle.defaultSignature()).toBeNull();
    });

    it('aendert ihren Inhalt und bleibt dasselbe Objekt', async () => {
        /*
         * Der Punkt, an dem eine naheliegende Umarbeitung alles brechen
         * wuerde: Das Formular haelt die Quelle fest und liest `signatures`
         * bei jedem Zeichnen neu. Wer `load()` ein frisches Objekt
         * zurueckgeben laesst, aendert nichts am Formular — es haelt das alte.
         */
        vi.stubGlobal(
            'fetch',
            vi.fn(async () => ({ ok: true, json: async () => ({ signatures: [{ id: 5, name: 'X', body_html: '' }] }) })),
        );

        const quelle = signaturQuelleFuer('/selbes-objekt');
        const vorher = quelle;

        await quelle.load(1);

        expect(quelle).toBe(vorher);
        expect(vorher.signatures).toHaveLength(1);
    });
});

describe('leereSignaturQuelle', () => {
    it('bleibt fuer ein Produkt, das ausdruecklich keine fuehrt', async () => {
        await expect(leereSignaturQuelle.load()).resolves.toBeUndefined();

        expect(leereSignaturQuelle.signatures).toEqual([]);
        expect(leereSignaturQuelle.defaultSignature()).toBeNull();
    });
});
