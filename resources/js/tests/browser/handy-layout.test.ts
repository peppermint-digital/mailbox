// @vitest-environment node
/**
 * Die Handy-Messreihe der Mailflaeche — in echtem Chromium (#9134).
 *
 * ## Was gemeldet wurde
 *
 * Bastian am 30.10.2026: „Da liegen Buttons uebereinander und der AI-Browser
 * ist auf, wenn ich da das erste Mal draufgehe. Dann sind die Ordner
 * eingeblendet, obwohl die erstmal nicht eingeblendet sein sollten. … wie so
 * ein Outlook-Mobile-App."
 *
 * Drei Beschwerden, und jede ist hier eine Zusicherung geworden:
 *
 * | Beschwerde | Zusicherung |
 * |---|---|
 * | „Buttons uebereinander" | kein Bedienelement ueberlappt ein anderes, bei keiner Geraetebreite und in keinem der vier Zustaende |
 * | „der AI-Browser ist auf" | auf der Liste ist der Assistent nicht im Bild |
 * | „die Ordner sind eingeblendet" | die Schublade ist zu, bis jemand sie holt |
 *
 * ## Warum „ueberlappt nichts" als Zahl und nicht als Blick
 *
 * Weil der Blick es viermal nicht gefunden hat. Eine paarweise Pruefung der
 * Rechtecke aller sichtbaren Bedienelemente findet es immer — und sie findet
 * auch den Fall, den niemand vorhergesehen hat. Beim Bauen hat sie genau einen
 * gefunden, der sonst live gegangen waere: Der schwebende Verfassen-Knopf lag
 * beim Lesen auf der Knopfleiste der Mail, weil beide unten rechts sitzen.
 *
 * Text zaehlt dabei NICHT als Bedienelement. Ein Abschnitt darf hinter einem
 * schwebenden Knopf durchlaufen — das ist, was ein schwebender Knopf tut.
 *
 * ## Warum Geraetebreiten und keine Spaltenbreiten
 *
 * Anders als bei der Leseansicht ({@see ./leseansicht-breiten.test.ts}) ist
 * hier das FENSTER die richtige Frage: Unterhalb von `md` zeigt der Rahmen
 * EINE Flaeche auf dem ganzen Schirm. Gemessen wird deshalb ueber die Breiten
 * wirklicher Geraete, vom kleinsten iPhone SE bis zum groessten Pro Max.
 */
import { createServer, type Server } from 'node:http';
import { mkdtemp, readFile, rm, writeFile } from 'node:fs/promises';
import { existsSync, mkdirSync } from 'node:fs';
import { homedir } from 'node:os';
import { dirname, extname, join, resolve } from 'node:path';
import { execFile } from 'node:child_process';
import { fileURLToPath } from 'node:url';
import { promisify } from 'node:util';
import { build } from 'esbuild';
import { chromium, type Browser } from 'playwright-core';
import { afterAll, beforeAll, describe, expect, it } from 'vitest';

const ausfuehren = promisify(execFile);

/*
 * GANZ OBEN, vor allem anderen: Playwright legt den Pfad fuer sein
 * Browserprofil beim LADEN des Moduls fest, nicht beim Starten. In `beforeAll`
 * gesetzt kaeme die Variable zu spaet.
 *
 * KURZ muss er sein, und das ist kein Schoenheitswunsch: Chromium legt neben
 * dem Profil einen Unix-Socket an, und der Pfad dafuer ist auf rund 108 Zeichen
 * begrenzt. Der erste Versuch zeigte auf `node_modules/.cache` im Paket und
 * starb mit „Socket path too long" — eine andere Meldung als die aus `/tmp`,
 * aber derselbe Riegel: Chromium kommt nicht hoch, und die Messreihe wird
 * uebersprungen statt gruen gemeldet.
 */
const ARBEIT = join(homedir(), '.cache/mailbox-test');
mkdirSync(ARBEIT, { recursive: true });
process.env.TMPDIR = ARBEIT;

const HIER = dirname(fileURLToPath(import.meta.url));
const PAKET = resolve(HIER, '../../../..');
const ATTRAPPE = join(HIER, 'attrappe');

