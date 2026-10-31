import { describe, expect, it } from 'vitest';
import { Editor } from '@tiptap/core';
import StarterKit from '@tiptap/starter-kit';
import TextAlign from '@tiptap/extension-text-align';
import Link from '@tiptap/extension-link';
import Underline from '@tiptap/extension-underline';
import { TextStyle } from '@tiptap/extension-text-style';
import { Color } from '@tiptap/extension-color';
import Highlight from '@tiptap/extension-highlight';
import { Table, TableCell, TableHeader, TableRow } from '@tiptap/extension-table';
import Image from '@tiptap/extension-image';
import { GESCHUETZTE_KNOTEN, SignaturKnoten, SIGNATUR_KNOTEN, ZitatKnoten, ZITAT_KNOTEN } from '../src/editorKnoten';
import { buildReplyQuote } from '../src/quote';
import { stripSignature, swapSignature, wrapSignature } from '../src/signature';

/**
 * Signatur und Zitat ueberleben den Editor (P18, 31.10.2026).
 *
 * ## Warum hier ein echter Editor laeuft und keine Attrappe
 *
 * Der Fehler SASS im Einlesen von ProseMirror: StarterKit hat keinen
 * `div`-Knoten, also warf es die Huelle weg und behielt den Inhalt. Das sieht
 * man nur, wenn man wirklich einliest. Eine Attrappe, die „der Marker ist
 * doch da" sagt, haette den Fehler drei Wochen laenger getragen — er war ja
 * im HTML richtig, bis der Editor ihn anfasste.
 *
 * Derselbe Erweiterungssatz wie in `RichTextEditor`, denn die Frage lautet
 * nicht „kann ProseMirror das", sondern „tut UNSER Editor es".
 */
function editorMitAllem(inhalt: string): { html: string; editor: Editor } {
    const element = document.createElement('div');
    document.body.appendChild(element);

    const editor = new Editor({
        element,
        extensions: [
            StarterKit.configure({ heading: { levels: [1, 2, 3] } }),
            TextAlign.configure({ types: ['heading', 'paragraph'] }),
            Link.configure({ openOnClick: false }),
            Underline,
            TextStyle,
            Color,
            Highlight.configure({ multicolor: false }),
            Table.configure({ resizable: false }),
            TableRow,
            TableHeader,
            TableCell,
            Image.configure({ inline: false, allowBase64: false }),
            SignaturKnoten,
            ZitatKnoten,
        ],
        content: inhalt,
    });

    return { html: editor.getHTML(), editor };
}

/** Durch den Editor und wieder heraus. */
function durch(inhalt: string): string {
    const { html, editor } = editorMitAllem(inhalt);
    editor.destroy();

    return html;
}

const SIGNATUR = '<p>Mit freundlichen Grüßen</p><p></p><p>Chris Stelljes</p>';

describe('die Signatur ueberlebt den Editor', () => {
    it('behaelt den Marker — vorher loeste ProseMirror den div auf', () => {
        const raus = durch(`<p>Hallo Bastian</p>${wrapSignature(SIGNATUR)}`);

        expect(raus).toContain('data-signature');
        expect(raus).toContain('Mit freundlichen Grüßen');
    });

    it('laesst „Signatur wechseln" ERSETZEN statt anhaengen — der Doppel-Signatur-Fehler', () => {
        /*
         * Der Fehler, den niemand gemeldet hat, und der schwerer wog als P18:
         * `swapSignature()` sucht `[data-signature]`. Ohne Marker haengt es an
         * statt zu ersetzen — ein Entwurf, den jemand getippt hatte, bekam
         * beim Wechseln eine ZWEITE Signatur.
         *
         * Der Test geht absichtlich durch den Editor und erst dann in die
         * Chirurgie: Das ist der Weg, den ein Entwurf im Betrieb nimmt.
         */
        const nachDemTippen = durch(`<p>Hallo Bastian</p>${wrapSignature(SIGNATUR)}`);
        const gewechselt = swapSignature(nachDemTippen, '<p>Viele Grüße</p><p>Chris</p>');

        expect(gewechselt.match(/data-signature/g)).toHaveLength(1);
        expect(gewechselt).toContain('Viele Grüße');
        expect(gewechselt).not.toContain('Mit freundlichen Grüßen');
    });

    it('laesst „Signatur entfernen" wirklich entfernen', () => {
        const nachDemTippen = durch(`<p>Hallo Bastian</p>${wrapSignature(SIGNATUR)}`);

        expect(stripSignature(nachDemTippen)).not.toContain('Mit freundlichen Grüßen');
    });
});

