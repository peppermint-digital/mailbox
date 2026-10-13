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
