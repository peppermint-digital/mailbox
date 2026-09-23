<?php

namespace Peppermint\Mailbox\Content;

/**
 * Was ein Mensch in dieser Nachricht wirklich geschrieben hat.
 *
 * Eine E-Mail aus einem laufenden Austausch besteht zum grössten Teil aus
 * Dingen, die niemand geschrieben hat: der zitierte Vorgängertext, die
 * Signatur, der Haftungshinweis der Rechtsabteilung, „Von meinem iPhone
 * gesendet". Beim zehnten Hin und Her steht der eine neue Satz am Anfang von
 * achtzig Zeilen Wiederholung.
 *
 * Diese Klasse trennt beides. Sie wirft nichts weg — sie sortiert.
 *
 * ## Warum nichts verloren gehen darf
 *
 * Die Erkennung ist Heuristik, und Heuristik irrt. Ein Absender, der ohne
 * Trennzeichen signiert, ein Zitat ohne Einrückung, eine Grussformel mitten im
 * Text: Jede Regel hier hat einen Fall, in dem sie danebengreift.
 *
 * Deshalb gibt jede Methode ihren Teil zurück statt ihn zu löschen. Wer den
 * Verlauf anzeigt, zeigt {@see inhalt()} — und hat {@see zitat()},
 * {@see signatur()} und {@see fusszeile()} eine Handbewegung weit daneben.
 * Greift die Erkennung daneben, sieht man es sofort und kann aufklappen;
 * schnitte sie weg, wäre ein fehlender Satz nicht von einem nie geschriebenen
 * zu unterscheiden.
 *
 * ## Die Reihenfolge ist Absicht
 *
 * Erst das Zitat, dann die Signatur, dann die Fusszeile — von der
 * zuverlässigsten Regel zur unsichersten:
 *
 * 1. **Zitatzeichen** (`>`) und **Einleitungszeilen** („Am … schrieb …") sind
 *    fast beweisbar. Ab dort ist alles Vergangenheit.
 * 2. **`-- `** ist in RFC 3676 als Signaturtrenner festgeschrieben. Wo es
 *    steht, ist die Sache eindeutig.
 * 3. **Ohne Trenner** bleibt die Grussformel als Anker — und selbst die gilt
 *    nur, wenn danach wirklich nach Kontaktdaten aussehendes Zeug kommt und
 *    nicht noch ein Absatz Inhalt.
 *
 * Eine unsichere Regel zuerst laufen zu lassen hiesse, den sicheren Regeln den
 * Text wegzunehmen, bevor sie ihn sehen.
 */
final class Gespraechstext
{
    /**
     * Zeilen, ab denen der zitierte Vorgängertext beginnt.
     *
     * Bewusst als Muster auf die GANZE Zeile: „Am Montag schrieb er mir, dass"
     * mitten im Fliesstext ist kein Zitatbeginn. Die echten Einleitungen
     * stehen allein auf einer Zeile und enden auf einen Doppelpunkt.
     */
    private const ZITAT_EINLEITUNG = [
        // Deutsch: „Am 12.09.2026 um 14:33 schrieb Max Mustermann:"
        '/^\s*Am\s.{0,120}\sschrieb\s.{0,120}:\s*$/iu',
        // Englisch: „On Fri, 12 Sep 2026 at 14:33, Max wrote:"
        '/^\s*On\s.{0,120}\swrote:\s*$/iu',
        // Outlook, beide Sprachen
        '/^\s*-{2,}\s*(Urspr(ü|ue)ngliche Nachricht|Original Message|Weitergeleitete Nachricht|Forwarded message)\s*-{2,}\s*$/iu',
        '/^\s*_{5,}\s*$/u',
        // Der Outlook-Kopfblock, der ohne Trennlinie kommt
        '/^\s*Von:\s.{1,200}$/iu',
        '/^\s*From:\s.{1,200}$/iu',
        '/^\s*Gesendet von (Mail|Outlook) f(ü|ue)r .{0,60}$/iu',
    ];

