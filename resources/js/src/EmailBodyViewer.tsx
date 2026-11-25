import { useEffect, useMemo, useRef, useState, useSyncExternalStore } from 'react';
import { cn } from './ui/utils';

/**
 * Shows the body of a mail inside a sandboxed iframe — ALWAYS in the light
 * scheme, whatever theme the application runs in. Most mails are built on
 * white and become unreadable otherwise.
 *
 * Data: the body arrives complete as a prop. The component loads nothing and
 * gates no permission.
 *
 * Two modes:
 * - `autoHeight`: the frame grows with the content (detail pages).
 * - without it: the frame fills its parent (mailbox view).
 *
 * ## The part that matters
 *
 * With `blockRemoteImages` a Content-Security-Policy inside the written
 * document blocks every remote source — which is what kills tracking pixels —
 * until the reader explicitly asks for them. The permission applies to exactly
 * this body: when the content changes, it closes again. A simple yes/no would
 * travel to the next mail and load its pixels.
 *
 * The permission is shared by every viewer showing the SAME body (see
 * `freigaben` below): the full-screen view ({@see MailVollbild}) is a second
 * viewer over the same mail, and asking twice for one mail reads as a bug.
 *
 * ## Why the words come from outside
 *
 * "External images were blocked" is a sentence in one language. The package
 * renders the structure; the product brings its words.
 */

export interface EmailBodyViewerLabels {
    /** Shown when there is neither an HTML nor a text body. */
    empty: string;
    /** The notice above a body whose remote content is blocked. */
    remoteBlocked: string;
    /** The button that releases the images for this one body. */
    loadImages: string;
    /** Accessible name of the iframe. */
    frameTitle: string;
}

export interface EmailBodyViewerProps {
    labels: EmailBodyViewerLabels;
    bodyHtml?: string | null;
    bodyText?: string | null;
    autoHeight?: boolean;
    minHeight?: number;
    blockRemoteImages?: boolean;
    /**
     * Die Leseansicht auf dem Telefon (seit 25.11.2026, nur unterhalb von `md`):
     *
     * - Der Rahmen waechst mit seinem Inhalt, statt selbst zu scrollen — es
     *   scrollt der Kasten darum herum, mit Betreff und Absender zusammen
     *   (siehe `mail-message-bildlauf` in {@see MailMessageView}).
     * - Kein weisser Kasten mit Rand und runden Ecken mehr, nur ein schmaler
     *   Abstand zum Displayrand.
     * - Im dunklen Modus des Produkts werden EINFACHE Mails dunkel gezeigt.
     *   Gestaltete Mails (eigene Hintergruende) bleiben hell — siehe `gestaltet`.
     *
     * Am Schreibtisch aendert der Schalter nichts.
     */
    mobil?: boolean;
}

function buildDocument(html: string, blocking: boolean, dunkel = false): string {
    // Block remote sources (tracking pixels), allow embedded ones (data:).
    const csp = blocking
        ? `<meta http-equiv="Content-Security-Policy" content="default-src 'none'; img-src data:; style-src 'unsafe-inline'; font-src data:;">`
        : '';

    const schema = dunkel ? 'dark' : 'light';

    /*
     * Dunkel: durchsichtig, damit der Hintergrund des Produkts durchscheint,
     * und heller Text. Nur fuer Mails ohne eigene Hintergruende — siehe
     * `gestaltet`. Dunkle Schriftfarben, die die Mail selbst setzt, hellt
     * `aufhellen` nach dem Laden auf.
     */
    const farben = dunkel
        ? `html, body { background: transparent; color: #e5e5e5; }
    a { color: #60a5fa; }`
        : `html, body { background: #ffffff; color: #1a1a1a; }
    a { color: #2563eb; }`;

    return `<!DOCTYPE html>
<html style="color-scheme: ${schema};">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="color-scheme" content="${schema}">
${csp}
<style>
    :root { color-scheme: ${schema}; }
    ${farben}
    body {
        /* Kein Randueberhang des ersten und letzten Kindes — sonst misst die
           Hoehe des Rumpfes zu kurz, und unten fehlt eine Zeile. */
        display: flow-root;
        font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, 'Helvetica Neue', Arial, sans-serif;
        font-size: 14px;
        line-height: 1.5;
        margin: 0;
        padding: 0;
        overflow-wrap: break-word;
        word-wrap: break-word;
    }
    img { max-width: 100%; height: auto; }
    table { max-width: 100%; border-collapse: collapse; }
    td, th { padding: 4px 8px; }
</style>
</head>
<body>${html}</body>
</html>`;
}

