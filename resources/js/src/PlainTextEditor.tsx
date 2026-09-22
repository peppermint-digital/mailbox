import { Textarea } from './ui/textarea';

/**
 * Der Rueckfall-Editor: schreiben wie in einer Notiz, HTML entsteht daraus.
 *
 * ## Warum es ihn gibt
 *
 * `EmailComposerForm` verlangt einen Editor vom Produkt — und das ist richtig
 * so: Wer einen guten hat, soll ihn benutzen. Nur haben ihn nicht alle.
 *
 * Am 22.09.2026 bekam die Verwaltung deshalb ihren Vorlagen-Editor
 * untergeschoben, und der sagte dem Benutzer: „Verwenden Sie HTML für die
 * Formatierung. Tipp: Sie können HTML-Tags wie `<h1>`, `<p>`, `<table>`
 * verwenden." Das ist fuer eine Belegvorlage richtig und fuer eine E-Mail
 * falsch — niemand tippt Tabellen-Auszeichnung in eine Antwort an einen
 * Kunden.
 *
 * Also lieber weniger, aber verstaendlich: ein Textfeld. Absaetze werden zu
 * `<p>`, Zeilenumbrueche zu `<br>`, und wer Fettdruck braucht, nimmt das
 * Produkt, das einen richtigen Editor mitbringt.
 *
 * ## Warum umgekehrt gerechnet wird
 *
 * Der Wert, der hinein- und hinausgeht, ist HTML — das verlangt das Formular.
 * Angezeigt wird Text. Beim Tippen wird also aus Text HTML, und beim
 * Anzeigen aus HTML wieder Text. Der Weg zurueck ist verlustbehaftet: Was ein
 * anderer Editor an Auszeichnung hinterlassen hat, ueberlebt ihn nicht.
 *
 * Das ist hier unkritisch, weil dieser Editor nur dort steht, wo es keinen
 * anderen gibt. Es waere kritisch, wenn jemand ihn NEBEN einen richtigen
 * setzt — dann friesse das Umschalten die Formatierung. Deshalb steht es hier.
 */
export interface PlainTextEditorProps {
    value: string;
    onChange: (value: string) => void;
    placeholder?: string;
    rows?: number;
}

export function PlainTextEditor({ value, onChange, placeholder, rows = 14 }: PlainTextEditorProps) {
    return (
        <Textarea
            value={htmlZuText(value)}
            rows={rows}
            placeholder={placeholder}
            className="min-h-[18rem] font-sans text-sm"
            data-slot="plain-text-editor"
            onChange={(e) => onChange(textZuHtml(e.target.value))}
        />
    );
}

/** Absaetze an Leerzeilen, Zeilenumbrueche als `<br>`. */
export function textZuHtml(text: string): string {
    const sicher = (s: string) =>
        s.replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;');

    return text
        .split(/\n{2,}/)
        .map((absatz) => absatz.trim())
        .filter((absatz) => absatz !== '')
        .map((absatz) => `<p>${sicher(absatz).replace(/\n/g, '<br>')}</p>`)
        .join('\n');
}

/** Der Weg zurueck — verlustbehaftet, siehe oben. */
export function htmlZuText(html: string): string {
    if (html === '') {
        return '';
    }

    return html
        .replace(/<br\s*\/?>/gi, '\n')
        .replace(/<\/p>\s*<p[^>]*>/gi, '\n\n')
        .replace(/<[^>]+>/g, '')
        .replace(/&lt;/g, '<')
        .replace(/&gt;/g, '>')
        .replace(/&quot;/g, '"')
        .replace(/&amp;/g, '&')
        .trim();
}
