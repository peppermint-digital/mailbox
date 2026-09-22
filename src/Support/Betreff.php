<?php

namespace Peppermint\Mailbox\Support;

/**
 * Der Betreff, so wie ihn ein Mensch lesen will.
 *
 * ## Der Anlass
 *
 * In der Liste stand `Neue Nachricht von &quot;McPaper&quot;`. Nachgesehen am
 * 22.09.2026: Die Kopfzeile der Mail traegt diese Zeichen wirklich — ein
 * Versender hat den Betreff HTML-kodiert, obwohl eine MIME-Kopfzeile reiner
 * Text ist.
 *
 * Streng genommen zeigen wir damit richtig an, und Thunderbird und Outlook
 * machen es genauso. Nur hilft das niemandem: Wer die Liste liest, will
 * `Neue Nachricht von "McPaper"` sehen und nicht den Kodierfehler eines
 * fremden Systems.
 *
 * ## Warum das vertretbar ist
 *
 * Ein Betreff, der `&quot;` enthaelt, ist praktisch immer ein Fehler beim
 * Versender — Mail-Betreffs sind Text, kein Markup. Der denkbare Gegenfall
 * (jemand schreibt ueber HTML und meint die Zeichenfolge woertlich) ist so
 * selten, dass er den Preis nicht wert ist.
 *
 * Bewusst NUR der Betreff. Im Nachrichtenkoerper haetten dieselben Zeichen
 * eine Bedeutung, und dort zu entkodieren hiesse, fremdes Markup auszufuehren.
 */
class Betreff
{
    /**
     * Loest HTML-Entitaeten auf, wenn welche da sind.
     *
     * Der Kurzschluss ist kein Mikro-Optimieren, sondern Vorsicht: Was keine
     * Entitaet enthaelt, wird auch nicht angefasst.
     */
    public static function lesbar(?string $betreff): ?string
    {
        if ($betreff === null || $betreff === '') {
            return $betreff;
        }

        if (! str_contains($betreff, '&')) {
            return $betreff;
        }

        return html_entity_decode($betreff, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    }
}