/*
 * Welche Rumpfe ihre Bilder laden duerfen — fuer alle Betrachter gemeinsam.
 *
 * Bis zum 25.11.2026 hielt jeder Betrachter seine Freigabe selbst. Mit dem
 * Vollbild liegen aber ZWEI ueber derselben Mail: Wer in der Leseansicht
 * „Bilder laden" gedrueckt hatte, sah im Vollbild wieder den Hinweis — und
 * umgekehrt.
 *
 * Der Schluessel bleibt der Rumpf selbst, also gilt die Freigabe weiter fuer
 * genau DIESE Mail und reist nicht zur naechsten. Gedeckelt, damit eine lange
 * Sitzung nicht jeden je freigegebenen Rumpf im Speicher behaelt.
 */
const FREIGABEN_HOECHSTENS = 50;
const freigaben = new Set<string>();
const zuhoerer = new Set<() => void>();
let freigabeStand = 0;

function freigeben(html: string): void {
    freigaben.delete(html);
    freigaben.add(html);

    while (freigaben.size > FREIGABEN_HOECHSTENS) {
        freigaben.delete(freigaben.values().next().value as string);
    }

    freigabeStand++;
    zuhoerer.forEach((z) => z());
}

function abonnieren(z: () => void): () => void {
    zuhoerer.add(z);

    return () => zuhoerer.delete(z);
}

/*
 * Eine Mail, die breiter ist als ihr Rahmen, wird auf die Breite verkleinert —
 * wie in Outlook und Gmail auf dem Telefon.
 *
 * Gemeldet von Bastian am 25.11.2026: „es gibt da eine horizontale Scrollbar
 * bei den Mails". Gemessen im pm-DevStack auf einem iPhone 14: Der Rahmen ist
 * 358 px breit, ein ueblicher Newsletter (Tabelle mit `width="640"`) 656 px.
 * `max-width` auf Tabellen hilft dagegen nicht — eine Tabelle mit fester
 * Breite in einer Zelle, ein Bild mit `width:600px` oder ein Knopf mit
 * `white-space:nowrap` halten die Seite breit, und jedes CSS, das ihnen die
 * Breite nimmt, zerlegt das Layout, fuer das sie gebaut ist.
 *
 * Also das, was die grossen Programme tun: die ganze Seite per `zoom`
 * verkleinern. Erst zuruecksetzen, dann messen — sonst misst man die schon
 * verkleinerte Seite und schrumpft sie bei jedem Durchgang weiter.
 *
 * Untergrenze 0,35: Darunter ist nichts mehr zu lesen; dann lieber seitlich
 * scrollen als eine Briefmarke.
 */
const KLEINSTER_ZOOM = 0.35;

function einpassen(iframe: HTMLIFrameElement | null): void {
    const doc = iframe?.contentDocument;
    const wurzel = doc?.documentElement;

    if (!iframe || !doc || !wurzel) {
        return;
    }

    wurzel.style.removeProperty('zoom');

    const verfuegbar = iframe.clientWidth;
    const breite = Math.max(wurzel.scrollWidth, doc.body?.scrollWidth ?? 0);
    let zoom = 1;

    if (verfuegbar > 0 && breite > verfuegbar + 1) {
        zoom = Math.max(KLEINSTER_ZOOM, verfuegbar / breite);
        wurzel.style.setProperty('zoom', String(zoom));
    }

    /*
     * Die Hoehe fuer den mitwachsenden Rahmen (`mobil`): gemessen OHNE Zoom,
     * also an der natuerlichen Seite, und dann mit dem Zoom verrechnet. Steht
     * als CSS-Variable am Rahmen — ob sie gilt, entscheidet die Klasse
     * (`max-md:`), nicht JavaScript. Am Schreibtisch fuellt der Rahmen weiter
     * seinen Platz.
     */
    wurzel.style.removeProperty('zoom');
    const hoehe = doc.body ? doc.body.getBoundingClientRect().height : 0;

    if (zoom < 1) {
        wurzel.style.setProperty('zoom', String(zoom));
    }

    iframe.style.setProperty('--mail-rumpf-hoehe', `${Math.ceil(hoehe * zoom) + 2}px`);
}

/*
 * Ist die Mail gestaltet — bringt sie eigene Hintergruende mit?
 *
 * Dann bleibt sie im dunklen Modus hell. Ein Newsletter, eine Rechnung, eine
 * Signatur mit Logo sind auf Weiss gebaut; sie umzufaerben ergibt schwarzen
 * Text auf dunklem Grund oder ein Logo mit weissem Kasten. Outlook versucht
 * es trotzdem und liegt oft daneben. Eine helle Mail im dunklen Modus ist
 * lesbar, eine kaputt umgefaerbte nicht.
 */
