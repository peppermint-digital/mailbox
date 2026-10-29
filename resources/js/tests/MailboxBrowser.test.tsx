import { describe, expect, it, vi } from 'vitest';
import MailboxBrowser from '../src/MailboxBrowser';
import MailFolderList from '../src/MailFolderList';
import MailMessageList, { type MailMessageListLabels } from '../src/MailMessageList';
import type { DisplayRow, RowMessage } from '../src/rows';
import { click, render, textOf } from './support/render';

const panes = {
    folders: <span>ORDNER</span>,
    list: <span>LISTE</span>,
    view: <span>ANSICHT</span>,
};

describe('MailboxBrowser', () => {
    it('places all three columns', () => {
        const { container, unmount } = render(<MailboxBrowser {...panes} />);

        expect(container.querySelector('[data-slot="mailbox-folders"]')).not.toBeNull();
        expect(container.querySelector('[data-slot="mailbox-list"]')).not.toBeNull();
        expect(container.querySelector('[data-slot="mailbox-view"]')).not.toBeNull();
        unmount();
    });

    it('drops the folder column entirely when it is hidden', () => {
        // Not merely narrow: a collapsed column that still renders keeps its
        // border and its scroll container, and both show.
        const { container, unmount } = render(<MailboxBrowser {...panes} showFolders={false} />);

        expect(container.querySelector('[data-slot="mailbox-folders"]')).toBeNull();
        expect(textOf(container)).not.toContain('ORDNER');
        expect(textOf(container)).toContain('LISTE');
        unmount();
    });

    it('takes a toolbar above the columns', () => {
        const { container, unmount } = render(<MailboxBrowser {...panes} toolbar={<span>LEISTE</span>} />);

        expect(textOf(container)).toContain('LEISTE');
        unmount();
    });

    it('takes a column of the product beside the reading pane', () => {
        const { container, unmount } = render(<MailboxBrowser {...panes} aside={<span>ASSISTENT</span>} />);

        expect(textOf(container)).toContain('ASSISTENT');
        unmount();
    });

    it('leaves the aside out when there is none', () => {
        const { container, unmount } = render(<MailboxBrowser {...panes} />);

        // Three columns and nothing else — an empty fourth would still take
        // its border and its share of the row.
        const row = container.querySelector('[data-slot="mailbox-folders"]')?.parentElement;
        expect(row?.children).toHaveLength(3);
        unmount();
    });

    it('lets the product widen the list', () => {
        const { container, unmount } = render(<MailboxBrowser {...panes} listWidth="w-96" />);

        expect(container.querySelector('[data-slot="mailbox-list"]')?.className).toContain('w-96');
        unmount();
    });

    it('laesst die Ordner scrollen, die Listen-Spalte aber nur abschneiden', () => {
        // Die Ordner-Spalte enthaelt nur Ordner — sie darf als Ganzes scrollen.
        //
        // Die Listen-Spalte enthaelt bei einem gewachsenen Produkt mehr als die
        // Liste: Werkzeugleiste, Filter, Sammelaktionen, Blaetterung. Wuerde sie
        // als Ganzes scrollen, wanderten die alle mit nach oben weg — man
        // verloere beim Blaettern genau die Knoepfe, die man dafuer braucht.
        // Gemeldet am 21.09.2026, nachdem hier zuerst die ganze Spalte scrollte.
        const { container, unmount } = render(<MailboxBrowser {...panes} />);

        expect(container.querySelector('[data-slot="mailbox-folders"]')?.className).toContain('overflow-y-auto');

        const liste = container.querySelector('[data-slot="mailbox-list"]')?.className ?? '';
        expect(liste).toContain('overflow-hidden');
        expect(liste).not.toContain('overflow-y-auto');

        unmount();
    });

});

/*
 * Ab hier: der Rahmen auf einem Telefon (#1079 kritisch, #1071).
 *
 * Gemeldet von Chris Tolksdorf zum iPhone. Die Ursache ist eine Rechnung:
 * Ordner 192px + Liste 320px = 512px, bevor der Lesebereich anfaengt — auf
 * 390px liegt er vollstaendig ausserhalb des Bildschirms.
 *
 * Die Pruefung geht ueber die Klassen und nicht ueber gemessene Breiten: jsdom
 * rechnet kein Layout und kennt keine Media Queries. Was eine Klasse bei 390px
 * tut, ist Tailwind und braucht hier nicht nachgewiesen zu werden — dass der
 * Rahmen die RICHTIGE Klasse setzt, schon.
 */

