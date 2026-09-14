import { defineConfig } from 'tsup';

export default defineConfig({
    entry: ['resources/js/src/index.ts', 'resources/js/src/inertia/index.ts'],
    format: ['esm'],
    dts: true,
    clean: true,
    // React bleibt beim Produkt: Zwei React-Instanzen im selben Baum enden in
    // „Invalid hook call", und das findet niemand schnell.
    // Inertia bleibt beim Produkt und ist optional: Der Grundeinstieg kommt ohne
    // aus, `/inertia` verlangt es. Zwei React- oder Inertia-Instanzen im selben
    // Baum enden in „Invalid hook call", und das findet niemand schnell.
    external: ['react', 'react-dom', '@inertiajs/react'],
});
