<?php

namespace Peppermint\Mailbox\Imap;

use DirectoryTree\ImapEngine\BodyStructurePart;
use DirectoryTree\ImapEngine\Message;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Peppermint\Mailbox\Content\CidReplacer;
use Peppermint\Mailbox\Support\Betreff;
use Peppermint\Mailbox\Threading\ThreadKey;

/**
 * Turns an IMAP message into rows the rest of the world can use.
 *
 * ## The one class that knows what IMAP is
 *
 * Everything else in this package works on plain arrays, which is what makes
 * it shareable. This class is the border post: it is the only place that takes
 * a `DirectoryTree\ImapEngine\Message` and it exists so the products do not
 * each write the same extraction.
 *
 * `directorytree/imapengine` is a **suggest**, not a require. Installing this
 * package for its settings and rules must not drag an IMAP client along — and
 * a product that reads mail has the library anyway.
 *
 * ## Two shapes, and why the list one is not just the full one truncated
 *
 * `summary()` is what a list needs and nothing more, because a mailbox list
 * fetches hundreds of them; `full()` pulls bodies and attachments, which is a
 * different cost entirely. Merging them would make every list view pay for
 * attachment downloads.
 *
 * No labels: a message without a subject gets `null`. What a reader sees
 * instead is the product's decision, in the product's language.
 */
class MessageFormatter
{
    public function __construct(
        private readonly CidReplacer $cids = new CidReplacer,
    ) {}

    /**
     * Siehe {@see anhangZahl()} — die Warnung kommt einmal, nicht hundertmal.
     *
     * Am Objekt und nicht `static`: Eine Liste baut genau einen Formatierer
     * und reicht ihm hundert Nachrichten, also ist das die richtige Koernung.
     * Ein `static` haette ausserdem ueber Testlaeufe hinweg geklebt und den
     * Test von der Reihenfolge abhaengig gemacht.
     */
    private bool $rueckfallGemeldet = false;

    /**
     * For a list view: headers, flags, attachment count — and NO preview.
     *
     * ## Why there is no preview here (#6119, measured 20.10.2026)
     *
     * Diese Zeile stand hier:
     *
     *     'preview' => Str::limit($message->text() ?? strip_tags($message->html() ?? ''), 100),
     *
     * `text()` fasst den RUMPF an. Alle vier Listenwege ueber IMAP holen die
     * Nachrichten mit `withHeaders()->withFlags()->withBodyStructure()` — also
     * OHNE Rumpf. Die Bibliothek holt ihn dann einzeln nach, pro Zeile, in dem
     * Moment, in dem formatiert wird.
     *
     * Gemessen an `department@peppermint-digital.de`, einem echten Postfach:
     *
     *     folders                   389 ms
     *     newest(100) (ohne summary)   1.509 ms
     *     page(1, 25) (mit summary)   10.999 ms   <-- 25 Nachhol-Rundreisen
     *
     * Elf Sekunden fuer eine Seite, von denen rund acht auf 25 einzelne
     * Rumpf-Abrufe gingen. Bei `headerRows()` und `search()` sind es bis zu
     * HUNDERT.
     *
     * Und das Entscheidende: **Keine Oberflaeche zeichnet dieses Feld.** Es
     * steht im TypeScript-Typ (`rows.ts`, `preview: string`), wird an einer
     * Stelle mit `''` belegt — und von keiner Komponente gelesen. Gesucht
     * wurde im Paket und in allen vier Produkten.
     *
     * Es ist derselbe Fehler wie Bug #877, nur eine Ebene tiefer: Damals
     * formatierte `headerRows()` alle hundert Zeilen statt der sichtbaren.
     * Behoben wurde die ANZAHL der formatierten Zeilen — nicht, dass
     * Formatieren selbst den Rumpf anfasst.
     *
     * ## Warum das Feld bleibt, aber leer
     *
     * Entfernt wird es nicht: Die Drahtform ist eine Zusage an vier Produkte,
     * und ein Feld zu streichen, das jemand morgen lesen koennte, ist eine
     * andere Entscheidung als es nicht zu befuellen. Ueber JMAP bleibt es
     * ausserdem echt — dort ist `preview` eine Eigenschaft der Listenabfrage
     * und kostet nichts.
     *
     * Wer hier wieder eine Vorschau haben will, holt den Rumpf im SELBEN
     * Abruf (`withBody()` an `fuerListenzeilen()`) und nimmt die Datenmenge in
     * Kauf — oder zeigt den Auszug erst beim Oeffnen.
     *
     * @return array<string, mixed>
     */
    public function summary(Message $message): array
    {
        $from = $message->from();
        $anzahl = $this->anhangZahl($message);

        return [
            'uid' => $message->uid(),
            'message_id' => $message->messageId(),
            'subject' => Betreff::lesbar($message->subject()),
            'from_address' => $from?->email() ?? '',
            'from_name' => $from?->name() ?? '',
            'date' => $message->date()?->toIso8601String(),
            'has_attachments' => $anzahl > 0,
            'attachment_count' => $anzahl,
            'is_read' => $message->isSeen(),
            'is_flagged' => $message->isFlagged(),
            // Leer, mit Absicht — siehe Klassen-Text dieser Methode. Den Rumpf
            // hier anzufassen kostete 25 bis 100 Rundreisen je Liste, fuer ein
            // Feld, das keine Oberflaeche zeichnet.
            'preview' => '',
            // The threading headers travel along: they are loaded anyway when
            // listing, and without them a list cannot be grouped into
            // conversations afterwards without a second round trip.
            'in_reply_to' => $this->header($message, 'in-reply-to'),
            'references' => $this->header($message, 'references'),
        ];
    }