/** Genau diese Klasse, nicht „enthaelt": `flex` steckt auch in `flex-col`. */
function hatKlasse(container: HTMLElement, slot: string, klasse: string): boolean {
    const element = container.querySelector(`[data-slot="${slot}"]`);

    return (element?.className ?? '').split(/\s+/).includes(klasse);
}

const listenTexte: MailMessageListLabels = {
    unread: 'Ungelesen',
    outboundSender: 'Ihre Antwort',
    outboundBadge: 'gesendet',
    outboundBadgeTitle: 'Aus dem Postfach gesendet',
    expandThread: 'Konversation aufklappen',
    collapseThread: 'Konversation zuklappen',
    newest: 'Neueste',
    newestTitle: 'Die jüngste Nachricht dieser Konversation',
    unreadCount: (n) => `${n} neu`,
    unreadCountTitle: (n, total) => `${n} von ${total} ungelesen`,
    archived: 'Archiv',
    archivedTitle: 'Aus dem Archiv der Anwendung',
    foundInFolder: (f) => `Gefunden in ${f}`,
    flagged: 'Markiert',
    hasAttachments: 'Anhänge',
    selectMessage: (betreff) => `„${betreff}" auswählen`,
};

const zeile: DisplayRow<RowMessage> = {
    msg: {
        uid: 1,
        subject: 'Betreff',
        from_address: 'a@b.de',
        from_name: 'Absender',
        date: '2026-10-22T10:00:00Z',
        has_attachments: false,
        attachment_count: 0,
        is_read: true,
        is_flagged: false,
        preview: 'Vorschau',
    },
    key: '',
    count: 1,
    isMember: false,
};

