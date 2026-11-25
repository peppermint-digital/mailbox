import { readdirSync, readFileSync } from 'node:fs';
import { join } from 'node:path';
import { describe, expect, it } from 'vitest';

/**
 * Das Paket nimmt seine Farben aus dem Theme des Produkts (pm #10236).
 *
 * Es laeuft in vier Produkten (Manager, CRM, Verwaltung, AI Brain), und jedes
 * kann ein eigenes Theme haben. Eine feste Tailwind-Farbe wie `bg-amber-50`
 * folgt keinem davon — im dunklen oder farbigen Theme steht dann ein
 * hellgelber Kasten. Deshalb: Statusfarben nur ueber die Tokens, die ALLE
 * vier Produkte fuehren.
 *
 * Gemeinsam haben sie (25.11.2026 gemessen) neben den shadcn-Grundfarben nur
 * success, warning, info und destructive, je mit -foreground, -subtle,
 * -subtle-foreground und -border. `ai` und `grade-*` fehlen in der
 * Verwaltung — die darf das Paket also nicht benutzen.
 */
const QUELLE = join(__dirname, '../src');

const ROHE_FARBE =
    /\b(?:bg|text|border|ring|fill|stroke|from|to|via|divide|outline)-(?:slate|gray|zinc|neutral|stone|red|orange|amber|yellow|lime|green|emerald|teal|cyan|sky|blue|indigo|violet|purple|fuchsia|pink|rose)-\d{2,3}\b/g;

const NUR_EINIGE_PRODUKTE = /\b(?:bg|text|border|ring)-(?:ai|grade-\d)(?:-[a-z-]+)?\b/g;

/**
 * Hex-Farben prueft dieser Test nicht: Die stehen hier im INHALT der Mail
 * (EmailBodyViewer, Tabellen im RichTextEditor, Zitatstil) und muessen beim
 * Empfaenger ohne unser Theme gleich aussehen.
 */
const AUSNAHMEN: string[] = [];

function dateien(): string[] {
    return readdirSync(QUELLE, { recursive: true })
        .map(String)
        .filter((pfad) => /\.(tsx?|css)$/.test(pfad) && !/\.test\.tsx?$/.test(pfad) && !AUSNAHMEN.includes(pfad));
}

describe('Farben folgen dem Theme des Produkts', () => {
    it('nutzt keine feste Tailwind-Palette fuer Statusfarben', () => {
        const funde = dateien().flatMap((pfad) =>
            [...readFileSync(join(QUELLE, pfad), 'utf8').matchAll(ROHE_FARBE)].map((treffer) => `${pfad}: ${treffer[0]}`),
        );

        expect(funde).toEqual([]);
    });

    it('nutzt nur Tokens, die alle Produkte fuehren', () => {
        const funde = dateien().flatMap((pfad) =>
            [...readFileSync(join(QUELLE, pfad), 'utf8').matchAll(NUR_EINIGE_PRODUKTE)].map((treffer) => `${pfad}: ${treffer[0]}`),
        );

        expect(funde).toEqual([]);
    });

    /**
     * Schrift einer VOLLEN Flaeche auf der zarten: `text-warning-foreground` ist
     * Weiss (fuer `bg-warning`), auf `bg-warning-subtle` also Weiss auf
     * Hellgelb. So stand es am 25.11.2026 im Verzeichnis-Hinweis der
     * Suchleiste (MailListToolbar), gefunden im E2E von pm #10236.
     */
    it('setzt auf eine zarte Flaeche nie die Schrift der vollen', () => {
        const FALSCHES_PAAR = /bg-(success|warning|info|destructive)-subtle\b[^'"`]*\btext-\1-foreground\b/g;
        const funde = dateien().flatMap((pfad) =>
            [...readFileSync(join(QUELLE, pfad), 'utf8').matchAll(FALSCHES_PAAR)].map((treffer) => `${pfad}: ${treffer[0]}`),
        );

        expect(funde).toEqual([]);
    });
});
