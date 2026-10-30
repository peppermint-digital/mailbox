// @vitest-environment node
/**
 * Die Breiten-Messreihe der Leseansicht — in echtem Chromium (Bug #1197).
 *
 * ## Warum dieser Test existiert, obwohl es schon einen jsdom-Test gibt
 *
 * Derselbe Fehler wurde zwischen dem 30.10.2026 und v0.184.0 **viermal**
 * „behoben" gemeldet, und jedes Mal stand ein grüner Test daneben:
 *
 * | Anlauf | was geprüft wurde | warum es grün war |
 * |---|---|---|
 * | v0.176.1 | „steht `flex-1` da?" (jsdom) | es stand da |
 * | v0.183.0 | „überlappen sich die Rasterzellen?" | Rasterzellen überlappen sich nie |
 * | v0.184.0 | „ist die Spalte bei 390 px breit genug?" | war sie — der Fehler saß bei 664 px |
 *
 * `jsdom` rechnet kein Layout. Eine Zusicherung auf Klassennamen bestätigt
 * **jede** Fassung, in der die Klasse dasteht — auch die kaputte. Und eine
 * Messung an **einer** Breite prüft genau diese eine Breite: v0.184.0 war auf
 * dem Telefon nachweislich richtig und zwischen 448 px und 800 px kaputt.
 *
 * Deshalb hier: ein echter Browser, eine echte Tailwind-Ausgabe, und eine
 * **Reihe** von Breiten statt einer. Siehe Memory #5024 und Bug #1197.
 *
 * ## Was gemessen wird und was zugesichert ist
 *
 * Für jede Breite von 320 bis 1600 px in 40-px-Schritten, für 8 und für 9
 * Knöpfe:
 *
 * 1. **Der Betreff hat Platz** — die Betreff-Spalte ist entweder mindestens
 *    `MINDESTBREITE` px breit oder sie nimmt die ganze Zeile ein. Das ist die
 *    Zusicherung, die nicht an der Knopfzahl hängt: Passt die Leiste nicht
 *    daneben, rutscht sie als Ganzes in die zweite Zeile.
 * 2. **Der Betreff bleibt kurz** — höchstens `MAX_ZEILEN` Zeilen. Eine Spalte
 *    von 20 px ist breit genug für ein „N" pro Zeile; nur die erste Zusicherung
 *    hätte den gemeldeten Fehler also nicht gefangen.
 * 3. **Nichts läuft aus dem Kasten** — `scrollWidth` bleibt an der Wurzel bei
 *    `clientWidth`. Der Wurzelkasten trägt `overflow-hidden`; ohne diese
 *    Zusicherung wäre „passt nicht" von „ist abgeschnitten" nicht zu
 *    unterscheiden.
 *
 * ## Der Riegel gegen einen falsch-grünen Lauf
 *
 * Ein Test an unsichtbaren Elementen meldet grün, ohne gemessen zu haben — im
 * Anlauf 2 und 3 je einmal passiert. Darum prüft `pruefeAufbau()` vor der
 * Reihe, dass Chromium überhaupt Layout gerechnet hat (Knöpfe sind sichtbar,
 * die Leiste ist so breit wie in den Produkten gemessen) und dass die
 * Container-Abfrage ankommt (bei 1600 px nebeneinander, bei 320 px
 * untereinander). Scheitert der Aufbau, scheitert der Test — er überspringt
 * sich nicht.
 */
import { createServer, type Server } from 'node:http';
import { mkdtemp, readFile, rm, writeFile } from 'node:fs/promises';
import { existsSync } from 'node:fs';
import { tmpdir } from 'node:os';
import { dirname, extname, join, resolve } from 'node:path';
import { execFile } from 'node:child_process';
import { fileURLToPath } from 'node:url';
import { promisify } from 'node:util';
import { build } from 'esbuild';
import { chromium, type Browser } from 'playwright-core';
import { afterAll, beforeAll, describe, expect, it } from 'vitest';

const ausfuehren = promisify(execFile);

const HIER = dirname(fileURLToPath(import.meta.url));
const PAKET = resolve(HIER, '../../../..');
const ATTRAPPE = join(HIER, 'attrappe');

/** Die Reihe aus Bug #1197: vom schmalsten Telefon bis zum breiten Schirm. */
const VON = 320;
const BIS = 1600;
const SCHRITT = 40;