const GESTALTET = /bgcolor\s*=|background(?:-color|-image)?\s*:|\sbackground\s*=/i;

/*
 * Dunkle Schriftfarben, die eine einfache Mail selbst setzt (Outlook schreibt
 * gern `color:black` an jeden Absatz), auf dem dunklen Grund aufhellen.
 * Grau wird hell, eine Farbe wird zu Weiss hin gemischt und bleibt erkennbar.
 */
function aufhellen(doc: Document): void {
    const elemente = [doc.body, ...Array.from(doc.body?.querySelectorAll<HTMLElement>('*') ?? [])].slice(0, 5000);

    for (const element of elemente) {
        if (!element) {
            continue;
        }

        const treffer = /rgba?\((\d+),\s*(\d+),\s*(\d+)/.exec(doc.defaultView?.getComputedStyle(element).color ?? '');

        if (!treffer) {
            continue;
        }

        const [r, g, b] = [Number(treffer[1]), Number(treffer[2]), Number(treffer[3])];
        const helligkeit = (0.2126 * r + 0.7152 * g + 0.0722 * b) / 255;

        if (helligkeit >= 0.45) {
            continue;
        }

        const grau = Math.max(r, g, b) - Math.min(r, g, b) < 40;
        const mischen = (wert: number) => Math.round(wert + (255 - wert) * 0.6);

        element.style.setProperty('color', grau ? '#e5e5e5' : `rgb(${mischen(r)}, ${mischen(g)}, ${mischen(b)})`, 'important');
    }
}

/*
 * Telefon und dunkler Modus — beides gilt nur, solange es gilt. Gelesen aus
 * der Breite des Fensters und der Klasse `dark` am Wurzelelement (so schalten
 * die Produkte), und neu gelesen, wenn sich eins davon aendert.
 */
const SCHMAL = '(max-width: 767.98px)';

function dunkelUndSchmalAbonnieren(z: () => void): () => void {
    if (typeof window === 'undefined') {
        return () => {};
    }

    const breite = window.matchMedia?.(SCHMAL);
    const klasse = new MutationObserver(z);

    breite?.addEventListener?.('change', z);
    klasse.observe(document.documentElement, { attributes: true, attributeFilter: ['class'] });

    return () => {
        breite?.removeEventListener?.('change', z);
        klasse.disconnect();
    };
}

function istDunkelUndSchmal(): boolean {
    if (typeof window === 'undefined') {
        return false;
    }

    return document.documentElement.classList.contains('dark') && (window.matchMedia?.(SCHMAL).matches ?? false);
}

const REMOTE_ATTRIBUTE = /(?:src|background)\s*=\s*["']?\s*https?:\/\//i;
const REMOTE_CSS_URL = /url\(\s*['"]?\s*https?:\/\//i;

export function EmailBodyViewer({
    labels,
    bodyHtml = null,
    bodyText = null,
    autoHeight = false,
    minHeight = 100,
    blockRemoteImages = false,
    mobil = false,
}: EmailBodyViewerProps) {
    const iframeRef = useRef<HTMLIFrameElement | null>(null);
    const [iframeHeight, setIframeHeight] = useState(minHeight);

    /**
     * Whether THIS body may load its images. A plain yes/no would travel to
     * the next mail and load its tracking pixels — so the key is the body.
     */
    useSyncExternalStore(abonnieren, () => freigabeStand, () => freigabeStand);
    const freigegeben = bodyHtml !== null && freigaben.has(bodyHtml);

    const hasRemoteContent = useMemo(() => {
        const html = bodyHtml ?? '';

        return REMOTE_ATTRIBUTE.test(html) || REMOTE_CSS_URL.test(html);
    }, [bodyHtml]);

    const blocking = blockRemoteImages && hasRemoteContent && !freigegeben;

    const dunkelUndSchmal = useSyncExternalStore(dunkelUndSchmalAbonnieren, istDunkelUndSchmal, () => false);
    const gestaltet = useMemo(() => GESTALTET.test(bodyHtml ?? ''), [bodyHtml]);
    const dunkel = mobil && dunkelUndSchmal && !gestaltet;

    /**
     * In the growing mode the document is written directly instead of through
     * `srcdoc`: only then is `contentDocument` immediately stable, and only
     * then can the body be observed to follow its height.
     */
    useEffect(() => {
        if (!autoHeight) {
            return;
        }

        const iframe = iframeRef.current;

        if (!iframe || !bodyHtml) {
            return;
        }

        const doc = iframe.contentDocument;

        if (!doc) {
            return;
        }

        doc.open();
        doc.write(buildDocument(bodyHtml, blocking));
        doc.close();

        const observer = new ResizeObserver(() => {
            if (doc.body) {
                setIframeHeight(Math.max(minHeight, doc.body.scrollHeight + 16));
            }
        });

        if (doc.body) {
            observer.observe(doc.body);
            setIframeHeight(Math.max(minHeight, doc.body.scrollHeight + 16));
        }

        return () => observer.disconnect();
    }, [autoHeight, bodyHtml, blocking, minHeight]);

    /*
     * Einpassen, sobald der Rumpf steht, wenn der Rahmen seine Breite aendert
     * (Drehen des Telefons, Vollbild) und wenn ein Bild nachlaedt — ein Bild
     * ohne `width` macht die Seite erst dann breit.
     */
    const [rahmen, setRahmen] = useState<HTMLIFrameElement | null>(null);

    useEffect(() => {
        if (autoHeight || !rahmen) {
            return;
        }

        let nachgeladen: Document | null = null;
        const nachBild = () => einpassen(rahmen);

        const geladen = () => {
            if (dunkel && rahmen.contentDocument?.body) {
                aufhellen(rahmen.contentDocument);
            }

            einpassen(rahmen);
            nachgeladen?.removeEventListener('load', nachBild, true);
            nachgeladen = rahmen.contentDocument;
            nachgeladen?.addEventListener('load', nachBild, true);
        };

        const breite = new ResizeObserver(() => einpassen(rahmen));

        rahmen.addEventListener('load', geladen);
        breite.observe(rahmen);
        geladen();

        return () => {
            rahmen.removeEventListener('load', geladen);
            nachgeladen?.removeEventListener('load', nachBild, true);
            breite.disconnect();
        };
    }, [autoHeight, rahmen, dunkel]);

    return (
        <div className={autoHeight ? 'space-y-2' : cn('flex h-full flex-col gap-2', mobil && 'max-md:h-auto')}>
            {blocking && (
                <div
                    data-slot="mail-body-bilder-blockiert"
                    className="flex shrink-0 items-center justify-between gap-3 rounded-md border border-amber-300 bg-amber-50 px-3 py-2 text-xs text-amber-800 dark:border-amber-800 dark:bg-amber-950 dark:text-amber-200"
                >
                    <span>{labels.remoteBlocked}</span>
                    <button
                        type="button"
                        className="shrink-0 font-medium underline hover:no-underline"
                        onClick={() => bodyHtml !== null && freigeben(bodyHtml)}
                    >
                        {labels.loadImages}
                    </button>
                </div>
            )}

            {/* White frame with padding — so the content does not stick to the edge.
                Auf dem Telefon (`mobil`) ohne Kasten: nur ein schmaler Abstand. */}
            <div
                className={cn(
                    'overflow-hidden rounded-md p-4',
                    dunkel ? 'bg-transparent text-foreground' : 'bg-white text-black',
                    !autoHeight && 'min-h-0 flex-1',
                    // Unten kein Abstand: Die Mail endet, wo ihr Inhalt endet.
                    mobil && 'max-md:flex-none max-md:rounded-none max-md:px-3 max-md:pt-2 max-md:pb-0',
                )}
                style={{ colorScheme: dunkel ? 'dark' : 'light' }}
                data-slot="mail-body-flaeche"
            >
                {bodyHtml && autoHeight && (
                    <iframe
                        ref={iframeRef}
                        className="w-full border-0 bg-white"
                        style={{ height: `${iframeHeight}px` }}
                        sandbox="allow-same-origin"
                        title={labels.frameTitle}
                    />
                )}

                {bodyHtml && !autoHeight && (
                    <iframe
                        ref={setRahmen}
                        data-slot="mail-body-rahmen"
                        srcDoc={buildDocument(bodyHtml, blocking, dunkel)}
                        className={cn(
                            'h-full w-full border-0',
                            dunkel ? 'bg-transparent' : 'bg-white',
                            mobil && 'max-md:h-[var(--mail-rumpf-hoehe,100px)]',
                        )}
                        style={{ colorScheme: dunkel ? 'dark' : 'light' }}
                        sandbox="allow-same-origin"
                        title={labels.frameTitle}
                    />
                )}

                {!bodyHtml && bodyText && (
                    <pre
                        className={cn(
                            'h-full overflow-auto font-sans text-sm whitespace-pre-wrap',
                            dunkel ? 'text-foreground' : 'text-black',
                            mobil && 'max-md:h-auto max-md:overflow-visible',
                        )}
                    >
                        {bodyText}
                    </pre>
                )}

                {!bodyHtml && !bodyText && <p className="text-gray-500 italic">{labels.empty}</p>}
            </div>
        </div>
    );
}

export default EmailBodyViewer;
