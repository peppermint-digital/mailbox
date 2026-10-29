import { describe, expect, it } from 'vitest';
import { MailboxHeader, type MailboxHeaderLabels } from '../src/MailboxHeader';
import { render } from './support/render';

const labels: MailboxHeaderLabels = {
    addressBook: 'Adressbuch',
    compose: 'Neue E-Mail',
    refresh: 'Aktualisieren',
    refreshing: 'Wird aktualisiert',
    accountPicker: 'Postfach wählen',
    noAccounts: 'Kein Postfach',
};

const konten = [{ id: 1, name: 'department@peppermint-digital.de' }];

/**
 * Gemessen am 29.10.2026 auf vw. und crm.peppermint-digital.com bei 390 px:
 * Der Kontowähler lag 135 px unter dem Knopf „Neue E-Mail" — die Adresse war
 * weder lesbar noch ganz anklickbar.
 *
 * Ursache war eine feste `w-[250px]` ohne Schrumpferlaubnis neben einer
 * `shrink-0`-Gruppe: 24+24 px Innenabstand lassen bei 390 px nur 342 px, und
 * was nicht schrumpfen darf, schiebt sich unter den Nachbarn.
 *
 * Was dieser Test NICHT kann: jsdom rechnet kein Layout, er misst also keine
 * Überlappung. Er hält nur fest, dass die Schrumpferlaubnis und der Umbruch
 * nicht wieder verschwinden. Der Nachweis, dass es wirklich passt, ist der
 * Screenshot bei 390 px — nicht diese Datei.
 */
describe('MailboxHeader bei schmaler Breite', () => {
    it('lässt den Kontowähler schrumpfen, statt ihn fest auf 250 px zu nageln', () => {
        const { container, unmount } = render(
            <MailboxHeader title="E-Mails" labels={labels} accounts={konten} accountId={1} onAccountChange={() => {}} onCompose={() => {}} />,
        );

        const waehler = container.querySelector('[aria-label="Postfach wählen"]');
        expect(waehler).not.toBeNull();

        const klassen = waehler!.className;
        expect(klassen).toContain('max-w-full');
        expect(klassen).toContain('min-w-0');

        unmount();
    });

    it('bricht die Kopfzeile unterhalb von md um, damit die Knöpfe eine eigene Reihe bekommen', () => {
        const { container, unmount } = render(
            <MailboxHeader title="E-Mails" labels={labels} accounts={konten} accountId={1} onAccountChange={() => {}} onCompose={() => {}} />,
        );

        const kopf = container.querySelector('[data-slot="mailbox-header"]');
        expect(kopf).not.toBeNull();
        expect(kopf!.className).toContain('max-md:flex-wrap');

        // Die linke Gruppe muss die Reihe fuellen, sonst steht der Waehler nach
        // dem Umbruch in einer 95-px-Spalte statt ueber die volle Breite.
        const links = kopf!.querySelector('div');
        expect(links!.className).toContain('max-md:w-full');

        unmount();
    });
});
