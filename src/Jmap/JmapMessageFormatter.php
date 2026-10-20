<?php

namespace Peppermint\Mailbox\Jmap;

use Illuminate\Support\Carbon;
use Peppermint\Mailbox\Content\CidReplacer;
use Peppermint\Mailbox\Imap\MessageFormatter;
use Peppermint\Mailbox\Support\Betreff;
use Peppermint\Mailbox\Threading\ThreadKey;

/**
 * A JMAP message, in the rows the rest of the package already speaks.
 *
 * The shape is not negotiable and not derived from JMAP: it is what the IMAP
 * MessageFormatter produces, because every consumer above — threading, paging, deduplication, the React views — was
 * written against those keys. A transport that returns its own idea of a row
 * is a second product, not a second transport. TransportContractTest holds the
 * two side by side.
 *
 * ## What JMAP gives for free, and why that changes nothing here
 *
 * The server can thread, sort and count. It still has to answer in these keys,
 * so that a product does not have to ask which transport it is on before it
 * reads a field.
 *
 * ## Inline images
 *
 * Not „the same rule as IMAP" — literally the same rule:
 * {@see MessageFormatter::isEmbeddedImage()} is asked here too. A second
 * spelling of the same sentence is what made Bug #1023 a JMAP-only fault for
 * weeks. A part keeps its position in the list either way, so that attachment
 * number two on screen is attachment number two when someone files it.
 *
 * What JMAP adds is a way to fail that IMAP does not have: the bytes of an
 * inline image arrive in a separate blob request, which can come back empty.
 * {@see full()} spells out what happens then.
 */
class JmapMessageFormatter
{
    public function __construct(
        private readonly CidReplacer $cids = new CidReplacer,
    ) {}

    /**
     * For a list view: headers and a short preview.
     *
     * @param  array<string, mixed>  $email
     * @return array<string, mixed>
     */
    public function summary(array $email): array
    {
        $from = $email['from'][0] ?? null;

        /*
         * Beide Zahlen aus DERSELBEN Quelle — nicht die Klammer vom Server und
         * die Zahl von hier. `hasAttachment` des Servers ist schon wahr, wenn
         * eine Mail nur ein eingebettetes Logo traegt: Die Liste zeigte dann
         * eine Klammer, und die geoeffnete Nachricht hatte keinen Anhang.
         */
        $anzahl = count($this->visibleAttachments($email));

        return [
            'uid' => $email['id'] ?? null,
            'message_id' => $this->firstMessageId($email['messageId'] ?? null),
            'subject' => Betreff::lesbar($email['subject'] ?? null),
            'from_address' => $from['email'] ?? '',
            'from_name' => $from['name'] ?? '',
            'date' => $this->date($email),
            'has_attachments' => $anzahl > 0,
            'attachment_count' => $anzahl,
            'is_read' => $this->hasKeyword($email, '$seen'),
            'is_flagged' => $this->hasKeyword($email, '$flagged'),
            // JMAP delivers a preview the server already cut. Cutting it again
            // to the same length is what keeps the two transports comparable:
            // a list must not change its line length when a mailbox moves.
            /*
             * Leer, obwohl JMAP die Vorschau GRATIS mitliefert (#6119).
             *
             * Das ist die Regel, die der Vertragstest fuer die billige
             * Dreiergruppe schon festhaelt: „also gibt die Dreiergruppe die
             * Schnittmenge heraus, nicht das Maximum." Fuer die Listenzeile
             * galt sie nur nie.
             *
             * Ueber IMAP kostet eine Vorschau einen Rumpfabruf PRO ZEILE — an
             * einem echten Postfach gemessen 25 Rundreisen und knapp acht
             * Sekunden je Seite. Hier das Feld zu fuellen und dort nicht hiesse:
             * Ein Produkt, das es zeichnet, zeigt Vorschauen nur an
             * JMAP-Postfaechern. Eine halb vorhandene Spalte ist schlechter als
             * keine — man sieht ihr nicht an, dass sie vom Protokoll abhaengt.
             *
             * Wer Vorschauen will, holt sie auf BEIDEN Wegen: ueber IMAP mit
             * einem gebuendelten Rumpfabruf fuer die sichtbare Seite.
             */
            'preview' => '',
            'in_reply_to' => $this->firstMessageId($email['inReplyTo'] ?? null),
            'references' => $this->joined($email['references'] ?? null),
        ];
    }

