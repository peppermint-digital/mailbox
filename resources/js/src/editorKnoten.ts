import { mergeAttributes, Node } from '@tiptap/core';
import { DEFAULT_QUOTE_STYLE } from './quote';

/**
 * Zwei Knoten, die den Editor etwas NICHT vergessen lassen.
 *
 * ## Der Fehler, den es ohne sie gibt
 *
 * Signatur und Zitat waren als `<div>` im HTML markiert — `data-signature`
 * beim einen, ein Rahmenstil beim anderen. StarterKit kennt keinen
 * `div`-Knoten, und ProseMirror wirft darum beim Einlesen die Huelle weg und
 * behaelt nur den Inhalt. Gemessen am 31.10.2026 mit dem echten Editor und
 * seinem echten Erweiterungssatz:
 *
 *     rein:  <p>Hallo</p><div data-signature="1"><p>Mit freundlichen Grüßen</p>…</div>
 *     raus:  <p>Hallo</p><p>Mit freundlichen Grüßen</p><p></p><p>Chris</p>
 *
 *     rein:  <div style="border-left: 3px solid #ccc; …"><p>Am … schrieb …:</p>…</div>
 *     raus:  <p>Am … schrieb …:</p><p>Bitte bestaetigen</p>
 *
 * Nicht beschaedigt, sondern aufgeloest. Das kostete drei Dinge auf einmal:
 *
 * 1. **Der Schreibassistent schrieb die Signatur um.** Der Befund von
 *    `mail-2b` (P18): „Grüßen" wurde zu „Grueßen", Leerzeilen fielen weg.
 *    `schreibhilfeTabu` konnte dagegen nichts tun — es nennt KNOTENNAMEN,
 *    und es gab keinen zu nennen.
 * 2. **Signatur wechseln legte eine zweite an.** `swapSignature()` sucht
 *    `[data-signature]`; findet es nichts, haengt es an statt zu ersetzen.
 *    Ein Entwurf, der einmal durch den Editor lief, hatte den Marker nicht
 *    mehr. `stripSignature()` entfernte entsprechend nichts. Genau diesen
 *    Fehler nennt `signature.test.ts` als Grund, warum die Chirurgie aus dem
 *    Manager geholt wurde — der Editor machte die Vorkehrung still zunichte.
 * 3. **Das Zitat verlor seinen Rahmen.** Der `border-left` steckt im
 *    `style` des `div`, und mit dem `div` ging er. In der gesendeten Mail
 *    war das Zitierte dann nicht mehr von den eigenen Worten zu
 *    unterscheiden.
 *
 * ## Warum zwei Knoten und nicht einer
 *
 * Sie sind beide tabu, aber sie sind nicht dasselbe: Die Signatur gehoert dem
 * Absender und das Zitat dem anderen. Ein gemeinsamer Knoten „nicht anfassen"
 * waere kuerzer und wuerde beim ersten Sonderfall auseinanderfallen — etwa
 * wenn die Signatur beim Senden ersetzt werden soll und das Zitat nie.
 *
 * ## Warum die Reihenfolge nicht zaehlt
 *
 * `useSchreibhilfe` schneidet den Rumpf am ERSTEN Tabu-Knoten ab. Steht das
 * Zitat vor der Signatur, schuetzt die Grenze beide; steht es dahinter,
 * ebenso. Das ist der Grund, warum hier zwei Knoten stehen und nicht die
 * Annahme „das Zitat liegt ohnehin hinter der Signatur".
 */

/** Der Knotenname der Signatur. Als Konstante, damit ein Produkt mit EIGENEM Editor keine zweite Schreibweise erfindet. */
export const SIGNATUR_KNOTEN = 'signatur';

/** Der Knotenname des Zitats. Dieselbe Begruendung. */
export const ZITAT_KNOTEN = 'zitat';

/**
 * Was der Schreibassistent von sich aus nicht anfasst.
 *
 * Die VORGABE, nicht ein Angebot: `useSchreibhilfe` nimmt diese Namen immer,
 * und `schreibhilfeTabu` aus dem Produkt kommt dazu. Ein Produkt kann also
 * ergaenzen und nichts wegnehmen — Bastians Ansage dazu war eindeutig: „Im
 * alten Manager-Editor war die Signatur geschuetzt, so soll es in allen vier
 * sein", und ein Schutz, den jedes Produkt erst einschalten muss, ist in drei
 * von vier Produkten vergessen.
 */
export const GESCHUETZTE_KNOTEN: readonly string[] = [SIGNATUR_KNOTEN, ZITAT_KNOTEN];

/**
 * Die Signatur als eigener Knoten.
 *
 * `isolating`, damit die Loeschtaste am Anfang nicht den Absatz davor
 * hineinzieht: Dann waere der Rumpf plötzlich Teil der Signatur, und von da an
 * schuetzte die Grenze zu viel statt zu wenig.
 *
 * `defining`, damit eingefuegter Inhalt die Huelle nicht ersetzt. Ohne das
 * macht ein Einfuegen aus der Signatur wieder gewoehnliche Absaetze — also
 * genau den Zustand, den dieser Knoten behebt.
 *
 * KEIN `atom`: Die Signatur bleibt lesbar und aenderbar. Wer seine Telefonnummer
 * im Entwurf berichtigen will, soll das koennen; geschuetzt ist sie gegen den
 * Assistenten, nicht gegen ihren Besitzer.
 */
export const SignaturKnoten = Node.create({
    name: SIGNATUR_KNOTEN,
    group: 'block',
    content: 'block+',
    defining: true,
    isolating: true,

    parseHTML() {
        return [{ tag: 'div[data-signature]' }];
    },

    renderHTML({ HTMLAttributes }) {
        return ['div', mergeAttributes(HTMLAttributes, { 'data-signature': '1' }), 0];
    },
});

/**
 * Das Zitat als eigener Knoten.
 *
 * Mit `style` als Attribut, und das ist kein Schoenheitsfehler: Der Rahmen
 * eines Zitats MUSS als Inline-Stil in der gesendeten Mail stehen, weil eine
 * Klasse unterwegs entfernt wird. Was hereinkommt, geht wieder hinaus; ohne
 * Angabe steht die Vorgabe aus {@see DEFAULT_QUOTE_STYLE} dort.
 */
export const ZitatKnoten = Node.create({
    name: ZITAT_KNOTEN,
    group: 'block',
    content: 'block+',
    defining: true,
    isolating: true,

    addAttributes() {
        return {
            style: {
                default: DEFAULT_QUOTE_STYLE,
                parseHTML: (element) => element.getAttribute('style') || DEFAULT_QUOTE_STYLE,
                renderHTML: (attributes) => ({ style: attributes.style as string }),
            },
        };
    },

    parseHTML() {
        return [{ tag: 'div[data-quote]' }];
    },

    renderHTML({ HTMLAttributes }) {
        return ['div', mergeAttributes(HTMLAttributes, { 'data-quote': '1' }), 0];
    },
});
