import { defineConfig } from 'tsup';

export default defineConfig({
    entry: ['resources/js/src/index.ts'],
    format: ['esm'],
    dts: true,
    clean: true,
    // React bleibt beim Produkt: Zwei React-Instanzen im selben Baum enden in
    // „Invalid hook call", und das findet niemand schnell.
    external: ['react', 'react-dom'],
});