/** Die Breiten wirklicher Telefone, vom kleinsten bis zum groessten. */
const GERAETE = [320, 360, 375, 390, 393, 412, 414, 428, 430];
const HOEHE = 844;

/** Die vier Zustaende, die die Attrappe gleichzeitig zeichnet. */
const FASSUNGEN = ['liste', 'schublade', 'lesen', 'assistent', 'verfassen'] as const;
type Fassung = (typeof FASSUNGEN)[number];

/**
 * Wie hoch ein Bedienelement auf einem Telefon mindestens sein soll.
 *
 * 44 px ist die Zahl, die Apple und Google seit Jahren nennen, und sie gilt
 * fuer das, was man in der Leiste mit dem Daumen trifft. Die Knoepfe IN der
 * Mail (`size="sm"`, 32 px) sind davon ausgenommen: Sie stehen in einer Reihe
 * am unteren Rand, nicht einzeln im Fliesstext, und sie am Schreibtisch
 * mitzuvergroessern waere eine Aenderung an einer Stelle, die niemand
 * beanstandet hat.
 */
const DAUMEN = 44;

interface Kasten {
    markierung: string;
    x: number;
    y: number;
    breite: number;
    hoehe: number;
}

interface Messung {
    breite: number;
    fassung: Fassung;
    ueberlappungen: [string, string][];
    ueberlaufX: number;
    leisteHoehe: number;
    baenderOben: number;
    zuKlein: Kasten[];
    inhaltRaus: { markierung: string; x: number; y: number }[];
    assistentSichtbar: boolean;
    schubladeSichtbar: boolean;
    kontoInSchublade: boolean;
    ordnerNamen: string[];
    verfassenSichtbar: boolean;
    aktionenUnten: number | null;
    aktionenHoehe: number;
    rumpfLuftUnten: number;
    textAb: number | null;
}

/**
 * Ein eigenes Temp-Verzeichnis auf der PLATTE, nicht in `/tmp`.
 *
 * Am 31.10.2026 scheiterten beide Browser-Messreihen mit
 * „Target page, context or browser has been closed". Die Ursache lag nicht im
 * Test: `/tmp` ist ein `tmpfs` und hatte keine INODES mehr frei (1.048.461 von
 * 1.048.576 belegt, 32 GB Platz ungenutzt daneben). Chromium kann dann sein
 * Profilverzeichnis nicht anlegen und stirbt beim Start.
 *
 * `df -h` zeigt so etwas NICHT — es meldet 33 % belegt. Nur `df -i` zeigt es.
 *
 * Darum legt diese Messreihe ihr Arbeitsverzeichnis unter `node_modules/.cache`
 * und schiebt `TMPDIR` mit: Playwright baut sein Browserprofil ueber
 * `os.tmpdir()`, und das liest die Variable bei jedem Aufruf. Damit haengt eine
 * Zusicherung dieses Pakets nicht mehr daran, was irgendein anderer Vorgang in
 * `/tmp` hinterlassen hat.
 */
function arbeitsverzeichnis(): string {
    return ARBEIT;
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

    for (const pfad of kandidaten) if (existsSync(pfad)) return pfad;

    try {
        const eigener = chromium.executablePath();
        if (eigener && existsSync(eigener)) return eigener;
    } catch {
        // kein heruntergeladener Browser
    }

    throw new Error(
        'Kein Chromium gefunden. Die Handy-Messreihe braucht einen echten Browser — jsdom rechnet kein Layout. ' +
            'Chromium installieren (Arch: `pacman -S chromium`) oder MAILBOX_CHROMIUM=/pfad/zu/chrome setzen.',
    );
}