describe('MailboxBrowser auf einem schmalen Bildschirm', () => {
    it('zeigt zuerst nur die Liste — und ab `md` wieder alle drei', () => {
        // Wortwoertlich die Bitte des Melders: „zunaechst ausschliesslich die
        // E-Mail-Uebersicht anzeigen", die Vorschau NICHT angeschnitten daneben.
        const { container, unmount } = render(<MailboxBrowser {...panes} />);

        expect(hatKlasse(container, 'mailbox-list', 'flex')).toBe(true);
        expect(hatKlasse(container, 'mailbox-folders', 'hidden')).toBe(true);
        expect(hatKlasse(container, 'mailbox-view', 'hidden')).toBe(true);

        // Und am Schreibtisch aendert sich nichts: Jede Spalte bringt ihre
        // `md:`-Klasse mit, die sie dort wieder hinstellt.
        expect(hatKlasse(container, 'mailbox-folders', 'md:block')).toBe(true);
        expect(hatKlasse(container, 'mailbox-list', 'md:flex')).toBe(true);
        expect(hatKlasse(container, 'mailbox-view', 'md:flex')).toBe(true);

        unmount();
    });

    it('nimmt der Liste die feste Breite, solange sie allein steht', () => {
        // Die feste Breite ist die eine Haelfte der Ursache: 320px auf einem
        // 390px-Schirm lassen 70px leer und sehen aus wie ein Fehler.
        const { container, unmount } = render(<MailboxBrowser {...panes} />);

        expect(hatKlasse(container, 'mailbox-list', 'max-md:w-full')).toBe(true);
        expect(hatKlasse(container, 'mailbox-list', 'w-80')).toBe(true);

        unmount();
    });

    it('holt die Ordner und fuehrt von dort zurueck zur Liste', () => {
        const { container, unmount } = render(<MailboxBrowser {...panes} />);

        click(container.querySelector('[data-slot="mailbox-pane-folders"]')!);

        expect(hatKlasse(container, 'mailbox-folders', 'block')).toBe(true);
        expect(hatKlasse(container, 'mailbox-list', 'hidden')).toBe(true);

        click(container.querySelector('[data-slot="mailbox-pane-back"]')!);

        expect(hatKlasse(container, 'mailbox-list', 'flex')).toBe(true);
        expect(hatKlasse(container, 'mailbox-folders', 'hidden')).toBe(true);

        unmount();
    });

    it('bietet ohne Ordner-Spalte auch keinen Ordner-Knopf', () => {
        // Sonst steht auf der Liste ein Streifen mit einer Trennlinie und einem
        // Knopf, der auf eine Spalte zeigt, die es nicht gibt.
        const { container, unmount } = render(<MailboxBrowser {...panes} showFolders={false} />);

        expect(container.querySelector('[data-slot="mailbox-pane-nav"]')).toBeNull();

        unmount();
    });

    it('holt den Lesebereich, wenn eine Zeile angetippt wird', () => {
        // Das ist der Kern: Ohne diesen Schritt bliebe die Liste stehen und die
        // geoeffnete Mail waere unsichtbar — schlimmer als vorher.
        //
        // Und es passiert OHNE Zutun des Produkts: Die Liste liegt selbst im
        // Paket und schaltet den Rahmen. Sonst waere der Fix in jedem der vier
        // Produkte eine Zeile, und so lange kaputt, wie eines sie nicht hat.
        const geoeffnet = vi.fn();

        const { container, unmount } = render(
            <MailboxBrowser
                {...panes}
                list={
                    <MailMessageList
                        rows={[zeile]}
                        labels={listenTexte}
                        formatDate={(iso) => iso ?? '-'}
                        formatSender={(m) => m.from_name}
                        onOpen={geoeffnet}
                    />
                }
            />,
        );

        click(container.querySelector('[data-slot="mail-message-open"]')!);

        expect(hatKlasse(container, 'mailbox-view', 'flex')).toBe(true);
        expect(hatKlasse(container, 'mailbox-list', 'hidden')).toBe(true);

        // Und das Produkt erfaehrt davon unveraendert.
        expect(geoeffnet).toHaveBeenCalledTimes(1);

        unmount();
    });

    it('holt die Liste, wenn ein Ordner gewaehlt wird', () => {
        const gewaehlt = vi.fn();

        const { container, unmount } = render(
            <MailboxBrowser
                {...panes}
                folders={
                    <MailFolderList
                        folders={[{ name: 'Posteingang', path: 'INBOX' }]}
                        labels={{ heading: 'Ordner', createFolder: 'Neuer Ordner' }}
                        onSelect={gewaehlt}
                    />
                }
            />,
        );

        click(container.querySelector('[data-slot="mailbox-pane-folders"]')!);
        click(container.querySelector('[data-slot="mail-folder"]')!);

        expect(hatKlasse(container, 'mailbox-list', 'flex')).toBe(true);
        expect(hatKlasse(container, 'mailbox-folders', 'hidden')).toBe(true);
        expect(gewaehlt).toHaveBeenCalledWith('INBOX');

        unmount();
    });

    it('legt die Seitenspalte darueber statt als vierte Spalte daneben', () => {
        // Vier Spalten auf 390px sind der Fehler noch einmal. Oberhalb von `md`
        // ist die Huelle per `contents` nicht da — dort bleibt die Seitenspalte
        // selbst das Flex-Kind, genau wie vorher.
        const { container, unmount } = render(<MailboxBrowser {...panes} aside={<span>ASSISTENT</span>} />);

        expect(hatKlasse(container, 'mailbox-aside', 'max-md:absolute')).toBe(true);
        expect(hatKlasse(container, 'mailbox-aside', 'md:contents')).toBe(true);

        unmount();
    });

    it('schaltet nicht um, wenn die Liste ausserhalb eines Rahmens steht', () => {
        // Ein Produkt darf die Liste allein benutzen, etwa in einer
        // Seitenspalte. Ohne Rahmen darueber gibt es nichts umzuschalten — und
        // ein `null`-Kontext wuerde hier werfen.
        const geoeffnet = vi.fn();

        const { container, unmount } = render(
            <MailMessageList
                rows={[zeile]}
                labels={listenTexte}
                formatDate={(iso) => iso ?? '-'}
                formatSender={(m) => m.from_name}
                onOpen={geoeffnet}
            />,
        );

        click(container.querySelector('[data-slot="mail-message-open"]')!);

        expect(geoeffnet).toHaveBeenCalledTimes(1);

        unmount();
    });
});
