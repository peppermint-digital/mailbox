import { describe, expect, it, vi } from 'vitest';
import { sendeNachricht } from '../src/transport';

/**
 * Senden mit Dateien (31.10.2026).
 *
 * Die drei Verfassen-Seiten ausserhalb des Managers bauten jede dieselbe
 * `fetch`-Zeile, und alle drei sendeten JSON. Deshalb konnten sie keine
 * Dateien anhängen — nicht weil der Server keine nähme (er tut es seit jeher),
 * sondern weil ein Browser eine Datei nicht in JSON unterbringt.
 */
function antwort(inhalt: unknown, ok = true, status = 200) {
    return {
        ok,
        status,
        json: () => Promise.resolve(inhalt),
    } as unknown as Response;
}

const DATEN = {
    to: [{ email: 'kunde@example.test', name: 'Kunde' }],
    subject: 'Angebot',
    body_html: '<p>Guten <b>Tag</b></p>',
};

describe('sendeNachricht', () => {
    it('schickt FormData und NICHT JSON — sonst gehen die Dateien lautlos verloren', async () => {
        /*
         * DIE Zusicherung. Wer hier versehentlich wieder JSON baut, verliert
         * die Anhänge, ohne dass irgendwo ein Fehler erscheint: Der Server
         * sieht kein `attachments`, sendet die Nachricht und meldet Erfolg.
         * Der Absender erfährt nie, dass die Rechnung fehlte — genau die
         * Fehlerklasse von Bug #1155.
         */
        const faelschung = vi.fn().mockResolvedValue(antwort({ success: true, message_id: '<a@b>' }));

        await sendeNachricht('/mailbox/1/send', DATEN, [], { csrfToken: 'abc', fetch: faelschung });

        const [, optionen] = faelschung.mock.calls[0]!;

        expect(optionen.body, 'der Rumpf muss FormData sein').toBeInstanceOf(FormData);
        expect(typeof optionen.body).not.toBe('string');
    });

    it('setzt KEINEN Content-Type — den wuerfelt der Browser mitsamt der boundary', async () => {
        // Von Hand gesetzt liefert man eine Grenze mit, die es nicht gibt, und
        // der Server findet kein einziges Feld.
        const faelschung = vi.fn().mockResolvedValue(antwort({ success: true }));

        await sendeNachricht('/mailbox/1/send', DATEN, [], { csrfToken: 'abc', fetch: faelschung });

        expect(Object.keys(faelschung.mock.calls[0]![1].headers)).not.toContain('Content-Type');
    });

    it('legt die Dateien bei', async () => {
        const faelschung = vi.fn().mockResolvedValue(antwort({ success: true }));
        const datei = new File(['inhalt'], 'Rechnung.pdf', { type: 'application/pdf' });

        await sendeNachricht('/mailbox/1/send', DATEN, [datei], { csrfToken: 'abc', fetch: faelschung });

        const koffer = faelschung.mock.calls[0]![1].body as FormData;

        expect(koffer.getAll('attachments[]')).toHaveLength(1);
        expect((koffer.get('attachments[]') as File).name).toBe('Rechnung.pdf');
    });

    it('schreibt Adressen in der Form, die Laravel aus FormData liest', async () => {
        /*
         * `to[0][email]`, nicht `to` mit JSON darin. Letzteres käme als
         * Zeichenkette an und fiele in der Prüfung durch — mit einer Meldung
         * über das Feld `to`, die niemand mit „die Adresse steht doch da"
         * zusammenbringt.
         */
        const faelschung = vi.fn().mockResolvedValue(antwort({ success: true }));

        await sendeNachricht(
            '/mailbox/1/send',
            { ...DATEN, cc: [{ email: 'chef@example.test' }] },
            [],
            { csrfToken: 'abc', fetch: faelschung },
        );

        const koffer = faelschung.mock.calls[0]![1].body as FormData;

        expect(koffer.get('to[0][email]')).toBe('kunde@example.test');
        expect(koffer.get('to[0][name]')).toBe('Kunde');
        expect(koffer.get('cc[0][email]')).toBe('chef@example.test');
        // Ohne Namen kein leeres Feld: `""` landete sonst im Kopf der Mail.
        expect(koffer.get('cc[0][name]')).toBeNull();
    });

    it('baut den Klartext-Zweig, wenn das Produkt keinen mitgibt', async () => {
        // Eine Mail ohne ihn ist bei Spamfiltern ein schwaches Signal — und
        // sonst baut ihn jedes Produkt selbst, jedes mit einem anderen Regex.
        const faelschung = vi.fn().mockResolvedValue(antwort({ success: true }));

        await sendeNachricht('/mailbox/1/send', DATEN, [], { csrfToken: 'abc', fetch: faelschung });

        expect((faelschung.mock.calls[0]![1].body as FormData).get('body_text')).toBe('Guten Tag');
    });

    it('reicht die Entwurfsfelder durch, aber nur wenn es welche gibt', async () => {
        const faelschung = vi.fn().mockResolvedValue(antwort({ success: true }));

        await sendeNachricht(
            '/mailbox/1/send',
            { ...DATEN, draft_folder: 'Entw&APw-rfe', draft_uid: '42' },
            [],
            { csrfToken: 'abc', fetch: faelschung },
        );

        const koffer = faelschung.mock.calls[0]![1].body as FormData;

        expect(koffer.get('draft_folder')).toBe('Entw&APw-rfe');
        expect(koffer.get('draft_uid')).toBe('42');
        expect(koffer.get('quoted_html')).toBeNull();
    });

    it('gibt den Satz des Servers weiter, statt einen eigenen zu erfinden', async () => {
        // Die Absage bei nicht ladbaren Entwurfs-Anhängen sagt genau, was
        // passiert ist. Sie durch „Fehler beim Senden" zu ersetzen wäre ein
        // Rückschritt.
        const faelschung = vi
            .fn()
            .mockResolvedValue(antwort({ success: false, message: 'Die Nachricht wurde NICHT gesendet — …' }, false, 502));

        const ergebnis = await sendeNachricht('/mailbox/1/send', DATEN, [], { csrfToken: 'abc', fetch: faelschung });

        expect(ergebnis.ok).toBe(false);
        expect(ergebnis.message).toContain('NICHT gesendet');
    });

    it('bleibt verstaendlich, wenn die Antwort gar kein JSON ist', async () => {
        // Die 413-Seite eines Webservers bei zu grossen Anhängen ist HTML.
        const faelschung = vi.fn().mockResolvedValue({
            ok: false,
            status: 413,
            json: () => Promise.reject(new Error('kein JSON')),
        } as unknown as Response);

        const ergebnis = await sendeNachricht('/mailbox/1/send', DATEN, [], { csrfToken: 'abc', fetch: faelschung });

        expect(ergebnis.ok).toBe(false);
        expect(ergebnis.message).toContain('413');
    });

    it('macht aus einem Netzfehler eine Absage und keinen Absturz', async () => {
        const faelschung = vi.fn().mockRejectedValue(new Error('offline'));

        const ergebnis = await sendeNachricht('/mailbox/1/send', DATEN, [], { csrfToken: 'abc', fetch: faelschung });

        expect(ergebnis.ok).toBe(false);
        expect(ergebnis.message).toContain('keine Verbindung');
    });
});