/**
 * Die Mindestbreite, die der Betreff behalten muss, wenn er sich die Zeile mit
 * der Knopfleiste teilt — in px, und sie entspricht `@md:basis-60` (15rem).
 *
 * Diese Zahl ist an der **Lesbarkeit** bemessen und nicht an der Knopfzahl: 240
 * px tragen rund 35 Zeichen pro Zeile. Eine an der Leiste bemessene Schwelle
 * wäre wieder geraten, denn ein Produkt darf über `zusatzAktionen` Knöpfe
 * dazustellen (AI Brain tut es, 490 px statt 444 px).
 */
const MINDESTBREITE = 240;

/** Mehr als drei Zeilen Betreff ist das gemeldete Bild, nicht mehr nur knapp. */
const MAX_ZEILEN = 3;

interface Messung {
    breite: number;
    fassung: string;
    richtung: string;
    spalte: number;
    leiste: number;
    innen: number;
    h2Hoehe: number;
    zeilen: number;
    ueberlauf: number;
    knoepfe: number;
    sichtbar: number;
}

function chromiumPfad(): string {
    const kandidaten = [
        process.env.MAILBOX_CHROMIUM,
        process.env.PLAYWRIGHT_CHROMIUM_EXECUTABLE_PATH,
        '/usr/bin/chromium',
        '/usr/bin/chromium-browser',
        '/usr/bin/google-chrome-stable',
        '/usr/bin/google-chrome',
        '/opt/google/chrome/chrome',
        '/Applications/Google Chrome.app/Contents/MacOS/Google Chrome',
    ].filter((p): p is string => typeof p === 'string' && p.length > 0);

    for (const pfad of kandidaten) {
        if (existsSync(pfad)) return pfad;
    }

    // Playwright bringt ggf. einen eigenen mit — nur fragen, nicht herunterladen.
    try {
        const eigener = chromium.executablePath();
        if (eigener && existsSync(eigener)) return eigener;
    } catch {
        // kein heruntergeladener Browser — fällt in die Meldung unten
    }

    /*
     * Hier wird NICHT übersprungen. Ein übersprungener Layout-Test ist genau
     * der falsch-grüne Lauf, der diesen Fehler viermal durchgelassen hat.
     */
    throw new Error(
        'Kein Chromium gefunden. Die Breiten-Messreihe braucht einen echten Browser — ' +
            'jsdom rechnet kein Layout. Entweder Chromium installieren (Arch: `pacman -S chromium`, ' +
            'Debian/Ubuntu: `apt install chromium`), oder den Pfad setzen: ' +
            'MAILBOX_CHROMIUM=/pfad/zu/chrome npm test',
    );
}

/** Attrappe bauen: ein Bündel aus den QUELLEN, dazu echtes Tailwind. */
async function bauen(): Promise<string> {
    const ziel = await mkdtemp(join(tmpdir(), 'mailbox-breiten-'));

    await build({
        entryPoints: [join(ATTRAPPE, 'einstieg.tsx')],
        outfile: join(ziel, 'einstieg.js'),
        bundle: true,
        format: 'esm',
        jsx: 'automatic',
        target: 'es2022',
        define: { 'process.env.NODE_ENV': '"production"' },
        logLevel: 'silent',
    });

    /*
     * Tailwind liest `@source` relativ zur EINGABE-Datei, nicht zur Ausgabe —
     * darum darf die Ausgabe im Temp-Verzeichnis liegen, ohne die Pfade zu
     * verbiegen.
     */
    const tailwind = join(PAKET, 'node_modules/.bin/tailwindcss');
    if (!existsSync(tailwind)) {
        throw new Error(`Tailwind-CLI fehlt (${tailwind}). \`npm install\` im Paket ausführen.`);
    }
    await ausfuehren(tailwind, ['--input', join(ATTRAPPE, 'stil.css'), '--output', join(ziel, 'stil.css')], { cwd: PAKET });

    const css = await readFile(join(ziel, 'stil.css'), 'utf8');
    /*
     * Der zweite Riegel, und er sitzt vor dem Browser: Erzeugt Tailwind die
     * Container-Abfrage nicht, messen wir die schmale Fassung bei jeder Breite
     * — und bekämen eine grüne Reihe ohne Aussage.
     */
    expect(css, 'Tailwind hat die Container-Abfragen des Pakets nicht erzeugt').toContain('@container');
    expect(css.length, 'Die Tailwind-Ausgabe ist verdächtig klein').toBeGreaterThan(5000);

    await writeFile(join(ziel, 'seite.html'), await readFile(join(ATTRAPPE, 'seite.html'), 'utf8'));

    return ziel;
}

