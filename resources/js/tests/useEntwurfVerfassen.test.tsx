import { describe, expect, it } from 'vitest';
import { act } from 'react';
import { render } from './support/render';
import { useEntwurfVerfassen, type EntwurfVerfassen, type VerfasstenEntwurf } from '../src/useEntwurfVerfassen';

/**
 * Einen Entwurf in den Verfasser bringen (31.10.2026).
 *
 * Der Haken gibt ANFANGSWERTE heraus und fuehrt den Zustand nicht doppelt —
 * das Formular ist gefuehrt, und zwei Stellen, die denselben Zustand fuehren,
 * sind die bekannte Sorte Fehler, bei der ein Feld nach dem ersten Tippen auf
 * den Entwurf zurueckspringt.
 */
function probe(entwurf: VerfasstenEntwurf | null, vorbelegung?: Parameters<typeof useEntwurfVerfassen>[1]) {
    const gesehen: { aktuell: EntwurfVerfassen | null } = { aktuell: null };

    function Probe() {
        gesehen.aktuell = useEntwurfVerfassen(entwurf, vorbelegung);

        return null;
    }

    const { unmount } = render(<Probe />);

    return { gesehen, unmount };
}

const ENTWURF: VerfasstenEntwurf = {
    account_id: 7,
    folder: 'Entw&APw-rfe',
    uid: 42,
    to: [{ email: 'kunde@example.test', name: 'Kunde' }],
    cc: [],
    subject: 'Angebot',
    body_html: '<p>Guten Tag</p>',
    attachments: [{ filename: 'Rechnung.pdf', size: 1024 }],
};

describe('useEntwurfVerfassen', () => {
    it('waehlt das Postfach DES ENTWURFS vor, nicht das erste der Liste', () => {
        /*
         * Der Befund von mail-rollout3: Die Verfassen-Seiten setzten ihr Konto
         * auf `accounts[0]`. Ein Entwurf aus einem zweiten Postfach ging damit
         * ueber das falsche Konto hinaus — und abgeloest wurde er auch nicht,
         * weil das Abloesen dasselbe falsche Postfach fragt.
         */
        const { gesehen, unmount } = probe(ENTWURF, { kontoId: 1 });

        expect(gesehen.aktuell!.kontoId).toBe(7);
        unmount();
    });

    it('nimmt ohne Entwurf die Vorbelegung des Produkts', () => {
        const { gesehen, unmount } = probe(null, { kontoId: 1 });

        expect(gesehen.aktuell!.kontoId).toBe(1);
        unmount();
    });

    it('uebernimmt Empfaenger, Betreff und Text', () => {
        const { gesehen, unmount } = probe(ENTWURF);

        expect(gesehen.aktuell!.an).toEqual([{ email: 'kunde@example.test', name: 'Kunde' }]);
        expect(gesehen.aktuell!.betreff).toBe('Angebot');
        expect(gesehen.aktuell!.inhalt).toBe('<p>Guten Tag</p>');
        unmount();
    });

    it('springt nach dem Tippen NICHT auf den Entwurf zurueck', () => {
        // Die Anfangswerte sind Anfangswerte. Ein `useEffect`, der den Entwurf
        // nachtraegt, waere genau dieser Rueckspringer.
        const { gesehen, unmount } = probe(ENTWURF);

        act(() => gesehen.aktuell!.setBetreff('Angebot, ueberarbeitet'));

        expect(gesehen.aktuell!.betreff).toBe('Angebot, ueberarbeitet');
        unmount();
    });

    it('gibt die zwei Sendefelder heraus, damit das Paket abloesen kann', () => {
        const { gesehen, unmount } = probe(ENTWURF);

        expect(gesehen.aktuell!.sendeFelder).toEqual({ draft_folder: 'Entw&APw-rfe', draft_uid: '42' });
        unmount();
    });

    it('gibt ohne Entwurf LEERE Sendefelder — damit das Produkt sie bedenkenlos einstreut', () => {
        const { gesehen, unmount } = probe(null);

        expect(gesehen.aktuell!.sendeFelder).toEqual({});
        unmount();
    });

    it('reicht die vorhandenen Anhaenge zum Anzeigen durch', () => {
        const { gesehen, unmount } = probe(ENTWURF);

        expect(gesehen.aktuell!.vorhandeneAnhaenge).toEqual([{ filename: 'Rechnung.pdf', size: 1024 }]);
        unmount();
    });
});

describe('die Blindkopie (31.10.2026)', () => {
    /*
     * Eine Luecke und keine Entscheidung: `BearbeitetEntwuerfe` liefert `bcc`
     * mit, `HandlesSending::send` nimmt es an — nur das Stueck dazwischen
     * fehlte. Gefunden von `mail-2b` beim Vergleich der vier Verfassen-Seiten.
     */
    it('gibt ein BCC-Feld heraus, damit der Zustand NICHT im Produkt landet', () => {
        const { gesehen, unmount } = probe(null);

        expect(gesehen.aktuell!.blindkopie).toEqual([]);

        act(() => gesehen.aktuell!.setBlindkopie([{ email: 'kopie@example.test' }]));

        expect(gesehen.aktuell!.blindkopie).toEqual([{ email: 'kopie@example.test' }]);

        unmount();
    });

    it('faengt bei einem Entwurf LEER an — im abgelegten Entwurf steht kein BCC', () => {
        /*
         * Nicht „noch nicht umgesetzt": Ein Entwurf im IMAP-Ordner traegt
         * keinen `Bcc`-Kopf, weil der beim Senden entfernt wird. Der Server
         * liefert darum `bcc => []`, und das ist die Wahrheit und keine
         * Luecke. Der Test haelt es fest, damit niemand es „reparieren" geht.
         */
        const { gesehen, unmount } = probe(ENTWURF);

        expect(gesehen.aktuell!.blindkopie).toEqual([]);

        unmount();
    });

    it('nimmt ein BCC an, wenn der Entwurf doch eines mitbringt', () => {
        // Falls ein Anbieter es doch ablegt: dann gehoert es ins Feld und
        // nicht weggeworfen.
        const { gesehen, unmount } = probe({ ...ENTWURF, bcc: [{ email: 'still@example.test' }] });

        expect(gesehen.aktuell!.blindkopie).toEqual([{ email: 'still@example.test' }]);

        unmount();
    });
});