    /**
     * For reading one message: recipients, bodies, attachments.
     *
     * Inline images are resolved into the body right here. A reader that gets
     * the body and the attachments separately has to reinvent this, and
     * reinventing it is where the broken-image bugs come from.
     *
     * @return array<string, mixed>
     */
    public function full(Message $message): array
    {
        $from = $message->from();
        $attachments = [];
        $inline = [];

        foreach ($message->attachments() as $index => $attachment) {
            $contentId = $attachment->contentId();

            if (self::isEmbeddedImage($contentId, $attachment->contentDisposition(), $attachment->contentType())) {
                $inline[$contentId] = 'data:'.$attachment->contentType().';base64,'.base64_encode($attachment->contents());

                continue;
            }

            $attachments[] = [
                'index' => $index,
                'filename' => $attachment->filename() ?? 'attachment',
                'mime_type' => $attachment->contentType(),
                'size' => Str::length($attachment->contents()),
            ];
        }

        $html = $message->html();

        if ($html && $inline !== []) {
            $html = $this->cids->replace($html, $inline);
        }

        return [
            'uid' => $message->uid(),
            'message_id' => $message->messageId(),
            'subject' => Betreff::lesbar($message->subject()),
            'from_address' => $from?->email() ?? '',
            'from_name' => $from?->name() ?? '',
            'to' => $this->addresses($message->to()),
            'cc' => $this->addresses($message->cc()),
            'date' => $message->date()?->toIso8601String(),
            'body_html' => $html,
            'body_text' => $message->text(),
            'attachments' => $attachments,
            'is_read' => $message->isSeen(),
            'is_flagged' => $message->isFlagged(),
            'in_reply_to' => $this->header($message, 'in-reply-to'),
            'references' => $this->header($message, 'references'),
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
                $message->messageId(),
                $this->header($message, 'in-reply-to'),
                $this->header($message, 'references'),
            ),
        ];
    }

    /**
     * How many attachments the LIST should show.
     *
     * ## Why not the library's own number
     *
     * `Message::attachmentCount()` parses the body — and when listing, the
     * body is deliberately not loaded. The answer is then not "imprecise", it
     * is **0**: a mail carrying an invoice showed no paperclip and no count,
     * in every IMAP mailbox, always. Measured against a real IMAP server on
     * 15.10.2026 (Bug #1025) — the code reading that preceded it guessed the
     * opposite, that the number was too HIGH.
     *
     * BODYSTRUCTURE answers the same question without fetching a single
     * attachment byte: it is metadata, not content. It has to be asked for
     * (`withBodyStructure()`), and where it was not asked for, the library's
     * number is still the best that exists.
     *
     * Counted with {@see isEmbeddedImage()}, so that the number in the list
     * matches what the opened message lists — and what JMAP reports for the
     * same mailbox.
     */
    private function anhangZahl(Message $message): int
    {
        $struktur = $message->bodyStructure();

        if ($struktur === null) {
            /*
             * Hier wird gleich eine Zahl herausgegeben, die sehr wahrscheinlich
             * falsch ist: Ohne geladenen Rumpf antwortet die Bibliothek mit 0,
             * und `0` sieht aus wie „keine Anhaenge" statt wie „nicht gefragt".
             * Genau das war Bug #1025, und genau das faellt niemandem auf.
             *
             * Einmal je Vorgang, nicht je Nachricht — eine Liste hat hundert
             * davon, und eine Warnung, die hundertmal kommt, wird abgeschaltet
             * statt gelesen.
             */
            if (! $this->rueckfallGemeldet) {
                $this->rueckfallGemeldet = true;

                Log::warning('Mailbox: Anhangzahl ohne BODYSTRUCTURE ermittelt — die Zahl ist vermutlich 0, obwohl Anhaenge da sind. Die Abfrage sollte withBodyStructure() mitholen (siehe MailboxClient::fuerListenzeilen).');
            }

            return $message->attachmentCount();
        }

        $anzahl = 0;

        foreach ($struktur->flatten() as $teil) {
            if (! self::istRumpfteil($teil)) {
                $anzahl++;
            }
        }

        return $anzahl;
    }

    /**
     * Is this structure part part of the DISPLAY rather than a file?
     *
     * Two cases. The body itself — text or HTML that carries no filename and
     * is not explicitly an attachment; a `.txt` someone attached has one and
     * is counted. And an embedded image, by the same rule as everywhere else.
     */
    private static function istRumpfteil(BodyStructurePart $teil): bool
    {
        $disposition = $teil->disposition()?->type()->value;

        if (($teil->isText() || $teil->isHtml()) && $teil->filename() === null && $disposition !== 'attachment') {
            return true;
        }

        return self::isEmbeddedImage($teil->id(), $disposition, $teil->contentType());
    }

    /**
     * Does this part belong IN the body instead of in the attachment list?
     *
     * THE rule, and the only one anyone may ask. Three conditions, each of
     * them necessary:
     *
     * - **An image.** An inline PDF is a file someone attached, whatever the
     *   disposition says: it belongs in the list, and it travels on a forward.
     * - **With a Content-ID.** The body points at a part by its cid and by
     *   nothing else. A part without one cannot be referenced, so it cannot be
     *   in the body — however it is marked.
     * - **Not explicitly an attachment.** Some clients set no disposition at
     *   all, and treating those as attachments leaves a hole in the text where
     *   the picture should be.
     *
     * ## Why it is spelled exactly once
     *
     * Every caller asks this same question and has to get the same answer:
     * {@see full()} decides what the screen lists, {@see attachmentAt()} what
     * a download hands out, `MailboxClient::attachments()` what a forward
     * carries, {@see verbatim()} what the archive flags — and the JMAP side
     * calls in here for all four.
     *
     * Three slightly different answers was Bug #1023: a part too inline for
     * the list and not inline enough for the body existed NOWHERE. No broken
     * image, no empty row, no log line. Measured on 15.10.2026, on both
     * transports, with an image marked `inline` without a Content-ID — the
     * shape Outlook and a few ERP mailers produce.
     */
    public static function isEmbeddedImage(?string $contentId, ?string $disposition, ?string $contentType): bool
    {
        // Ueber CidReplacer gefragt und nicht selbst getrimmt: Was hier als
        // Kennung gilt, MUSS im Rumpf auch ersetzbar sein. `<>` ist nicht
        // leer und normalisiert zu nichts — das auseinanderlaufen zu lassen
        // war Bug #1023 ein zweites Mal.
        //
        // Die Angabe wird klein geschrieben verglichen, weil RFC 2183 sie
        // ohne Ruecksicht auf Gross- und Kleinschreibung definiert. Ein
        // Absender, der `Content-Disposition: Attachment` schreibt, verlor
        // seinen Anhang sonst genauso lautlos — nur eben an der Schreibweise
        // einer Kopfzeile statt an einer fehlenden Kennung.
        return CidReplacer::bareId($contentId) !== ''
            && strtolower(trim((string) $disposition)) !== 'attachment'
            && Str::startsWith((string) $contentType, 'image/');
    }

    /**
     * The same message, but for keeping: body untouched, every file separate.
     *
     * @return array<string, mixed>
     */
    public function verbatim(Message $message): array
    {
        $from = $message->from();
        $files = [];

        foreach ($message->attachments() as $index => $attachment) {
            $contentId = $attachment->contentId();
            // EINMAL lesen: Der Inhalt kommt aus einem Strom, der nach dem
            // ersten Zugriff leer ist. Zweimal zu fragen schrieb die Datei
            // richtig und die Groesse daneben als 0 (Bug #557 im Manager).
            $contents = $attachment->contents();

            $files[] = [
                'index' => $index,
                'filename' => $attachment->filename() ?? 'attachment',
                'mime_type' => $attachment->contentType(),
                'extension' => $attachment->extension(),
                'contents' => $contents,
                'size' => Str::length($contents),
                'content_id' => $contentId,
                'inline' => self::isEmbeddedImage($contentId, $attachment->contentDisposition(), $attachment->contentType()),
            ];
        }

        return [
            'uid' => $message->uid(),
            'message_id' => $message->messageId(),
            'subject' => Betreff::lesbar($message->subject()),
            'from_address' => $from?->email() ?? '',
            'from_name' => $from?->name(),
            'to' => $this->addresses($message->to()),
            'cc' => $this->addresses($message->cc()),
            'date' => $message->date()?->toIso8601String(),
            // Roh, mit cid: — genau der Unterschied zu full().
            'body_html' => $message->html(),
            'body_text' => $message->text(),
            'files' => $files,
            'is_read' => $message->isSeen(),
            'is_flagged' => $message->isFlagged(),
            'in_reply_to' => $this->header($message, 'in-reply-to'),
            'references' => $this->header($message, 'references'),
        ];
    }

    /**
     * One attachment with its bytes, addressed by the same index {@see full()}
     * hands out.
     *
     * That index is the position in the message's own part list — NOT the
     * position in the filtered list. Inline images keep their number even
     * though they never appear on screen, so counting the visible ones here
     * would drift apart from the view as soon as a mail contains a logo.
     *
     * @return array{filename: string, mime_type: string, contents: string}|null
     */
    public function attachmentAt(mixed $message, int $index): ?array
    {
        foreach ($message->attachments() as $i => $attachment) {
            if ((int) $i !== $index) {
                continue;
            }

            if (self::isEmbeddedImage($attachment->contentId(), $attachment->contentDisposition(), $attachment->contentType())) {
                // Die Stelle gibt es, sie gehoert aber in den Rumpf. Wer sie
                // anfordert, meint etwas anderes — nichts zurueckgeben ist
                // ehrlicher als ein Logo auszuliefern.
                //
                // Dieselbe Regel wie in full(): Was dort in der Liste steht,
                // MUSS hier herauskommen. Vorher stand hier die breite
                // Inline-Frage, und ein Inline-PDF war damit sichtbar, aber
                // nicht herunterladbar (Bug #1023).
                return null;
            }

            return [
                'filename' => $attachment->filename() ?? 'attachment',
                'mime_type' => (string) $attachment->contentType(),
                'contents' => (string) $attachment->contents(),
            ];
        }

        return null;
    }

    /**
     * @param  iterable<mixed>  $addresses
     * @return array<int, array{email: string|null, name: string|null}>
     */
    private function addresses(iterable $addresses): array
    {
        $result = [];

        foreach ($addresses as $address) {
            $result[] = ['email' => $address->email(), 'name' => $address->name()];
        }

        return $result;
    }

    private function header(Message $message, string $name): ?string
    {
        return $message->header($name)?->getValue();
    }
}
