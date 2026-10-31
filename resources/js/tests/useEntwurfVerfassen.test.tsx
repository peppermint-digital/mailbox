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
