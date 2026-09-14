import { act } from 'react';
import { createRoot, type Root } from 'react-dom/client';
import type { ReactElement } from 'react';

/**
 * Render a component into jsdom and hand back its container.
 *
 * Deliberately without a testing library: the package has react-dom as a
 * peer dependency anyway, and what these tests assert is "is this element
 * there, does that one stay away" — questions the DOM answers directly.
 */
export function render(element: ReactElement): { container: HTMLElement; unmount: () => void; rerender: (next: ReactElement) => void } {
    const container = document.createElement('div');
    document.body.appendChild(container);

    let root: Root;

    act(() => {
        root = createRoot(container);
        root.render(element);
    });

    return {
        container,
        rerender(next: ReactElement) {
            act(() => {
                root.render(next);
            });
        },
        unmount() {
            act(() => {
                root.unmount();
            });
            container.remove();
        },
    };
}

/** Text content of the container, whitespace collapsed — for "does it say X". */
export function textOf(container: HTMLElement): string {
    return (container.textContent ?? '').replace(/\s+/g, ' ').trim();
}

/** Click an element and let React settle. */
export function click(element: Element): void {
    act(() => {
        element.dispatchEvent(new MouseEvent('click', { bubbles: true }));
    });
}
