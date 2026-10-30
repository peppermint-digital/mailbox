import { describe, expect, it, vi } from 'vitest';
import MailMessageView, { type MailMessageViewLabels, type MailMessageViewMessage } from '../src/MailMessageView';
import { click, render, textOf } from './support/render';

const labels: MailMessageViewLabels = {
    empty: 'Wählen Sie eine E-Mail aus',
    archived: 'Archiv',
    archivedTitle: 'Liegt nicht mehr im Postfach',
    editDraft: 'Weiter bearbeiten',
    reply: 'Antworten',
    replyAll: 'Allen antworten',
    forward: 'Weiterleiten',
    markRead: 'Als gelesen markieren',
    markUnread: 'Als ungelesen markieren',
    flag: 'Markieren',
    unflag: 'Markierung entfernen',
    archive: 'Archivieren',
    archiveConversation: (n) => `Ganze Konversation archivieren (${n} Nachrichten)`,
    delete: 'Löschen',
    from: 'Von:',
    date: 'Datum:',
    to: 'An:',
    cc: 'CC:',
    downloadAttachment: (f) => `${f} herunterladen`,
    body: { empty: 'Kein Inhalt', remoteBlocked: 'Blockiert', loadImages: 'Bilder laden', frameTitle: 'Inhalt' },
};

function message(overrides: Partial<MailMessageViewMessage> = {}): MailMessageViewMessage {
    return {
        uid: 1, message_id: '<a@b>', subject: 'Betreff', from_address: 'a@b.de', from_name: 'Absender',
        to: [{ email: 'ich@hier.de', name: 'Ich' }], cc: [], date: '2026-09-14T10:00:00Z',
        body_html: null, body_text: 'Hallo', attachments: [], is_read: true, is_flagged: false, ...overrides,
    };
}

const base = {
    labels,
    formatDateTime: (iso: string) => iso,
    formatAddressList: (a: { email: string; name: string }[]) => a.map((x) => x.email).join(', '),
    formatFileSize: (b: number) => `${b} B`,
    attachmentUrl: (a: { index: number }) => `/anhang/${a.index}`,
};