async function bauen(): Promise<string> {
    const ziel = await mkdtemp(join(arbeitsverzeichnis(), 'mailbox-handy-'));

    await build({
        entryPoints: [join(ATTRAPPE, 'handy.tsx')],
        outfile: join(ziel, 'handy.js'),
        bundle: true,
        format: 'esm',
        jsx: 'automatic',
        target: 'es2022',
        define: { 'process.env.NODE_ENV': '"production"' },
        logLevel: 'silent',
    });

    const tailwind = join(PAKET, 'node_modules/.bin/tailwindcss');
    if (!existsSync(tailwind)) throw new Error(`Tailwind-CLI fehlt (${tailwind}). \`npm install\` im Paket ausführen.`);
    await ausfuehren(tailwind, ['--input', join(ATTRAPPE, 'stil.css'), '--output', join(ziel, 'stil.css')], { cwd: PAKET });

    const css = await readFile(join(ziel, 'stil.css'), 'utf8');
    /*
     * Der Riegel vor dem Browser: Erzeugt Tailwind die `md`-Schwelle nicht,
     * stuende die Handy-Fassung bei JEDER Breite und die Reihe waere gruen
     * ohne Aussage. Tailwind v4 schreibt Bereiche (`width >= 48rem`), nicht
     * `min-width` — beides einmal nachgesehen, nicht geraten.
     */
    expect(css, 'Tailwind hat die md-Schwelle des Rahmens nicht erzeugt').toContain('(width >= 48rem)');
    expect(css, 'Tailwind hat die max-md-Schwelle nicht erzeugt').toContain('(width < 48rem)');
    await writeFile(join(ziel, 'handy.html'), await readFile(join(ATTRAPPE, 'handy.html'), 'utf8'));

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
                antwort.writeHead(204).end();
                return;
            }
            const datei = join(wurzel, pfad === '/' ? 'handy.html' : pfad);
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
            fertig({ server, adresse: `http://127.0.0.1:${port}/handy.html` });
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
    const seite = await browser.newPage({ viewport: { width: 1400, height: 1000 }, deviceScaleFactor: 1 });

    const fehler: string[] = [];
    seite.on('pageerror', (e) => fehler.push(String(e)));
    seite.on('console', (n) => {
        if (n.type() !== 'error') return;

        /*
         * Die eine Meldung, die kein Fehler ist, sondern der Beweis: Der Rumpf
         * der Mail steht in einem Rahmen mit `img-src data:`, und die Attrappe
         * schickt absichtlich ein fremdes Bild hinein. Chromium meldet die
         * abgewiesene Anfrage — genau das soll passieren, und daraufhin
         * zeichnet das Paket den Hinweis „Externe Bilder wurden blockiert".
         *
         * Sie hier durchzulassen waere bequem und falsch: Dann liesse sich der
         * Riegel „die Attrappe hat Fehler geworfen" mit jeder beliebigen
         * Meldung aushebeln. Deshalb genau diese eine, an zwei Merkmalen.
         */
        const istDerBlockierteBildAbruf =
            n.text().includes('Content Security Policy') && n.text().includes('img-src');

        if (!istDerBlockierteBildAbruf) fehler.push(n.text());
    });

    await seite.goto(ausgeliefert.adresse, { waitUntil: 'load' });
    await seite.waitForSelector('body[data-gezeichnet="ja"]', { timeout: 15_000 });
    await seite.waitForSelector('[data-fassung="lesen"] [data-slot="mail-message-actions"] button');

    expect(fehler, 'Die Attrappe hat Fehler geworfen — dann ist die Messung keine').toEqual([]);

    /*
     * Die vier Zustaende werden hergestellt, wie ein Mensch sie herstellt:
     * durch Antippen der echten Knoepfe. Den Flaechen-Zustand fuehrt der
     * Rahmen selbst; ihn von aussen zu setzen ginge nur, indem man den Rahmen
     * umgeht — und dann misst man eine Anordnung, die so nie entsteht.
     *
     * Das Fenster muss dafuer schon schmal sein: Der Menue-Knopf steht nur
     * unterhalb von `md`.
     */
    await seite.setViewportSize({ width: GERAETE[0]!, height: HOEHE });
    await seite.click('[data-fassung="schublade"] [data-slot="mailbox-mobil-menue"]');
    await seite.click('[data-fassung="lesen"] [data-slot="attrappe-zeile"]');
    await seite.click('[data-fassung="assistent"] [data-slot="attrappe-zeile"]');
    await seite.click('[data-fassung="assistent"] [data-slot="mailbox-mobil-leiste"] [aria-label="Postfach-Assistent"]');

    /* Und der Riegel darauf: Sind die vier Zustaende nicht entstanden, taugt die ganze Reihe nichts. */
    await seite.waitForSelector('[data-fassung="schublade"] [data-slot="mailbox-schublade-kopf"]');
    await seite.waitForSelector('[data-fassung="lesen"] [data-slot="mail-message-actions"]');
    await seite.waitForSelector('[data-fassung="assistent"] [data-slot="mailbox-aside"]');

    for (const breite of GERAETE) {
        /*
         * Das FENSTER wird schmal, nicht die Kaesten: `md:` ist eine
         * Fenster-Abfrage. Ein schmaler Kasten in einem breiten Fenster
         * zeichnet die Schreibtisch-Fassung.
         */
        await seite.setViewportSize({ width: breite, height: HOEHE });
        await seite.evaluate(() => new Promise((f) => requestAnimationFrame(() => requestAnimationFrame(() => f(null)))));

        const teil = await seite.evaluate(
        ({ breite, daumen }) => {
            const ergebnis: Record<string, unknown>[] = [];

            /** Nur was man ANTIPPT. Text darf hinter einem schwebenden Knopf durchlaufen. */
            const bedienbar = (wurzel: Element) =>
                [...wurzel.querySelectorAll<HTMLElement>('button, a[href], select, input, [role="combobox"]')].filter((e) => {
                    const r = e.getBoundingClientRect();
                    const stil = getComputedStyle(e);

                    return r.width > 0 && r.height > 0 && stil.visibility !== 'hidden' && stil.pointerEvents !== 'none';
                });

            const name = (e: HTMLElement) =>
                e.dataset.slot ?? e.getAttribute('aria-label') ?? (e.textContent ?? '').trim().slice(0, 24) ?? e.tagName;

            {
                for (const kasten of document.querySelectorAll<HTMLElement>('[data-fassung]')) {
                    const rahmen = kasten.getBoundingClientRect();

                    /*
                     * Was hinter einer deckenden Flaeche liegt, liegt nicht
                     * „uebereinander" — es ist schlicht nicht im Bild.
                     *
                     * Der Assistent ist unterhalb von `md` ein Overlay mit
                     * `bg-background` ueber den Flaechen. Die Knoepfe darunter
                     * sind fuer das Auge weg; ohne diese Unterscheidung meldete
                     * die Messung jeden von ihnen gegen den Schliessen-Knopf
                     * des Overlays — und haette damit genau das als Fehler
                     * ausgegeben, was die Loesung IST.
                     *
                     * Die Gegenrichtung bleibt scharf: Deckt ein BEDIENELEMENT
                     * ein anderes ab (der schwebende Verfassen-Knopf auf der
                     * Knopfleiste der Mail — beim Bauen genau so passiert),
                     * steht es weiter in der Liste, denn ein Knopf ist keine
                     * deckende Flaeche.
                     */
                    const overlay = kasten.querySelector<HTMLElement>('[data-slot="mailbox-aside"]');
                    const deckt =
                        overlay &&
                        (() => {
                            const r = overlay.getBoundingClientRect();

                            return r.width >= rahmen.width - 1 && r.height > rahmen.height / 2;
                        })();

                    const elemente = bedienbar(kasten).filter((e) => {
                        if (!deckt || !overlay) return true;
                        if (overlay.contains(e)) return true;

                        /* Ueber dem Overlay (die schmale Leiste) bleibt sichtbar. */
                        return e.getBoundingClientRect().bottom <= overlay.getBoundingClientRect().top + 1;
                    });

                    const ueberlappungen: [string, string][] = [];
                    const zuKlein: unknown[] = [];

                    /*
                     * Laeuft einem Bedienelement sein EIGENER Inhalt aus dem
                     * Kasten? Das ist etwas anderes als die paarweise
                     * Ueberlappung oben, und es hat am 31.10.2026 genau einen
                     * Fehler durchgelassen: Der Absender im Feld „Von" brach
                     * auf 390 px in zwei Zeilen um, und weil der Ausloeser
                     * eine feste Hoehe hat, stand die zweite Zeile auf seinem
                     * Rahmen. Nichts ueberlappte, nichts lief waagerecht ueber
                     * — gesehen hat es nur das Bild.
                     *
                     * Elemente, die von sich aus scrollen (Textfelder, der
                     * Editor), sind ausgenommen: Dort IST mehr Inhalt als
                     * Kasten der Normalfall.
                     */
                    const inhaltRaus: unknown[] = [];

                    for (let i = 0; i < elemente.length; i++) {
                        const a = elemente[i]!;
                        const ra = a.getBoundingClientRect();

                        /* Die Daumengroesse gilt fuer die Leiste und das, was schwebt. */
                        const inLeiste = a.closest('[data-slot="mailbox-mobil-leiste"]') !== null;
                        const schwebt = a.dataset.slot === 'mailbox-verfassen-mobil';
                        if ((inLeiste || schwebt) && ra.height < daumen - 0.5) {
                            zuKlein.push({
                                markierung: name(a),
                                x: Math.round(ra.x - rahmen.x),
                                y: Math.round(ra.y - rahmen.y),
                                breite: Math.round(ra.width),
                                hoehe: Math.round(ra.height),
                            });
                        }

                        const stil = getComputedStyle(a);
                        const scrolltSelbst = /auto|scroll/.test(stil.overflow + stil.overflowY + stil.overflowX);
                        if (!scrolltSelbst && (a.scrollHeight > a.clientHeight + 1 || a.scrollWidth > a.clientWidth + 1)) {
                            inhaltRaus.push({
                                markierung: name(a),
                                x: a.scrollWidth - a.clientWidth,
                                y: a.scrollHeight - a.clientHeight,
                            });
                        }

                        for (let j = i + 1; j < elemente.length; j++) {
                            const b = elemente[j]!;
                            if (a.contains(b) || b.contains(a)) continue;
                            const rb = b.getBoundingClientRect();

                            /* 1 px Toleranz: aneinanderstossende Raender sind keine Ueberlappung. */
                            const x = Math.min(ra.right, rb.right) - Math.max(ra.left, rb.left);
                            const y = Math.min(ra.bottom, rb.bottom) - Math.max(ra.top, rb.top);
                            if (x > 1 && y > 1) ueberlappungen.push([name(a), name(b)]);
                        }
                    }

                    const leiste = kasten.querySelector<HTMLElement>('[data-slot="mailbox-mobil-leiste"]');
                    const assistent = kasten.querySelector<HTMLElement>('[data-slot="mailbox-aside"]');
                    const schublade = kasten.querySelector<HTMLElement>('[data-slot="mailbox-folders"]');
                    const aktionen = kasten.querySelector<HTMLElement>('[data-slot="mail-message-actions"]');
                    const rumpf = kasten.querySelector<HTMLElement>('iframe, pre');

                    const sichtbar = (e: Element | null) => {
                        if (!e) return false;
                        const r = e.getBoundingClientRect();

                        return r.width > 0 && r.height > 0;
                    };

                    ergebnis.push({
                        breite,
                        fassung: kasten.dataset.fassung,
                        ueberlappungen,
                        zuKlein,
                        inhaltRaus,
                        ueberlaufX: kasten.scrollWidth - kasten.clientWidth,
                        leisteHoehe: leiste ? Math.round(leiste.getBoundingClientRect().height) : 0,
                        /* Wie viele Baender stehen ueber den Flaechen? Frueher drei. */
                        baenderOben:
                            (sichtbar(leiste) ? 1 : 0) + (kasten.querySelector('[data-slot="mailbox-pane-nav"]') ? 1 : 0),
                        assistentSichtbar: sichtbar(assistent),
                        schubladeSichtbar: sichtbar(schublade),
                        kontoInSchublade: sichtbar(kasten.querySelector('[data-slot="mailbox-schublade-kopf"]')),
                        ordnerNamen: [...kasten.querySelectorAll('[data-slot="mail-folder-name"]')]
                            .map((e) => (e.textContent ?? '').trim())
                            .filter(Boolean),
                        verfassenSichtbar: sichtbar(kasten.querySelector('[data-slot="mailbox-verfassen-mobil"]')),
                        aktionenUnten:
                            aktionen && aktionen.getBoundingClientRect().height > 0
                                ? Math.round(rahmen.bottom - aktionen.getBoundingClientRect().bottom)
                                : null,
                        aktionenHoehe: aktionen ? Math.round(aktionen.getBoundingClientRect().height) : 0,
                        rumpfLuftUnten: rumpf
                            ? Math.round(parseFloat(getComputedStyle(rumpf.closest('[class*="pb-"]') ?? rumpf).paddingBottom) || 0)
                            : 0,
                        textAb: rumpf ? Math.round(rumpf.getBoundingClientRect().top - rahmen.top) : null,
                    });
                }
            }

            return ergebnis as never;
        },
        { breite, daumen: DAUMEN },
        );

        messungen.push(...(teil as Messung[]));
    }

    await seite.close();
}, 180_000);

