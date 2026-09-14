import { defineConfig } from 'vitest/config';

export default defineConfig({
    test: {
        // Die Signatur-Helfer arbeiten mit DOMParser — mit Regex bräche das am
        // ersten verschachtelten <div>, und eine Signatur ist genau solches HTML.
        environment: 'jsdom',
    },
});