describe('das Zitat ueberlebt den Editor', () => {
    const ZITAT = buildReplyQuote(
        {
            date: '2026-10-31T10:00:00Z',
            fromName: 'Bastian Stelljes',
            fromAddress: 'bastian@peppermint-digital.de',
            bodyHtml: '<p>Bitte bestätigen</p>',
            bodyText: null,
        },
        { labels: { repliedOn: (d: string, s: string) => `Am ${d} schrieb ${s}:` } as never, formatDate: (d: string) => d },
    );

    it('behaelt den Marker UND den Rahmen', () => {
        /*
         * Der Rahmen muss als Inline-Stil in der gesendeten Mail stehen — eine
         * Klasse wird unterwegs entfernt. Vorher ging er mit dem div verloren,
         * und dann war das Zitierte in der gesendeten Mail nicht mehr von den
         * eigenen Worten zu unterscheiden.
         */
        const raus = durch(`<p>Ja, passt.</p>${ZITAT}`);

        expect(raus).toContain('data-quote');
        expect(raus).toContain('border-left');
    });
});

describe('die Vorgabe schuetzt, ohne dass ein Produkt etwas setzt', () => {
    it('nennt beide Knoten', () => {
        /*
         * Die Liste ist die Zusage. Ein Schutz, den jedes Produkt erst
         * einschalten muss, ist in drei von vier Produkten vergessen — und
         * genau so stand es bis zum 31.10.2026 da.
         */
        expect(GESCHUETZTE_KNOTEN).toEqual([SIGNATUR_KNOTEN, ZITAT_KNOTEN]);
    });

    it('gibt den Knoten einen Namen, auf den ein Produkt zeigen kann', () => {
        // Damit ein Produkt mit EIGENEM Editor keine zweite Schreibweise erfindet.
        expect(SignaturKnoten.name).toBe(SIGNATUR_KNOTEN);
        expect(ZitatKnoten.name).toBe(ZITAT_KNOTEN);
    });
});

describe('der Editor des Pakets meldet die Knoten auch wirklich an', () => {
    /*
     * Die Tests oben bauen ihren eigenen Editor und melden die Knoten dort
     * selbst an — sie pruefen also, dass die KNOTEN taugen, nicht dass
     * `RichTextEditor` sie benutzt. Das ist genau die Luecke, durch die der
     * Fehler ueberhaupt entstanden ist: Die Chirurgie in `signature.ts` war
     * richtig und zugesichert, nur der Editor wusste nichts davon.
     *
     * Also hier der echte Bauteil, in jsdom gemountet, mit `onChange` als
     * Horchposten: Was der Editor herausgibt, muss den Marker tragen.
     */
    it('gibt die Signatur mit Marker zurueck, ohne dass ein Produkt etwas setzt', async () => {
        const { RichTextEditor } = await import('../src/RichTextEditor');
        const { germanLabels } = await import('../src/germanLabels');
        const { render } = await import('./support/render');

        let herausgegeben: string | null = null;

        const { unmount } = render(
            <RichTextEditor
                value={`<p>Hallo Bastian</p>${wrapSignature(SIGNATUR)}`}
                onChange={(html) => {
                    herausgegeben = html;
                }}
                labels={germanLabels.editor}
            />,
        );

        /*
         * Zwei Enden, und beide zaehlen: Was im EDITOR steht (hat das
         * Einlesen den Marker behalten?) und was er HERAUSGIBT (kommt er beim
         * Speichern mit?). Vorher ging er an beiden Enden verloren.
         */
        const feld = document.querySelector('.ProseMirror');

        expect(feld).not.toBeNull();
        expect(feld!.querySelector('[data-signature]'), 'der Editor hat den Marker beim EINLESEN verloren').not.toBeNull();

        unmount();

        expect(herausgegeben, 'der Editor hat nichts herausgegeben').not.toBeNull();
        expect(herausgegeben!, 'der Editor hat den Marker beim HERAUSGEBEN verloren').toContain('data-signature');
        expect(herausgegeben!).toContain('Mit freundlichen Grüßen');
    });
});
