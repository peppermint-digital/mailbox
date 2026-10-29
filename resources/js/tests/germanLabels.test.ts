import { describe, expect, it } from 'vitest';
import { germanLabels } from '../src/germanLabels';

/**
 * Die mitgelieferten Woerter (19.10.2026).
 *
 * Die VOLLSTAENDIGKEIT erzwingt der Typ: Jedes Objekt ist auf seine
 * Schnittstelle typisiert, ein neues Pflichtfeld bricht beim Bauen. Was der
 * Typ nicht sieht, ist ein Wort, das es GIBT und das leer ist — und genau das
 * prueft dieser Test.
 *
 * Ein leeres Label faellt in der Oberflaeche nicht als Fehler auf. Es sieht
 * aus wie ein Knopf ohne Beschriftung, ein Titel ohne Text, ein Hinweis, der
 * fehlt. Niemand meldet es, weil es wie Absicht aussieht.
 */
const gruppen = Object.entries(germanLabels);

/**
 * Jedes Wort einer Gruppe pruefen — auch die in einer Untergruppe.
 *
 * `view.body` ist so eine: Die Leseansicht bringt die Woerter ihres
 * Nachrichtenrumpfs verschachtelt mit. Ohne Rekursion waere diese Gruppe
 * ungeprueft geblieben, und der Test haette trotzdem gruen gemeldet — das
 * waere schlechter als kein Test.
 */
function pruefeWoerter(gruppe: Record<string, unknown>, pfad = ''): void {
    for (const [schluessel, wert] of Object.entries(gruppe)) {
        const name = pfad === '' ? schluessel : `${pfad}.${schluessel}`;

        if (typeof wert === 'string') {
            expect(wert.trim(), name).not.toBe('');

            continue;
        }

        if (typeof wert === 'object' && wert !== null) {
            pruefeWoerter(wert as Record<string, unknown>, name);

            continue;
        }

        expect(typeof wert, name).toBe('function');

        /*
         * Eine Funktion wird mit Platzhaltern gerufen. Die Werte sind
         * absichtlich stumpf: Geprueft wird, dass ueberhaupt ein Satz
         * herauskommt — nicht, welcher. Was dort steht, ist eine Entscheidung
         * des Produkts und keine Zusage des Pakets.
         */
        let satz: unknown;

        try {
            satz = (wert as (...a: unknown[]) => unknown)(1, 2, 3);
        } catch {
            /*
             * Manche Saetze nehmen eine LISTE von Namen, nicht eine Zahl —
             * `werkzeuge.unerreichbar(['Verwaltung', 'CRM'])` zum Beispiel.
             * Die ruft `.join()` und faellt ueber eine 1.
             *
             * Also zweiter Versuch mit Listen statt Zahlen. Nicht geraten,
             * sondern aufgefallen: Der Abschnitt `werkzeuge` kam am
             * 20.10.2026 dazu, und dieser Waechter fiel sofort — was genau
             * seine Aufgabe ist.
             */
            satz = (wert as (...a: unknown[]) => unknown)(['a', 'b'], ['c'], ['d']);
        }

        expect(typeof satz, name).toBe('string');
        expect(String(satz).trim(), name).not.toBe('');
    }
}

describe('germanLabels', () => {
    it('bringt alle fünfzehn Gruppen mit', () => {
        // Faellt eine weg, verliert ein Produkt lautlos seine Woerter und
        // zeigt `undefined` — oder gar nichts.
        //
        // Die Liste ist ausdruecklich und nicht gezaehlt: Ein `length === 7`
        // waere auch gruen, wenn jemand eine Gruppe umbenennt.
        expect(gruppen.map(([name]) => name).sort()).toEqual([
            // Seit dem 22.10.2026 (#8377): Antworten, Weiterleiten und
            // Verfassen samt Zitat, Rumpf und Editor-Woertern. Dieselben
            // Saetze standen in drei Produkten, wortgleich.
            'antwort',
            'conversation',
            'editor',
            'folders',
            'header',
            'list',
            // Seit #1079: die Umschaltung des Rahmens, die es nur auf schmalen
            // Bildschirmen gibt. Als EINZIGE Gruppe auch die Vorgabe des
            // Bauteils selbst — der mobile Rahmen sollte in allen vier
            // Produkten wirken, ohne dass eines eine Zeile aendert.
            'rahmen',
            'rumpf',
            'toolbar',
            'verfassen',
            'view',
            'weiterleiten',
            'werkzeuge',
            'zitat',
            // Seit dem 22.10.2026 (#8377): Die Woerter der Zustaendigkeit
            // standen als Literal in jedem Produkt, wortgleich.
            'zuweisung',
        ]);
    });

    it.each(gruppen)('lässt in %s kein Wort leer', (_name, gruppe) => {
        pruefeWoerter(gruppe as Record<string, unknown>);
    });

    it('lässt sich überschreiben, ohne die Vorgabe zu verändern', () => {
        // So behaelt der Manager seine fuenf genaueren Saetze. Wuerde das
        // Spreizen die Vorgabe anfassen, traefe seine Abweichung alle.
        const eigene = { ...germanLabels.list, empty: 'Keine Tickets' };

        expect(eigene.empty).toBe('Keine Tickets');
        expect(germanLabels.list.empty).not.toBe('Keine Tickets');
    });
});