    /**
     * Zeilen, ab denen eine Fusszeile beginnt — Pflichtangaben, Haftung, Umwelt.
     *
     * Diese stehen NACH der Signatur, oft durch nichts davon getrennt. Sie
     * eigens zu erkennen lohnt sich, weil sie die längsten Blöcke sind und in
     * jeder Nachricht identisch wiederkehren.
     */
    private const FUSSZEILE_BEGINN = [
        '/^\s*Diese (E-?Mail|Nachricht) (enth(ä|ae)lt|kann)\b/iu',
        '/^\s*This (e-?mail|message) (and any|is intended|contains)\b/iu',
        '/^\s*Der Inhalt dieser E-?Mail\b/iu',
        '/^\s*Bitte denken Sie an die Umwelt\b/iu',
        '/^\s*Please consider the environment\b/iu',
        '/^\s*Vertraulichkeitshinweis\b/iu',
        '/^\s*Confidentiality (Notice|Note)\b/iu',
        '/^\s*Registergericht:\s/iu',
        '/^\s*Amtsgericht\s.{0,60}\bHRB\b/iu',
        '/^\s*USt-?IdNr\.?:?\s/iu',
    ];

    /**
     * Grussformeln — der Anker für eine Signatur ohne Trennzeichen.
     */
    private const GRUSSFORMEL = [
        '/^\s*(Mit )?(freundlichen|besten|sonnigen|herzlichen|lieben) Gr(ü|ue)(ß|ss)en\b/iu',
        '/^\s*(Viele|Beste|Liebe|Herzliche|Sonnige) Gr(ü|ue)(ß|ss)e\b/iu',
        '/^\s*(VG|MfG|LG|BG)\s*[,.!]?\s*$/iu',
        '/^\s*(Best|Kind|Warm) regards\b/iu',
        '/^\s*(Cheers|Thanks|Thank you|Regards|Sincerely)\s*[,.!]?\s*$/iu',
    ];

    /**
     * Zeilen, die für sich genommen schon eine Signatur sind.
     */
    private const MOBILSIGNATUR = [
        '/^\s*(Von|Gesendet von) meinem\s.{0,40}(gesendet)?\s*$/iu',
        '/^\s*Sent from my\s.{0,40}$/iu',
        '/^\s*Get Outlook for\s.{0,20}$/iu',
    ];

    private function __construct(
        private readonly string $inhalt,
        private readonly ?string $zitat,
        private readonly ?string $signatur,
        private readonly ?string $fusszeile,
    ) {}

    /**
     * Aus dem Klartext-Teil einer Nachricht.
     */
    public static function aus(?string $text): self
    {
        $zeilen = self::zeilen((string) $text);

        [$zeilen, $zitat] = self::abtrennen($zeilen, fn (string $z): bool => self::istZitatbeginn($z));
        [$zeilen, $fusszeile] = self::abtrennen($zeilen, fn (string $z): bool => self::passt($z, self::FUSSZEILE_BEGINN));
        [$zeilen, $signatur] = self::signaturAbtrennen($zeilen);

        return new self(
            self::zusammen($zeilen) ?? '',
            self::zusammen($zitat),
            self::zusammen($signatur),
            self::zusammen($fusszeile),
        );
    }

    /**
     * Aus einer HTML-Nachricht.
     *
     * Der Umweg über Text ist Absicht und kein Notbehelf: Der Verlauf zeigt
     * Text. Wer HTML mitschleppte, müsste jede fremde Formatierung anzeigen
     * oder filtern — und ein zitierter `<blockquote>` sieht dann aus wie ein
     * eigener Absatz.
     */
    public static function ausHtml(?string $html): self
    {
        return self::aus(self::htmlZuText((string) $html));
    }

    /** Was die Person geschrieben hat. */
    public function inhalt(): string
    {
        return $this->inhalt;
    }

    /** Der zitierte Vorgängertext, falls einer erkannt wurde. */
    public function zitat(): ?string
    {
        return $this->zitat;
    }

    public function signatur(): ?string
    {
        return $this->signatur;
    }

    public function fusszeile(): ?string
    {
        return $this->fusszeile;
    }