function ausliefern(wurzel: string): Promise<{ server: Server; adresse: string }> {
    const typen: Record<string, string> = {
        '.html': 'text/html; charset=utf-8',
        '.js': 'text/javascript; charset=utf-8',
        '.css': 'text/css; charset=utf-8',
    };

    return new Promise((fertig) => {
        const server = createServer((anfrage, antwort) => {
            const pfad = (anfrage.url ?? '/').split('?')[0] ?? '/';
            if (pfad === '/favicon.ico') {
                // Chromium holt es von selbst; ein 404 darauf wäre ein Fehler ohne Aussage.
                antwort.writeHead(204).end();
                return;
            }
            const datei = join(wurzel, pfad === '/' ? 'seite.html' : pfad);
            if (!datei.startsWith(wurzel)) {
                antwort.writeHead(403).end();
                return;
            }
            readFile(datei)
                .then((inhalt) => {
                    antwort.writeHead(200, { 'content-type': typen[extname(datei)] ?? 'application/octet-stream' });
                    antwort.end(inhalt);
                })
                .catch(() => antwort.writeHead(404).end());
        });
        server.listen(0, '127.0.0.1', () => {
            const an = server.address();
            const port = typeof an === 'object' && an ? an.port : 0;
            fertig({ server, adresse: `http://127.0.0.1:${port}/seite.html` });
        });
    });
}

let browser: Browser;
let server: Server;
let verzeichnis: string;
let messungen: Messung[] = [];

beforeAll(async () => {
    verzeichnis = await bauen();
    const ausgeliefert = await ausliefern(verzeichnis);
    server = ausgeliefert.server;

    browser = await chromium.launch({ executablePath: chromiumPfad() });
    const seite = await browser.newPage({ viewport: { width: 1800, height: 1200 }, deviceScaleFactor: 1 });

    const fehler: string[] = [];
    seite.on('pageerror', (e) => fehler.push(String(e)));
    seite.on('console', (n) => n.type() === 'error' && fehler.push(n.text()));

    await seite.goto(ausgeliefert.adresse, { waitUntil: 'load' });
    await seite.waitForSelector('body[data-gezeichnet="ja"]', { timeout: 15_000 });
    await seite.waitForSelector('[data-fassung="neun"] [data-slot="mail-message-actions"] button');

    expect(fehler, 'Die Attrappe hat Fehler geworfen — dann ist die Messung keine').toEqual([]);

    const breiten: number[] = [];
    for (let b = VON; b <= BIS; b += SCHRITT) breiten.push(b);

    messungen = await seite.evaluate(
        ({ breiten }) => {
            const ergebnis: Record<string, unknown>[] = [];

            for (const breite of breiten) {
                for (const kasten of document.querySelectorAll<HTMLElement>('[data-fassung]')) {
                    kasten.style.width = `${breite}px`;
                }
                // Umbruch erzwingen, damit danach gültige Maße stehen.
                void document.body.getBoundingClientRect();

                for (const kasten of document.querySelectorAll<HTMLElement>('[data-fassung]')) {
                    const wurzel = kasten.querySelector<HTMLElement>('[data-slot="mail-message-view"]')!;
                    const leiste = wurzel.querySelector<HTMLElement>('[data-slot="mail-message-actions"]')!;
                    const h2 = wurzel.querySelector<HTMLElement>('h2')!;
                    const spalte = h2.parentElement!;
                    const zeile = spalte.parentElement!;
                    const zeilenhoehe = parseFloat(getComputedStyle(h2).lineHeight) || 28;
                    const knoepfe = [...leiste.querySelectorAll<HTMLElement>('button')];

                    ergebnis.push({
                        breite,
                        fassung: kasten.dataset.fassung,
                        richtung: getComputedStyle(zeile).flexDirection,
                        spalte: Math.round(spalte.getBoundingClientRect().width),
                        leiste: Math.round(leiste.getBoundingClientRect().width),
                        innen: Math.round(zeile.getBoundingClientRect().width),
                        h2Hoehe: Math.round(h2.getBoundingClientRect().height),
                        zeilen: Math.max(1, Math.round(h2.getBoundingClientRect().height / zeilenhoehe)),
                        ueberlauf: wurzel.scrollWidth - wurzel.clientWidth,
                        knoepfe: knoepfe.length,
                        sichtbar: knoepfe.filter((k) => k.getBoundingClientRect().width > 0).length,
                    });
                }
            }

            for (const kasten of document.querySelectorAll<HTMLElement>('[data-fassung]')) {
                kasten.style.width = '';
            }

            return ergebnis as never;
        },
        { breiten },
    );

    await seite.close();
}, 180_000);

