import { useEffect, useMemo, useRef, useState } from 'react';

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
}

function buildDocument(html: string, blocking: boolean): string {
    // Block remote sources (tracking pixels), allow embedded ones (data:).
    const csp = blocking
        ? `<meta http-equiv="Content-Security-Policy" content="default-src 'none'; img-src data:; style-src 'unsafe-inline'; font-src data:;">`
        : '';

    return `<!DOCTYPE html>
<html style="color-scheme: light;">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="color-scheme" content="light">
${csp}
<style>
    :root { color-scheme: light; }
    html, body {
        background: #ffffff;
        color: #1a1a1a;
    }
    body {
        font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, 'Helvetica Neue', Arial, sans-serif;
        font-size: 14px;
        line-height: 1.5;
        margin: 0;
        padding: 0;
        overflow-wrap: break-word;
        word-wrap: break-word;
    }
    img { max-width: 100%; height: auto; }
    a { color: #2563eb; }
    table { max-width: 100%; border-collapse: collapse; }
    td, th { padding: 4px 8px; }
</style>
</head>
<body>${html}</body>
</html>`;
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
}: EmailBodyViewerProps) {
    const iframeRef = useRef<HTMLIFrameElement | null>(null);
    const [iframeHeight, setIframeHeight] = useState(minHeight);

    /**
     * Which body the permission applies to. A plain yes/no would travel to the
     * next mail and load its tracking pixels.
     */
    const [allowedFor, setAllowedFor] = useState<string | null>(null);

    const hasRemoteContent = useMemo(() => {
        const html = bodyHtml ?? '';

        return REMOTE_ATTRIBUTE.test(html) || REMOTE_CSS_URL.test(html);
    }, [bodyHtml]);

    const blocking = blockRemoteImages && hasRemoteContent && allowedFor !== bodyHtml;

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

    return (
        <div className={autoHeight ? 'space-y-2' : 'flex h-full flex-col gap-2'}>
            {blocking && (
                <div className="flex shrink-0 items-center justify-between gap-3 rounded-md border border-amber-300 bg-amber-50 px-3 py-2 text-xs text-amber-800 dark:border-amber-800 dark:bg-amber-950 dark:text-amber-200">
                    <span>{labels.remoteBlocked}</span>
                    <button
                        type="button"
                        className="shrink-0 font-medium underline hover:no-underline"
                        onClick={() => setAllowedFor(bodyHtml ?? null)}
                    >
                        {labels.loadImages}
                    </button>
                </div>
            )}

            {/* White frame with padding — so the content does not stick to the edge. */}
            <div
                className={`overflow-hidden rounded-md bg-white p-4 text-black ${autoHeight ? '' : 'min-h-0 flex-1'}`}
                style={{ colorScheme: 'light' }}
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
                        srcDoc={buildDocument(bodyHtml, blocking)}
                        className="h-full w-full border-0 bg-white"
                        sandbox="allow-same-origin"
                        title={labels.frameTitle}
                    />
                )}

                {!bodyHtml && bodyText && <pre className="h-full overflow-auto font-sans text-sm whitespace-pre-wrap text-black">{bodyText}</pre>}

                {!bodyHtml && !bodyText && <p className="text-gray-500 italic">{labels.empty}</p>}
            </div>
        </div>
    );
}

export default EmailBodyViewer;
