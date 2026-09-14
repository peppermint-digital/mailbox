/**
 * The primitives the package's own views are built from.
 *
 * ## Why copies and not imports
 *
 * shadcn is not a library you depend on — it is source you copy into your app.
 * Both products that use this package have their own copies, and they are the
 * same components in different vintages: `button`, `input`, `label`, `select`,
 * `skeleton` and `separator` are byte-identical, `card` differs only in using
 * forwardRef, `badge` carries three extra variants in one of them (measured
 * 14.09.2026).
 *
 * The package could import them through an `@/components/ui/...` alias and
 * save these files. It would also stop being installable by anyone who does
 * not have our application — and that contradicts the point of shipping it.
 *
 * So: own copies, same look, self-contained. The cost is that a change to the
 * product's primitives does not reach the package by itself. That is the price
 * of a package that runs on its own, and it is the cheaper half of the trade.
 */
/*
 * Bewusst NICHT aus dem Paket-Einstieg heraus weiterexportiert: Wer im Produkt
 * einen Knopf braucht, nimmt seinen eigenen. Zwei Button-Fassungen auf
 * derselben Seite waeren genau die Doppelung, gegen die das Paket antritt —
 * hier sind sie nur Baumaterial der paketeigenen Ansichten.
 */
export * from './badge';
export * from './button';
export * from './input';
export * from './label';
export * from './select';
export { cn } from './utils';
export * from './checkbox';