afterAll(async () => {
    await browser?.close();
    server?.close();
    if (verzeichnis) await rm(verzeichnis, { recursive: true, force: true });
});

function fuer(fassung: Fassung): Messung[] {
    return messungen.filter((m) => m.fassung === fassung);
}

function tafel(): string {
    return [
        'Breite | Fassung    | Leiste | Text ab | Aktionen vom Rand | Ueberlauf | Ueberlappungen',
        ...messungen.map(
            (m) =>
                `${String(m.breite).padStart(6)} | ${m.fassung.padEnd(10)} | ${String(m.leisteHoehe).padStart(6)} | ` +
                `${String(m.textAb ?? '—').padStart(7)} | ${String(m.aktionenUnten ?? '—').padStart(17)} | ` +
                `${String(m.ueberlaufX).padStart(9)} | ${m.ueberlappungen.length}`,
        ),
    ].join('\n');
}

describe('Handy-Layout der Mailflaeche', () => {
    /* Nur mit `MAILBOX_MESSREIHE=1 npm test` — 36 Zeilen Tafel decken sonst zu, was zaehlt. */
    it.runIf(process.env.MAILBOX_MESSREIHE === '1')('schreibt die Reihe heraus', () => {
        process.stdout.write(`\n== Handy-Layout ==\n${tafel()}\n`);
    });

    it('hat ueberhaupt gemessen — der Riegel gegen einen falsch-gruenen Lauf', () => {
        expect(messungen).toHaveLength(GERAETE.length * FASSUNGEN.length);

        for (const m of messungen.filter((m) => m.fassung !== 'verfassen')) {
            expect(m.leisteHoehe, `bei ${m.breite} px (${m.fassung}) war die schmale Leiste nicht da`).toBeGreaterThan(0);
        }
    });

    it('legt KEIN Bedienelement auf ein anderes — die gemeldeten „Buttons uebereinander"', () => {
        const schlimm = messungen.filter((m) => m.ueberlappungen.length > 0);
        const bericht = schlimm
            .map((m) => `${m.breite} px / ${m.fassung}: ${m.ueberlappungen.map(([a, b]) => `${a} × ${b}`).join(', ')}`)
            .join('\n');

        expect(schlimm.map((m) => `${m.breite}/${m.fassung}`), `Bedienelemente liegen uebereinander:\n${bericht}`).toEqual([]);
    });

    it('laesst keinem Bedienelement seinen eigenen Inhalt aus dem Kasten laufen', () => {
        /*
         * Die Luecke, die der 31.10.2026 gezeigt hat: Die paarweise
         * Ueberlappung sieht nur, ob zwei Kaesten uebereinanderliegen. Ein
         * Kasten, aus dem sein eigener Text herausragt, faellt ihr nicht auf —
         * und genau das war der Absender im Feld „Von".
         */
        const raus = messungen.flatMap((m) =>
            m.inhaltRaus.map((e) => `${m.breite}/${m.fassung}: ${e.markierung} ragt ${e.x} x ${e.y} px heraus`),
        );

        expect(raus).toEqual([]);
    });

    it('laesst nichts waagerecht aus dem Bild laufen', () => {
        const raus = messungen.filter((m) => m.ueberlaufX > 1);

        expect(raus.map((m) => `${m.breite}/${m.fassung}: ${m.ueberlaufX} px`)).toEqual([]);
    });

    it('setzt EIN Band ueber die Flaechen, nicht drei', () => {
        for (const m of messungen.filter((m) => m.fassung !== 'verfassen')) {
            expect(m.baenderOben, `bei ${m.breite} px (${m.fassung})`).toBe(1);
            expect(m.leisteHoehe, `die Leiste ist bei ${m.breite} px (${m.fassung}) zu hoch`).toBeLessThanOrEqual(60);
        }
    });

    it('macht die Knoepfe der Leiste daumengross', () => {
        const klein = messungen.flatMap((m) => m.zuKlein.map((k) => `${m.breite}/${m.fassung}: ${k.markierung} ${k.hoehe} px`));

        expect(klein).toEqual([]);
    });

    describe('die Liste ist der Anfang', () => {
        it('zeigt weder Assistenten noch Ordner — auch nicht aus dem Speicher', () => {
            /*
             * Die Attrappe legt `attrappe.mailbox.assistent = "true"` in den
             * Speicher, BEVOR sie zeichnet — so, wie jemand den Assistenten am
             * Schreibtisch offen laesst. Genau das hat ihn am Telefon ueber die
             * Liste gelegt (gemessen in CRM und Verwaltung am 30.10.2026:
             * Assistent 707 px hoch ab y=121, erste Listenzeile bei y=219
             * dahinter).
             *
             * `localStorage` haengt am Browser, nicht am Schirm. Ein dort
             * abgelegter Wunsch ist deshalb keine Wahl FUER DIESEN Schirm.
             */
            for (const m of fuer('liste')) {
                expect(m.assistentSichtbar, `der Assistent stand bei ${m.breite} px offen`).toBe(false);
                expect(m.schubladeSichtbar, `die Ordner standen bei ${m.breite} px offen`).toBe(false);
            }
        });

        it('bietet den Verfassen-Knopf an', () => {
            for (const m of fuer('liste')) expect(m.verfassenSichtbar).toBe(true);
        });
    });

    describe('die Schublade', () => {
        it('bringt Konto UND Ordner — beides beantwortet „wo bin ich"', () => {
            for (const m of fuer('schublade')) {
                expect(m.schubladeSichtbar).toBe(true);
                expect(m.kontoInSchublade, `die Kontoauswahl fehlte bei ${m.breite} px`).toBe(true);
            }
        });

        it('nennt die Standardordner deutsch, egal wie der Server sie nennt', () => {
            /*
             * Die Attrappe fuehrt Exchange-Namen (`INBOX`, `Sent Items`,
             * `Entw&APw-rfe`, `Junk E-Mail`, `Gelöschte Elemente`). Die
             * Verwaltung zeigte am Telefon woertlich „INBOX" (#9134).
             *
             * `Archiv 2024` steht absichtlich daneben: Ein eigener Ordner darf
             * NICHT zu „Archiv" werden, nur weil sein Name so anfaengt.
             */
            for (const m of fuer('schublade')) {
                expect(m.ordnerNamen, `bei ${m.breite} px`).toEqual([
                    'Posteingang',
                    'Entwürfe',
                    'Gesendet',
                    'Spam',
                    'Papierkorb',
                    'Archiv 2024',
                ]);
            }
        });
    });

    describe('die geoeffnete Mail', () => {
        it('klebt die Aktionen an den unteren Rand', () => {
            for (const m of fuer('lesen')) {
                expect(m.aktionenUnten, `bei ${m.breite} px standen die Aktionen nicht unten`).not.toBeNull();
                expect(Math.abs(m.aktionenUnten ?? 999), `bei ${m.breite} px: ${m.aktionenUnten} px vom unteren Rand`).toBeLessThanOrEqual(1);
            }
        });

        it('faengt mit dem Mailtext im oberen Drittel an — vorher bei 750 von 844 px', () => {
            /*
             * Vorher fing er bei rund 750 von 844 px an — drei Baender und eine
             * aufgeklappte Werkzeugleiste standen davor.
             *
             * 380 px ist nicht gegriffen, sondern aufaddiert aus dem, was oben
             * stehen DARF: schmale Leiste 57, Werkzeuge zugeklappt 57,
             * Polsterung 32, Betreff bis drei Zeilen 84, Kopfdaten zugeklappt
             * 40, Abstaende und Trennlinien 24, und der Hinweis „Externe
             * Bilder wurden blockiert" 58 — zusammen 352, gemessen 369.
             *
             * Der Hinweis kam erst am 30.10.2026 in die Rechnung: Die erste
             * Fassung der Attrappe fuehrte nur `body_text`, und eine Mail ohne
             * HTML hat keine fremden Bilder. Mit ihm sind es 58 px mehr — der
             * groesste einzelne Posten ueber dem Text und der naechste, an dem
             * sich etwas holen liesse. Ein Fehler ist er nicht: Er sagt, was
             * das Paket gerade NICHT geladen hat.
             *
             * Wer eine Zeile dazustellt, faellt auf. Wer den Betreff kuerzt,
             * um hier durchzukommen, hat den gemeldeten Fehler
             * wiederhergestellt (unlesbarer Betreff) und faellt in der anderen
             * Messreihe auf — die beiden Zusicherungen halten sich gegenseitig.
             */
            for (const m of fuer('lesen')) {
                expect(m.textAb, `bei ${m.breite} px fehlte der Rumpf`).not.toBeNull();
                expect(m.textAb ?? 999, `bei ${m.breite} px fing der Mailtext erst bei ${m.textAb} px an`).toBeLessThanOrEqual(380);
            }
        });

        it('haelt unter dem Text so viel Luft frei, wie die Leiste hoch ist', () => {
            /*
             * Die Ueberlappungspruefung oben sieht nur BEDIENELEMENTE. Dass die
             * Leiste auf den letzten Zeilen der Mail liegt, saehe sie nicht —
             * und genau das war der gemeldete Zustand („der Antworten-Knopf
             * liegt darueber"). Deshalb hier die Zahl dagegen: Die Polsterung
             * unter dem Rumpf muss mindestens so gross sein wie die Leiste.
             *
             * Das ist zugleich der Riegel gegen eine Leiste, die bei einem
             * Produkt mit mehr Knoepfen in eine zweite Reihe umbricht: Dann
             * waechst sie, die Polsterung nicht, und der Test faellt.
             */
            for (const m of fuer('lesen')) {
                expect(m.rumpfLuftUnten, `bei ${m.breite} px: Leiste ${m.aktionenHoehe} px, Luft ${m.rumpfLuftUnten} px`).toBeGreaterThanOrEqual(
                    m.aktionenHoehe,
                );
            }
        });

        it('zeigt den Verfassen-Knopf NICHT — er laege auf der Knopfleiste', () => {
            for (const m of fuer('lesen')) expect(m.verfassenSichtbar).toBe(false);
        });
    });

    describe('der Assistent', () => {
        it('deckt nach dem Antippen den ganzen Bildschirm', () => {
            for (const m of fuer('assistent')) expect(m.assistentSichtbar, `bei ${m.breite} px`).toBe(true);
        });

        it('nimmt den schwebenden Verfassen-Knopf mit sich — er lag auf „Senden"', () => {
            /*
             * Gemessen in CRM und Verwaltung am 30.10.2026: Verfassen-Knopf
             * (314, 752, 56x56) auf dem Senden-Knopf des Assistenten
             * (302, 782, 74x38), Ueberlappung 56 x 26 px. Wer etwas tippt und
             * senden will, trifft den falschen Knopf.
             *
             * Die paarweise Ueberlappungspruefung allein haette das NICHT
             * gemeldet: Sie laesst aus, was hinter einer deckenden Flaeche
             * liegt — und der Verfassen-Knopf lag DAVOR.
             */
            for (const m of fuer('assistent')) {
                expect(m.verfassenSichtbar, `bei ${m.breite} px schwebte der Verfassen-Knopf ueber dem Assistenten`).toBe(false);
            }
        });
    });
});