    /**
     * For reading one message: recipients, bodies, attachments.
     *
     * @param  array<string, mixed>  $email
     * @param  callable(string, string, string): ?string  $blob  fetches an attachment's bytes
     * @return array<string, mixed>
     */
    public function full(array $email, callable $blob): array
    {
        $attachments = [];
        $inline = [];

        foreach ($this->parts($email) as $index => $part) {
            $contentId = $part['cid'] ?? null;
            $type = (string) ($part['type'] ?? '');

            if (MessageFormatter::isEmbeddedImage($contentId, $part['disposition'] ?? null, $type)) {
                $bytes = $blob((string) ($part['blobId'] ?? ''), (string) ($part['name'] ?? 'inline'), $type);

                if ($bytes !== null) {
                    $inline[$contentId] = 'data:'.$type.';base64,'.base64_encode($bytes);

                    continue;
                }

                /*
                 * Keine Bytes — und damit faellt der Teil NICHT aus der Liste.
                 *
                 * Uebersprungen wird nur, was wirklich im Rumpf steht. Das
                 * `cid:` bleibt hier unersetzt, und die Anzeige laesst per CSP
                 * ausschliesslich `data:` als Bildquelle zu: Das Bild ist also
                 * kaputt, egal was wir tun. Dann ist eine Zeile, deren
                 * Download scheitert, ehrlicher als eine Datei, die es
                 * scheinbar nie gegeben hat (Bug #1023).
                 */
            }

            $attachments[] = [
                'index' => $index,
                'filename' => $part['name'] ?? 'attachment',
                'mime_type' => $part['type'] ?? null,
                'size' => (int) ($part['size'] ?? 0),
            ];
        }

        $html = $this->body($email, 'htmlBody');

        if ($html && $inline !== []) {
            $html = $this->cids->replace($html, $inline);
        }

        $from = $email['from'][0] ?? null;

        return [
            'uid' => $email['id'] ?? null,
            'message_id' => $this->firstMessageId($email['messageId'] ?? null),
            'subject' => Betreff::lesbar($email['subject'] ?? null),
            'from_address' => $from['email'] ?? '',
            'from_name' => $from['name'] ?? '',
            'to' => $this->addresses($email['to'] ?? []),
            'cc' => $this->addresses($email['cc'] ?? []),
            'date' => $this->date($email),
            'body_html' => $html,
            'body_text' => $this->body($email, 'textBody'),
            'attachments' => $attachments,
            'is_read' => $this->hasKeyword($email, '$seen'),
            'is_flagged' => $this->hasKeyword($email, '$flagged'),
            'in_reply_to' => $this->firstMessageId($email['inReplyTo'] ?? null),
            'references' => $this->joined($email['references'] ?? null),
            /*
             * Welche Unterhaltung das ist — aus den Kopfzeilen gerechnet, die
             * hier ohnehin schon stehen.
             *
             * Die Listenzeile traegt das laengst; die geoeffnete Nachricht
             * nicht. Aufgefallen ist es erst, als etwas davon abhing: Der
             * Gespraechsverlauf fand nie eine Kette und zeigte seinen
             * Umschalter deshalb nirgends. Kein Fehler, keine Meldung — nur
             * eine Ansicht, die es scheinbar nicht gibt.
             */
            'thread_id' => ThreadKey::fromHeaders(
                $this->firstMessageId($email['messageId'] ?? null),
                $this->firstMessageId($email['inReplyTo'] ?? null),
                $this->joined($email['references'] ?? null),
            ),
        ];
    }

    /**
     * The part at this position, or null when it is one of the inline ones.
     *
     * Deliberately the same arithmetic as the view: the index counts every
     * part the message has, inline images included. Counting only the visible
     * ones here would drift apart from the list as soon as a mail carries a
     * logo — and then someone files the logo instead of the invoice.
     *
     * @param  array<string, mixed>  $email
     * @return array<string, mixed>|null
     */
    public function partAt(array $email, int $index): ?array
    {
        foreach ($this->parts($email) as $i => $part) {
            if ($i !== $index) {
                continue;
            }

            if (MessageFormatter::isEmbeddedImage($part['cid'] ?? null, $part['disposition'] ?? null, $part['type'] ?? null)) {
                return null;
            }

            return $part;
        }

        return null;
    }

    /**
     * Every part that is not the body, in document order.
     *
     * @param  array<string, mixed>  $email
     * @return list<array<string, mixed>>
     */
    private function parts(array $email): array
    {
        return array_values($email['attachments'] ?? []);
    }