describe('MailMessageView', () => {
    it('says nothing is picked when nothing is', () => {
        const { container, unmount } = render(<MailMessageView {...base} message={null} />);

        expect(textOf(container)).toContain('Wählen Sie eine E-Mail aus');
        unmount();
    });

    it('shows a spinner while fetching, not the empty state', () => {
        const { container, unmount } = render(<MailMessageView {...base} message={null} loading />);

        expect(container.querySelector('[data-slot="mail-message-loading"]')).not.toBeNull();
        expect(textOf(container)).not.toContain('Wählen Sie eine E-Mail aus');
        unmount();
    });

    it('shows subject, sender and recipients', () => {
        const { container, unmount } = render(<MailMessageView {...base} message={message()} />);

        expect(textOf(container)).toContain('Betreff');
        expect(textOf(container)).toContain('Absender');
        expect(textOf(container)).toContain('ich@hier.de');
        unmount();
    });

    describe('reply-all appears only when there is an "all"', () => {
        it('stays away for a single recipient without CC', () => {
            const { container, unmount } = render(<MailMessageView {...base} message={message()} />);

            expect(container.querySelector('[title="Allen antworten"]')).toBeNull();
            unmount();
        });

        it('appears with a second recipient', () => {
            const two = message({ to: [{ email: 'a@x.de', name: '' }, { email: 'b@x.de', name: '' }] });
            const { container, unmount } = render(<MailMessageView {...base} message={two} onReply={() => {}} />);

            expect(container.querySelector('[title="Allen antworten"]')).not.toBeNull();
            unmount();
        });

        it('appears with a CC', () => {
            const cc = message({ cc: [{ email: 'c@x.de', name: '' }] });
            const { container, unmount } = render(<MailMessageView {...base} message={cc} onReply={() => {}} />);

            expect(container.querySelector('[title="Allen antworten"]')).not.toBeNull();
            unmount();
        });
    });

    describe('an archived message has no toolbar', () => {
        const archived = message({ uid: null, source: 'stored' });

        it('shows the badge instead', () => {
            const { container, unmount } = render(<MailMessageView {...base} message={archived} />);

            expect(container.querySelector('[data-slot="mail-message-archived"]')).not.toBeNull();
            expect(container.querySelector('[data-slot="mail-message-actions"]')).toBeNull();
            unmount();
        });

        it('offers no reply — there is no uid to reply from', () => {
            const { container, unmount } = render(<MailMessageView {...base} message={archived} />);

            expect(container.querySelector('[title="Antworten"]')).toBeNull();
            unmount();
        });

        it('still shows the message itself', () => {
            const { container, unmount } = render(<MailMessageView {...base} message={archived} />);

            expect(textOf(container)).toContain('Betreff');
            unmount();
        });
    });

    it('offers "continue editing" only for a draft', () => {
        const plain = render(<MailMessageView {...base} message={message()} />);
        expect(plain.container.querySelector('[title="Weiter bearbeiten"]')).toBeNull();
        plain.unmount();

        const draft = render(<MailMessageView {...base} message={message()} isDraft onEditDraft={() => {}} />);
        expect(draft.container.querySelector('[title="Weiter bearbeiten"]')).not.toBeNull();
        draft.unmount();
    });

    it('says how many messages archiving moves', () => {
        const one = render(<MailMessageView {...base} message={message()} archiveCount={1} />);
        expect(one.container.querySelector('[title="Archivieren"]')).not.toBeNull();
        one.unmount();

        const many = render(<MailMessageView {...base} message={message()} archiveCount={4} />);
        expect(many.container.querySelector('[title="Ganze Konversation archivieren (4 Nachrichten)"]')).not.toBeNull();
        expect(textOf(many.container)).toContain('4');
        many.unmount();
    });

    it('names the state the read button will move to', () => {
        const read = render(<MailMessageView {...base} message={message({ is_read: true })} />);
        expect(read.container.querySelector('[title="Als ungelesen markieren"]')).not.toBeNull();
        read.unmount();

        const unread = render(<MailMessageView {...base} message={message({ is_read: false })} />);
        expect(unread.container.querySelector('[title="Als gelesen markieren"]')).not.toBeNull();
        unread.unmount();
    });

    it('replies, and says whether it means everyone', () => {
        const onReply = vi.fn();
        const two = message({ cc: [{ email: 'c@x.de', name: '' }] });
        const { container, unmount } = render(<MailMessageView {...base} message={two} onReply={onReply} />);

        click(container.querySelector('[title="Antworten"]') as HTMLButtonElement);
        expect(onReply).toHaveBeenLastCalledWith(false);

        click(container.querySelector('[title="Allen antworten"]') as HTMLButtonElement);
        expect(onReply).toHaveBeenLastCalledWith(true);
        unmount();
    });

    it('lists attachments with their size and a download link', () => {
        const withFile = message({ attachments: [{ index: 0, filename: 'Angebot.pdf', mime_type: 'application/pdf', size: 2048 }] });
        const { container, unmount } = render(<MailMessageView {...base} message={withFile} />);

        const link = container.querySelector('[data-slot="mail-message-attachments"] a') as HTMLAnchorElement;
        expect(link.getAttribute('href')).toBe('/anhang/0');
        expect(textOf(container)).toContain('Angebot.pdf');
        expect(textOf(container)).toContain('2048 B');
        unmount();
    });

    it('shows no attachment strip when there are none', () => {
        const { container, unmount } = render(<MailMessageView {...base} message={message()} />);

        expect(container.querySelector('[data-slot="mail-message-attachments"]')).toBeNull();
        unmount();
    });

    it('takes the product’s own badge and buttons', () => {
        const { container, unmount } = render(
            <MailMessageView
                {...base}
                message={message()}
                headerAccessory={<span>Zugewiesen an Bastian</span>}
                extraActions={<button type="button">Aufgabe zuordnen</button>}
            />,
        );

        expect(textOf(container)).toContain('Zugewiesen an Bastian');
        expect(textOf(container)).toContain('Aufgabe zuordnen');
        unmount();
    });

    it('zeigt Antworten und Weiterleiten nur mit Rueckruf', () => {
        // Bis zum 22.09.2026 standen sie immer da — auch in Produkten ohne
        // Sendeweg, wo sie beim Klick nichts taten. Ein Knopf ohne Wirkung
        // sieht aus wie ein Defekt, nicht wie eine fehlende Funktion.
        const { container, rerender, unmount } = render(<MailMessageView {...base} message={message()} />);

        expect(container.querySelector('button[title="Antworten"]')).toBeNull();
        expect(container.querySelector('button[title="Weiterleiten"]')).toBeNull();

        rerender(<MailMessageView {...base} message={message()} onReply={() => {}} onForward={() => {}} />);

        expect(container.querySelector('button[title="Antworten"]')).not.toBeNull();
        expect(container.querySelector('button[title="Weiterleiten"]')).not.toBeNull();
        unmount();
    });
});

