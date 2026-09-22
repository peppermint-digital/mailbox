import { act } from 'react';
import { beforeEach, describe, expect, it } from 'vitest';
import { useMailboxPreference } from '../src/useMailboxPreference';
import { render } from './support/render';

/**
 * Ein Schalter, der einen Neuaufruf ueberlebt (22.09.2026).
 *
 * Die eine Stelle, die hier wirklich zaehlt, ist der Schutz um
 * `localStorage`: In manchen Browsern wirft schon das LESEN im privaten
 * Fenster eine Ausnahme, und ein ungeschuetzter Zugriff nimmt die ganze Seite
 * mit. Gefunden wird so etwas sonst erst in einem Browser, in dem niemand
 * testet.
 */
function haenge(key: string, standard: boolean) {
    const ref: { current: [boolean, (w: boolean) => void] } = { current: null as never };

    function Probe() {
        ref.current = useMailboxPreference(key, standard);

        return null;
    }

    const { unmount } = render(<Probe />);

    return { ref, unmount };
}

describe('useMailboxPreference', () => {
    beforeEach(() => localStorage.clear());

    it('nimmt die Vorgabe, solange nichts gespeichert ist', () => {
        const { ref, unmount } = haenge('test.a', true);

        expect(ref.current[0]).toBe(true);
        unmount();
    });

    it('merkt sich eine Aenderung', () => {
        const { ref, unmount } = haenge('test.b', true);

        act(() => ref.current[1](false));

        expect(ref.current[0]).toBe(false);
        expect(localStorage.getItem('test.b')).toBe('false');
        unmount();
    });

    it('findet den gespeicherten Wert beim naechsten Aufruf wieder', () => {
        localStorage.setItem('test.c', 'false');

        const { ref, unmount } = haenge('test.c', true);

        expect(ref.current[0]).toBe(false);
        unmount();
    });

    it('bleibt stehen, wenn der Speicher gar nicht antwortet', () => {
        // Der Fall, der sonst die ganze Seite mitnimmt.
        const echt = Object.getOwnPropertyDescriptor(window, 'localStorage')!;
        Object.defineProperty(window, 'localStorage', {
            configurable: true,
            get() {
                throw new Error('Zugriff verweigert');
            },
        });

        try {
            const { ref, unmount } = haenge('test.d', true);

            expect(ref.current[0]).toBe(true);
            act(() => ref.current[1](false));
            expect(ref.current[0]).toBe(false);
            unmount();
        } finally {
            Object.defineProperty(window, 'localStorage', echt);
        }
    });
});