    /**
     * Nichts übrig — etwa bei einer Antwort, die nur aus „+1" bestand und
     * deren Zeile fälschlich als Grussformel gelesen wurde.
     *
     * Wer das prüft, zeigt im Zweifel lieber den ganzen Text als eine leere
     * Blase im Verlauf.
     */
    public function istLeer(): bool
    {
        return trim($this->inhalt) === '';
    }

    /**
     * Alles wieder zusammen — in der Reihenfolge, in der es stand.
     *
     * Die Gegenprobe zur Trennung: Was hier herauskommt, muss den Eingang
     * ergeben. Ohne diese Methode wäre „geht nichts verloren?" eine Behauptung
     * statt einer Messung.
     */
    public function vollstaendig(): string
    {
        return implode("\n\n", array_filter([
            $this->inhalt,
            $this->signatur,
            $this->fusszeile,
            $this->zitat,
        ], fn (?string $t): bool => $t !== null && trim($t) !== ''));
    }

    /**
     * @return list<string>
     */
    private static function zeilen(string $text): array
    {
        $text = str_replace(["\r\n", "\r"], "\n", $text);

        return explode("\n", $text);
    }

    /**
     * @param  list<string>  $zeilen
     * @return array{0: list<string>, 1: list<string>}
     */
    private static function abtrennen(array $zeilen, callable $beginnt): array
    {
        foreach ($zeilen as $i => $zeile) {
            if ($beginnt($zeile)) {
                return [array_slice($zeilen, 0, $i), array_slice($zeilen, $i)];
            }
        }

        return [$zeilen, []];
    }

    /**
     * Zitatbeginn: eine Einleitungszeile — oder der erste Block aus `>`-Zeilen.
     *
     * Das `>` allein genügt nicht als Bedingung für die Einleitungssuche, denn
     * manche Programme zitieren OHNE Zeichen und nur mit Einleitung, andere
     * mit Zeichen und ohne Einleitung. Beides muss greifen.
     */
    private static function istZitatbeginn(string $zeile): bool
    {
        return str_starts_with(ltrim($zeile), '>')
            || self::passt($zeile, self::ZITAT_EINLEITUNG);
    }

    /**
     * Die Signatur — erst am Trennzeichen, sonst an der Grussformel.
     *
     * @param  list<string>  $zeilen
     * @return array{0: list<string>, 1: list<string>}
     */
    private static function signaturAbtrennen(array $zeilen): array
    {
        // RFC 3676: „-- " allein auf einer Zeile. Manche Programme schlucken
        // das Leerzeichen, deshalb auch „--" akzeptieren — aber nur, wenn
        // sonst nichts auf der Zeile steht.
        foreach ($zeilen as $i => $zeile) {
            if (rtrim($zeile) === '--' || $zeile === '-- ') {
                return [array_slice($zeilen, 0, $i), array_slice($zeilen, $i)];
            }
        }

        foreach ($zeilen as $i => $zeile) {
            if (self::passt($zeile, self::MOBILSIGNATUR)) {
                return [array_slice($zeilen, 0, $i), array_slice($zeilen, $i)];
            }
        }

        return self::anGrussformel($zeilen);
    }