    /**
     * The same message, but for keeping: body untouched, every file separate.
     *
     * @param  array<string, mixed>  $email
     * @param  callable(string, string, string): ?string  $blob  fetches a part's bytes
     * @return array<string, mixed>
     */
    public function verbatim(array $email, callable $blob): array
    {
        $from = $email['from'][0] ?? null;
        $files = [];

        foreach ($this->parts($email) as $index => $part) {
            $type = (string) ($part['type'] ?? '');
            $name = (string) ($part['name'] ?? 'attachment');
            $inhalt = $blob((string) ($part['blobId'] ?? ''), $name, $type);

            if ($inhalt === null) {
                // Ein Teil, dessen Bytes der Server nicht herausgibt, gehoert
                // nicht als leere Datei ins Archiv — dort saehe er aus wie ein
                // Anhang, den jemand geleert hat.
                continue;
            }

            $cid = $part['cid'] ?? null;

            $files[] = [
                'index' => $index,
                'filename' => $name,
                'mime_type' => $part['type'] ?? null,
                'extension' => self::extension($name),
                'contents' => $inhalt,
                'size' => strlen($inhalt),
                'content_id' => $cid,
                'inline' => MessageFormatter::isEmbeddedImage($cid, $part['disposition'] ?? null, $part['type'] ?? null),
            ];
        }

        return [
            'uid' => $email['id'] ?? null,
            'message_id' => $this->firstMessageId($email['messageId'] ?? null),
            'subject' => Betreff::lesbar($email['subject'] ?? null),
            'from_address' => $from['email'] ?? '',
            'from_name' => $from['name'] ?? null,
            'to' => $this->addresses($email['to'] ?? []),
            'cc' => $this->addresses($email['cc'] ?? []),
            'date' => $this->date($email),
            // Roh, mit cid: — genau der Unterschied zu full().
            'body_html' => $this->body($email, 'htmlBody'),
            'body_text' => $this->body($email, 'textBody'),
            'files' => $files,
            'is_read' => $this->hasKeyword($email, '$seen'),
            'is_flagged' => $this->hasKeyword($email, '$flagged'),
            'in_reply_to' => $this->firstMessageId($email['inReplyTo'] ?? null),
            'references' => $this->joined($email['references'] ?? null),
        ];
    }

    /** Die Endung aus dem Dateinamen — das Einzige, was JMAP dazu hergibt. */
    private static function extension(string $name): ?string
    {
        $endung = pathinfo($name, PATHINFO_EXTENSION);

        return $endung === '' ? null : $endung;
    }

    /** @param array<string, mixed> $email */
    private function visibleAttachments(array $email): array
    {
        return array_filter(
            $this->parts($email),
            fn (array $part): bool => ! MessageFormatter::isEmbeddedImage($part['cid'] ?? null, $part['disposition'] ?? null, $part['type'] ?? null),
        );
    }

    /**
     * The body text of one kind, assembled from the values the server sent.
     *
     * Null rather than an empty string when there is none: a reader has to be
     * able to tell "no HTML part" from "an HTML part that is empty".
     *
     * @param  array<string, mixed>  $email
     */
    private function body(array $email, string $kind): ?string
    {
        $stuecke = [];

        foreach ($email[$kind] ?? [] as $part) {
            $partId = $part['partId'] ?? null;
            $wert = $partId !== null ? ($email['bodyValues'][$partId]['value'] ?? null) : null;

            if (is_string($wert)) {
                $stuecke[] = $wert;
            }
        }

        return $stuecke === [] ? null : implode("\n", $stuecke);
    }

    /** @param array<string, mixed> $email */
    private function date(array $email): ?string
    {
        $wert = $email['receivedAt'] ?? $email['sentAt'] ?? null;

        return is_string($wert) ? Carbon::parse($wert)->toIso8601String() : null;
    }

    /** @param array<string, mixed> $email */
    private function hasKeyword(array $email, string $keyword): bool
    {
        return (bool) ($email['keywords'][$keyword] ?? false);
    }

    /**
     * A message id in the spelling the rest of the world uses: with brackets.
     *
     * JMAP hands ids over stripped (`a@x`), a mail header carries them wrapped
     * (`<a@x>`), and the IMAP transport passes the header through untouched.
     * Both spellings work for threading — ThreadKey normalises — but not
     * everything normalises:
     *
     * - `MessagePage::dedupe()` keys on the raw value, so one mailbox read
     *   twice in two spellings would show every sent mail twice.
     * - Products store this value. The Manager files who is working on a mail
     *   under its message id; had this gone out stripped, switching a mailbox
     *   to JMAP would have quietly detached every existing assignment.
     *
     * None of that fails loudly, which is why the contract test compares the
     * two transports field by field — it caught exactly this.
     *
     * @param  list<string>|string|null  $value
     */
    private function firstMessageId(array|string|null $value): ?string
    {
        $id = is_string($value) ? $value : ($value[0] ?? null);

        if ($id === null || trim($id) === '') {
            return null;
        }

        return '<'.trim($id, " \t<>").'>';
    }

    /**
     * References travel as one header value, because that is what the
     * threading code parses.
     *
     * @param  list<string>|string|null  $value
     */
    private function joined(array|string|null $value): ?string
    {
        if (is_string($value)) {
            return $value;
        }

        if ($value === null || $value === []) {
            return null;
        }

        return implode(' ', array_map(fn (string $id): string => '<'.trim($id, '<>').'>', $value));
    }

    /**
     * @param  list<array<string, mixed>>  $addresses
     * @return array<int, array{email: string|null, name: string|null}>
     */
    private function addresses(array $addresses): array
    {
        return array_map(
            fn (array $a): array => ['email' => $a['email'] ?? null, 'name' => $a['name'] ?? null],
            array_values($addresses),
        );
    }
}