describe('die Betreff-Spalte auf schmalen Schirmen', () => {
    /**
     * ## Erste Messung, 30.10.2026 (crm.peppermint-digital.com, iPhone 14)
     *
     *     h2   x=16  y=487  BREITE 0  HOEHE 196
     *
     * Der Betreff brach zeichenweise um, und der Antworten-Knopf sass auf
     * derselben Hoehe darueber. Die linke Spalte trug `min-w-0` ohne `flex-1`
     * — daneben eine Knopfleiste mit `shrink-0`, darum herum
     * `justify-between`.
     *
     * ## Zweite Messung, 30.10.2026 — `flex-1` reichte NICHT
     *
     * Die Session `mail-rollout` hat den ersten Versuch (v0.176.1) am Geraet
     * nachgemessen, mit dem frisch gebauten Buendel und an der Klassenliste
     * des lebenden Elements:
     *
     *     Betreff-Spalte   BREITE   0
     *     Knopfleiste      BREITE 490
     *     Container        BREITE 358
     *
     * `flex-1` stand da. Es konnte nur nichts ausrichten: Die Leiste ist
     * breiter als der Container, es gibt keinen Rest zu verteilen, und
     * `shrink-0` verbietet ihr, schmaler zu werden.
     *
     * Deshalb pruefen die Tests unten jetzt BEIDE Seiten der Zeile. Ein Test
     * nur an der Betreff-Spalte war die ganze Zeit gruen und die Seite die
     * ganze Zeit kaputt — er hat gemessen, was dastand, nicht, was es bewirkt.
     *
     * ## Dritte Messung, 30.10.2026 — v0.184.0 hat ihn VERSCHOBEN (Bug #1197)
     *
     * `@md` oeffnet bei 28rem = 448 px, die Leiste braucht 444 px (CRM,
     * Verwaltung) bzw. 490 px (AI Brain). Zwischen Schwelle und Leistenbreite
     * gibt es nichts zu verteilen — bei 448 px Container stand die Spalte
     * wieder auf 0, bei 664 px (Fenster 1440, Assistent zu) auf 126.
     *
     * ## Was diese Tests NICHT koennen — und wo der Nachweis jetzt liegt
     *
     * jsdom rechnet kein Layout, also keine Breite. Sie halten den
     * Klassenvertrag fest, damit niemand die Klassen versehentlich loescht.
     * Eine Zusicherung auf Klassennamen kann aber nicht falsifizieren: Sie war
     * bei jedem der vier Anlaeufe gruen.
     *
     * Der Nachweis steht daher seit v0.185.0 IM PAKET, in echtem Chromium:
     * `resources/js/tests/browser/leseansicht-breiten.test.ts` faehrt 320 bis
     * 1600 px in 40-px-Schritten ab, fuer 8 und fuer 9 Knoepfe. Hier stand bis
     * zum 30.10.2026, gemessen werde in der Browser-Suite des Projekt-Managers
     * — das galt, solange das Paket keine Seite oeffnen konnte. Es kann.
     */
    it('behaelt ihre Breite in der Zeile', () => {
        const { container, unmount } = render(<MailMessageView {...base} message={message()} />);

        const betreff = container.querySelector('h2');
        const spalte = betreff?.parentElement;

        expect(spalte?.className).toContain('min-w-0');
        expect(spalte?.className, 'ohne flex-1 waechst die Spalte nicht in den freien Platz').toContain('flex-1');

        /*
         * Die Mindestbreite ist der Teil, den `flex-1` NICHT leisten kann:
         * `flex-1` ist `flex-basis: 0%`, und `flex-grow` verteilt nur
         * VERBLEIBENDEN Platz. Reicht die Zeile nicht, bleibt die Spalte bei 0
         * — mit `flex-1` genauso wie ohne. Erst eine Basis macht aus „passt
         * nicht" einen Umbruch der Zeile (siehe `@md:flex-wrap` unten).
         *
         * 20rem = 320 px, bemessen an der Lesbarkeit und NICHT an der
         * Knopfzahl: Ein Produkt darf ueber `zusatzAktionen` Knoepfe
         * dazustellen (AI Brain tut es), eine an der Leiste bemessene Zahl
         * waere wieder geraten.
         */
        expect(spalte?.className, 'ohne Mindestbreite faellt die Spalte neben der Leiste auf 0').toContain('@md:basis-80');
        unmount();
    });

    it('steht in einer schmalen Spalte UEBER der Knopfleiste, nicht neben ihr', () => {
        const { container, unmount } = render(<MailMessageView {...base} message={message()} />);

        const zeile = container.querySelector('h2')?.parentElement?.parentElement;

        /*
         * Das ist der Teil, den `flex-1` nicht leisten konnte: Solange beide
         * in EINER Zeile stehen und die Leiste 490 px braucht, bleibt fuer den
         * Betreff nichts. Erst `flex-col` unter `sm` gibt ihm eine eigene.
         */
        expect(zeile?.className, 'ohne flex-col stehen Betreff und Leiste in einer Zeile').toContain('flex-col');
        expect(zeile?.className, 'in einer breiten Spalte soll es nebeneinander bleiben').toContain('@md:flex-row');

        /*
         * Und nebeneinander nur, SOLANGE ES PASST. Das ist die Antwort auf
         * Bug #1197: `@md:flex-row` allein erzwingt eine Zeile, in die beide
         * nicht hineinpassen; mit `@md:flex-wrap` rutscht die Leiste als
         * Ganzes in die zweite Zeile und der Betreff bekommt die volle Breite.
         * Ohne Schwelle, und damit unabhaengig von der Knopfzahl.
         */
        expect(zeile?.className, 'ohne flex-wrap wird eine Zeile erzwungen, in die beide nicht passen').toContain('@md:flex-wrap');
        unmount();
    });

    it('laesst die Knopfleiste umbrechen, statt sie aus dem Bild laufen zu lassen', () => {
        const { container, unmount } = render(<MailMessageView {...base} message={message()} />);

        const leiste = container.querySelector('[data-slot="mail-message-actions"]');

        // Gemessen 490 px bei 358 px Container. Eine eigene Zeile allein
        // reicht nicht — in ihr muessen die Knoepfe auch umbrechen duerfen.
        expect(leiste?.className, 'ohne flex-wrap laeuft die 490-px-Leiste rechts aus dem Bild').toContain('flex-wrap');

        /*
         * Und zwar BEI JEDER BREITE. Hier standen bis v0.184.0 `@md:shrink-0`
         * und `@md:flex-nowrap` — beide haben ab 448 px genau das wieder
         * verboten, was eine Zeile darueber erlaubt wurde:
         *
         * - `shrink-0` laesst der Leiste ihre volle Inhaltsbreite (490 px) und
         *   draengt die Betreff-Spalte heraus.
         * - `flex-nowrap` setzt ihre Mindestbreite auf die Summe aller
         *   Knoepfe, womit sie auch in einer EIGENEN Zeile nicht umbricht.
         *
         * Das ist eine Zusicherung auf etwas, das NICHT dasteht — unueblich,
         * aber hier richtig: Beide Klassen sind schon einmal zurueckgekommen
         * (v0.184.0), und beide sehen fuer sich genommen vernuenftig aus.
         */
        expect(leiste?.className, 'shrink-0 draengt die Betreff-Spalte wieder auf 0 (Bug #1197)').not.toContain('shrink-0');
        expect(leiste?.className, 'flex-nowrap verbietet der Leiste den Umbruch in ihrer eigenen Zeile').not.toContain('flex-nowrap');
        unmount();
    });

    it('setzt Von und Datum in einer schmalen Spalte untereinander', () => {
        const { container, unmount } = render(<MailMessageView {...base} message={message()} />);

        // Zwei Spalten auf 358 px sind je rund 175 px; „Von:
        // department@peppermi" und „Datum:" lagen uebereinander (gemessen
        // 30.10.2026).
        const raster = container.querySelector('.grid');

        expect(raster?.className).toContain('grid-cols-1');
        expect(raster?.className).toContain('@md:grid-cols-2');

        /*
         * Und die ueberbreiten Zeilen spannen erst AB `sm` ueber zwei Spalten.
         *
         * `col-span-2` ohne Breakpoint macht `grid-cols-1` wirkungslos: Ein
         * Kind, das zwei Spalten verlangt, zwingt das Raster, eine zweite Spur
         * ANZULEGEN — die Vorlage nennt dann eine, das Raster hat zwei, und die
         * beiden kurzen Felder rutschen nebeneinander hinein.
         *
         * Im echten Browser auf 390 px gemessen (30.10.2026):
         * `gridTemplateColumns` war „166px 184px" — zwei UNGLEICHE Spuren, also
         * kein `grid-cols-2`, sondern eine implizite Spur. Ein Probe-Element
         * mit `grid grid-cols-1` ergab im selben Lauf „358px": Die Klasse
         * wirkte, das Kind hat sie ueberstimmt.
         *
         * Dieser Test kann das nicht messen (jsdom rechnet kein Layout) — er
         * haelt fest, dass der Breakpoint dasteht. Gemessen wird es in der
         * Browser-Suite des Projekt-Managers,
         * `tests/Browser/MailLeseansichtAufDemTelefonTest.php`.
         */
        const breit = container.querySelector('.grid > *:nth-child(3)');

        expect(breit?.className, 'ohne Breakpoint legt col-span-2 eine zweite Spur an').toContain('@md:col-span-2');
        expect(breit?.className).not.toMatch(/(^|\s)col-span-2(\s|$)/);
        unmount();
    });
});