afterAll(async () => {
    await browser?.close();
    server?.close();
    if (verzeichnis) await rm(verzeichnis, { recursive: true, force: true });
});

function fuer(fassung: string): Messung[] {
    return messungen.filter((m) => m.fassung === fassung);
}

function tafel(reihe: Messung[]): string {
    return [
        'Breite | Richtung | Spalte | Leiste | innen | h2 | Zeilen | Überlauf',
        ...reihe.map(
            (m) =>
                `${String(m.breite).padStart(6)} | ${m.richtung.padEnd(8)} | ${String(m.spalte).padStart(6)} | ` +
                `${String(m.leiste).padStart(6)} | ${String(m.innen).padStart(5)} | ${String(m.h2Hoehe).padStart(3)} | ` +
                `${String(m.zeilen).padStart(6)} | ${String(m.ueberlauf).padStart(8)}`,
        ),
    ].join('\n');
}

describe('Leseansicht: Betreff und Knopfleiste über die ganze Breitenreihe', () => {
    it('hat überhaupt gemessen — der Riegel gegen einen falsch-grünen Lauf', () => {
        const erwartet = Math.floor((BIS - VON) / SCHRITT) + 1;
        expect(fuer('acht')).toHaveLength(erwartet);
        expect(fuer('neun')).toHaveLength(erwartet);

        // Knöpfe da und sichtbar — sonst ist jede Breite „genug".
        for (const m of messungen) {
            expect(m.sichtbar, `bei ${m.breite} px (${m.fassung}) war kein Knopf sichtbar`).toBe(m.knoepfe);
        }
        expect(fuer('acht')[0]!.knoepfe, 'die CRM-/Verwaltungs-Fassung hat acht Knöpfe').toBe(8);
        expect(fuer('neun')[0]!.knoepfe, 'die AI-Brain-Fassung hat neun (Ablage über zusatzAktionen)').toBe(9);

        // Die Container-Abfrage kommt an: schmal untereinander, breit nebeneinander.
        expect(fuer('neun').find((m) => m.breite === VON)!.richtung).toBe('column');
        expect(fuer('neun').find((m) => m.breite === BIS)!.richtung).toBe('row');
    });

    /*
     * Die Reihe zum Nachlesen. Sie laeuft nur mit `MAILBOX_MESSREIHE=1 npm test`,
     * weil 66 Zeilen Tafel in jedem Lauf die Meldungen zudecken, die zaehlen —
     * gebraucht wird sie beim Nachziehen in ein Produkt, um die Zahlen des
     * Pakets gegen die am lebenden Element zu stellen.
     */
    it.runIf(process.env.MAILBOX_MESSREIHE === '1')('schreibt die Reihe heraus', () => {
        for (const fassung of ['acht', 'neun'] as const) {
            process.stdout.write(`\n== ${fassung} Knoepfe ==\n${tafel(fuer(fassung))}\n`);
        }
    });

    for (const fassung of ['acht', 'neun'] as const) {
        describe(`${fassung} Knöpfe`, () => {
            it('lässt dem Betreff überall Platz — Mindestbreite oder ganze Zeile', () => {
                const reihe = fuer(fassung);
                const eng = reihe.filter((m) => m.spalte < MINDESTBREITE && m.spalte < m.innen - 2);
                expect(eng, `Betreff-Spalte zu schmal und nicht allein in der Zeile:\n${tafel(reihe)}`).toEqual([]);
            });

            it('bricht den Betreff höchstens über drei Zeilen', () => {
                const reihe = fuer(fassung);
                const hoch = reihe.filter((m) => m.zeilen > MAX_ZEILEN);
                expect(hoch, `Betreff über mehr als ${MAX_ZEILEN} Zeilen:\n${tafel(reihe)}`).toEqual([]);
            });

            it('lässt nichts aus dem Wurzelkasten laufen', () => {
                const reihe = fuer(fassung);
                const raus = reihe.filter((m) => m.ueberlauf > 1);
                expect(raus, `waagerechter Überlauf am Wurzelkasten:\n${tafel(reihe)}`).toEqual([]);
            });
        });
    }
});
