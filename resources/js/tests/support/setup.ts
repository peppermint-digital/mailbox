/**
 * Was jsdom nicht mitbringt.
 *
 * `ResizeObserver` gibt es im Browser, in jsdom nicht. Komponenten, die eine
 * Höhe mitwachsen lassen (der Nachrichten-Rahmen tut das), stürzen sonst beim
 * Zeichnen ab — und der Test meldet einen Fehler der Umgebung als Fehler des
 * Pakets.
 */
if (!('ResizeObserver' in globalThis)) {
    (globalThis as Record<string, unknown>).ResizeObserver = class {
        observe() {}
        unobserve() {}
        disconnect() {}
    };
}

/**
 * `getClientRects` auf einem Range — ProseMirror braucht es, jsdom hat es nicht.
 *
 * Aufgefallen am 31.10.2026 bei den ersten Tests mit einem ECHTEN Editor
 * (`editorKnoten`, `useSchreibhilfe`). Jede Aenderung, die den Cursor
 * mitnimmt (`chain().focus()`), laesst ProseMirror zur Auswahl scrollen, und
 * dafuer fragt es die Lage des Ranges. In jsdom gibt es die Methode nicht, und
 * der Lauf meldete vier Fehler der UMGEBUNG neben vier gruenen Tests — was
 * schlimmer ist als ein roter Test, weil es wie ein Fehler des Pakets aussieht.
 *
 * Eine leere Liste ist die ehrliche Antwort: In jsdom hat nichts eine Lage.
 * Was von der Lage abhaengt (wohin gescrollt wird), ist damit nicht geprueft —
 * das gehoert in die Browsermessung und nicht hierhin.
 */
if (typeof Range !== 'undefined' && !Range.prototype.getClientRects) {
    Range.prototype.getClientRects = () => ({ length: 0, item: () => null, [Symbol.iterator]: function* () {} }) as unknown as DOMRectList;
    Range.prototype.getBoundingClientRect = () => ({ x: 0, y: 0, width: 0, height: 0, top: 0, right: 0, bottom: 0, left: 0, toJSON: () => ({}) }) as DOMRect;
}
