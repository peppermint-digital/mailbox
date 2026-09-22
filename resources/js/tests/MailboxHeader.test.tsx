import { describe, expect, it, vi } from 'vitest';
import { MailboxHeader, type MailboxHeaderLabels } from '../src/MailboxHeader';
import { click, render, textOf } from './support/render';

/**
 * Die Kopfzeile des Postfachs (22.09.2026).
 *
 * Der Punkt dieser Tests ist die Regel, nach der sie gebaut ist: Was alle
 * Produkte teilen, steht fest an seinem Platz; was ein Produkt zusaetzlich
 * hat, steht in einem eigenen, erkennbaren Bereich daneben. Und ein Knopf, den
 * das Produkt nicht bedient, erscheint gar nicht — ein Knopf ohne Wirkung
 * sieht aus wie ein Fehler, nicht wie eine fehlende Funktion.
 */
const texte: MailboxHeaderLabels = {
    addressBook: 'Adressbuch',
    compose: 'Neue E-Mail',
    refresh: 'Aktualisieren',
    refreshing: 'Wird aktualisiert …',
    accountPicker: 'Postfach auswählen',
    noAccounts: 'Kein Postfach zugeteilt',
};

const konten = [
    { id: 1, name: 'info@example.test' },
    { id: 2, name: 'buero@example.test' },
];

const grund = { title: 'E-Mails', labels: texte, accounts: konten, accountId: 1, onAccountChange: () => {} };

describe('MailboxHeader', () => {
    it('zeigt Titel und Postfach-Auswahl', () => {
        const { container, unmount } = render(<MailboxHeader {...grund} />);

        expect(textOf(container)).toContain('E-Mails');
        expect(container.querySelector('[data-slot="mailbox-header"]')).not.toBeNull();
        unmount();
    });

    it('laesst jeden Knopf weg, den das Produkt nicht bedient', () => {
        // Das ist der Kern: Ein CRM ohne Verfassen-Route soll keinen
        // Verfassen-Knopf zeigen, der nichts tut.
        const { container, unmount } = render(<MailboxHeader {...grund} />);

        expect(container.querySelector('button[aria-label="Adressbuch"]')).toBeNull();
        expect(container.querySelector('button[aria-label="Aktualisieren"]')).toBeNull();
        expect(textOf(container)).not.toContain('Neue E-Mail');
        unmount();
    });

    it('zeigt die gemeinsamen Knoepfe, sobald es sie gibt', () => {
        const { container, unmount } = render(
            <MailboxHeader {...grund} onAddressBook={() => {}} onCompose={() => {}} onRefresh={() => {}} />,
        );

        expect(container.querySelector('button[aria-label="Adressbuch"]')).not.toBeNull();
        expect(container.querySelector('button[aria-label="Aktualisieren"]')).not.toBeNull();
        expect(textOf(container)).toContain('Neue E-Mail');
        unmount();
    });

    it('haelt die produkteigenen Werkzeuge in einem eigenen Bereich', () => {
        // Nicht bloss „irgendwo mitgerendert": Wer zwei Produkte nebeneinander
        // legt, soll sehen koennen, welcher Knopf geteilt ist und welcher nicht.
        const { container, unmount } = render(<MailboxHeader {...grund} extras={<button>NUR HIER</button>} />);

        const bereich = container.querySelector('[data-slot="mailbox-header-extras"]');

        expect(bereich).not.toBeNull();
        expect(bereich?.textContent).toContain('NUR HIER');
        unmount();
    });

    it('ohne Zusatzwerkzeuge bleibt der Bereich weg', () => {
        // Sonst stuende ein Trennstrich da, hinter dem nichts kommt.
        const { container, unmount } = render(<MailboxHeader {...grund} />);

        expect(container.querySelector('[data-slot="mailbox-header-extras"]')).toBeNull();
        unmount();
    });

    it('meldet das Aktualisieren und sperrt sich, solange es laeuft', () => {
        const aktualisiere = vi.fn();
        const { container, rerender, unmount } = render(<MailboxHeader {...grund} onRefresh={aktualisiere} />);

        click(container.querySelector('button[aria-label="Aktualisieren"]')!);
        expect(aktualisiere).toHaveBeenCalled();

        rerender(<MailboxHeader {...grund} onRefresh={aktualisiere} refreshing />);
        const knopf = container.querySelector<HTMLButtonElement>('button[aria-label="Aktualisieren"]')!;
        expect(knopf.disabled).toBe(true);
        expect(knopf.title).toBe('Wird aktualisiert …');
        unmount();
    });

    it('sagt bei einem leeren Produkt, dass kein Postfach zugeteilt ist', () => {
        // „Postfach auswaehlen" ueber einer leeren Liste schickt jemanden auf
        // die Suche nach einem Eintrag, den es nicht gibt.
        const { container, unmount } = render(<MailboxHeader {...grund} accounts={[]} accountId={null} />);

        expect(textOf(container)).toContain('Kein Postfach zugeteilt');
        unmount();
    });

    /*
     * `accountAccessory` hat hier BEWUSST keinen Test.
     *
     * Radix baut die Zeilen der Auswahlliste erst, wenn sie geoeffnet ist, und
     * das Oeffnen braucht Zeiger-APIs, die jsdom nicht hat. Ein Test waere
     * gruen, ohne je an der Kennzeichnung vorbeizukommen — ein Waechter, der
     * nie zuschnappt, und damit schlimmer als keiner: Er sagt, die Sache sei
     * geprueft.
     *
     * Geprueft wird sie am ausgerollten Stand im Browser.
     */

    it('zeigt eine Stoerung neben dem Titel', () => {
        const { container, unmount } = render(<MailboxHeader {...grund} error="Das Postfach ist gerade nicht erreichbar." />);

        expect(container.querySelector('[data-slot="mailbox-header-error"]')?.textContent).toContain('nicht erreichbar');
        unmount();
    });
});