    /**
     * Die vorsichtigste Regel im Haus.
     *
     * Eine Grussformel trennt nur dann ab, wenn danach höchstens acht Zeilen
     * kommen und keine davon nach einem Satz aussieht. „Viele Grüße" mitten im
     * Text — „Viele Grüße an deine Frau, und noch etwas: …" — darf den Rest der
     * Nachricht nicht verschlucken.
     *
     * @param  list<string>  $zeilen
     * @return array{0: list<string>, 1: list<string>}
     */
    private static function anGrussformel(array $zeilen): array
    {
        foreach ($zeilen as $i => $zeile) {
            if (! self::passt($zeile, self::GRUSSFORMEL)) {
                continue;
            }

            // Eine Grussformel STEHT für sich. „Viele Grüße an deine Frau, sag
            // ihr danke für gestern" beginnt mit denselben zwei Wörtern und
            // ist trotzdem ein Satz mitten im Text — wer daran abschneidet,
            // verschluckt alles, was danach noch kommt.
            //
            // Fünf Wörter lassen „Viele Grüße, Luka" und „Beste Grüße aus
            // Hamburg" durch und halten den Satz draussen. Die Grenze ist
            // grob; sie darf es sein, weil die Prüfung darunter den Rest
            // abfängt.
            if (self::woerter($zeile) > 5) {
                continue;
            }

            $danach = array_values(array_filter(
                array_slice($zeilen, $i + 1),
                fn (string $z): bool => trim($z) !== ''
            ));

            if (count($danach) > 8) {
                continue;
            }

            foreach ($danach as $rest) {
                if (self::siehtNachSatzAus($rest)) {
                    continue 2;
                }
            }

            return [array_slice($zeilen, 0, $i), array_slice($zeilen, $i)];
        }

        return [$zeilen, []];
    }

    /**
     * Sieht die Zeile nach einem Satz aus statt nach Kontaktdaten?
     *
     * Kontaktzeilen sind kurz und bestehen aus Namen, Nummern, Adressen. Ein
     * Satz hat Länge und Satzzeichen. Die Grenze bei zwölf Wörtern ist grob —
     * sie muss nur den Fall auffangen, dass nach der Grussformel noch ein
     * Absatz Inhalt kommt.
     */
    private static function siehtNachSatzAus(string $zeile): bool
    {
        $zeile = trim($zeile);

        return self::woerter($zeile) > 12
            || (str_contains($zeile, '. ') && mb_strlen($zeile) > 60);
    }

    /**
     * Wörter zählen, mit Umlauten als Buchstaben.
     *
     * Ohne die Zeichenliste zerlegt `str_word_count` „Grüße" in „Gr" und „e"
     * und zählt zwei Wörter statt einem — jede Längengrenze hier wäre dann
     * lautlos falsch.
     */
    private static function woerter(string $zeile): int
    {
        return str_word_count(trim($zeile), 0, 'äöüÄÖÜßáàâéèêíóôúñç0123456789@.-');
    }

    /**
     * @param  list<string>  $muster
     */
    private static function passt(string $zeile, array $muster): bool
    {
        foreach ($muster as $m) {
            if (preg_match($m, $zeile) === 1) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  list<string>  $zeilen
     */
    private static function zusammen(array $zeilen): ?string
    {
        $text = trim(implode("\n", $zeilen));

        // Mehr als eine Leerzeile hintereinander trägt nichts und macht den
        // Verlauf luftig bis zur Unlesbarkeit.
        $text = (string) preg_replace("/\n{3,}/", "\n\n", $text);

        return $text === '' ? null : $text;
    }

    /**
     * HTML zu Text — gerade so viel, wie der Verlauf braucht.
     *
     * Absätze und Zeilenumbrüche überleben, alles andere fällt weg. Ein
     * vollwertiger Wandler wäre eine weitere Abhängigkeit für ein Ergebnis,
     * das hier ohnehin nur gelesen und nicht wieder ausgegeben wird.
     */
    private static function htmlZuText(string $html): string
    {
        // Was gar nicht erst als Text gelten darf.
        $html = (string) preg_replace('#<(script|style|head)\b[^>]*>.*?</\1>#is', '', $html);

        // Blockenden werden zu Zeilenumbrüchen, sonst klebt der ganze Text
        // in einer einzigen Zeile und jede zeilenweise Regel oben läuft leer.
        $html = (string) preg_replace('#<br\s*/?>#i', "\n", $html);
        $html = (string) preg_replace('#</(p|div|tr|li|h[1-6]|blockquote)>#i', "\n\n", $html);

        $text = html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8');

        // Geschütztes Leerzeichen: sieht aus wie ein Leerzeichen, ist aber
        // keins — `trim()` und jedes `\s`-Muster gehen daran vorbei.
        $text = str_replace("\u{00A0}", ' ', $text);

        return (string) preg_replace("/[ \t]+\n/", "\n", $text);
    }
}
