<?php

namespace Peppermint\Mailbox\Http;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Peppermint\Mailbox\Mailboxes;
use Peppermint\Mailbox\Models\MailAccount;
use Peppermint\Mailbox\Sending\Mailer;
use Peppermint\Mailbox\Sending\Outgoing;

/**
 * Der Endpunkt zum Senden.
 *
 * ## Was das Produkt beisteuert
 *
 * `accountFor()` — welches Postfach das ist und ob der Anfragende darin
 * senden darf. Dieselbe Rolle wie `mailboxFor()` bei den Leseaktionen, nur
 * gibt es hier das KONTO zurueck und nicht die Verbindung: Gesendet wird
 * ueber SMTP, nicht ueber IMAP.
 *
 * Optional `afterSending()` — die Buchfuehrung. Wer die gesendete Nachricht
 * in eigenen Tabellen ablegen will, tut es dort. Das Paket legt nichts an,
 * was es nicht selbst liest.
 *
 * ## Weiterleiten ist Senden mit fremden Anhaengen
 *
 * {@see forward()} unterscheidet sich vom Senden an genau einer Stelle: Die
 * Anhaenge der Ursprungsnachricht muessen erneut aus dem Postfach geholt und
 * mitgeschickt werden. Ohne sie kommt beim Empfaenger ein Text an, in dem von
 * einer Rechnung die Rede ist, die nicht dabei ist — und das faellt erst dem
 * Empfaenger auf.
 *
 * Der Vertrag hat die Methode dafuer laengst: `Mailbox::attachments()` holt
 * alle Dateien einer Nachricht in einem Zug und laesst Inline-Bilder weg, weil
 * die im Rumpf stecken.
 *
 * ## Die Kopie in den Gesendet-Ordner darf nie den Versand kippen
 *
 * Sie laeuft nach dem Senden und ihr Ergebnis steht in der Antwort, aber ein
 * Fehlschlag macht aus einer zugestellten Mail keine gescheiterte. Wer hier
 * eine Fehlermeldung liest, schickt sie sonst ein zweites Mal.
 */
trait HandlesSending
{
    /**
     * Das Konto, ueber das gesendet werden soll — oder ein Abbruch.
     */
    abstract protected function accountFor(int $account): MailAccount;

    /**
     * Nach erfolgreichem Versand. Standardmaessig passiert nichts.
     */
    protected function afterSending(MailAccount $konto, string $messageId, array $daten): void {}

    /**
     * Ob dieses Konto seine Kopie selbst ablegt.
     *
     * Exchange, Office 365 und Gmail tun das. Wer dann zusaetzlich anhaengt,
     * hat alles doppelt im Gesendet-Ordner.
     */
    protected function speichertGesendetSelbst(MailAccount $konto): bool
    {
        return (bool) $konto->field('provider_saves_sent');
    }

    public function send(Request $request, int $account): JsonResponse
    {
        $daten = $request->validate([
            'to' => ['required', 'array', 'min:1'],
            'to.*.email' => ['required', 'email'],
            'to.*.name' => ['nullable', 'string', 'max:255'],
            'cc' => ['nullable', 'array'],
            'cc.*.email' => ['required', 'email'],
            'cc.*.name' => ['nullable', 'string', 'max:255'],
            'bcc' => ['nullable', 'array'],
            'bcc.*.email' => ['required', 'email'],
            'bcc.*.name' => ['nullable', 'string', 'max:255'],
            'subject' => ['required', 'string', 'max:500'],
            'body_html' => ['required', 'string'],
            'body_text' => ['nullable', 'string'],

            /*
             * Das Zitat der Ursprungsnachricht, getrennt vom eigenen Text.
             * Optional — wer es schon selbst angehaengt hat, schickt es nicht
             * mit. Es steht hier, weil {@see forward()} es auch nimmt: Ohne
             * das Feld baut jedes Produkt dieselbe Verkettung nach, und vier
             * Nachbauten derselben Zeile sind vier Gelegenheiten, die
             * Reihenfolge zu vertauschen.
             */
            'quoted_html' => ['nullable', 'string'],
            // Antworten: Ohne diese beiden beginnt die Nachricht beim
            // Empfaenger einen neuen Strang.
            'in_reply_to' => ['nullable', 'string', 'max:998'],
            'references' => ['nullable', 'string'],
            'attachments' => ['nullable', 'array'],
            'attachments.*' => ['file'],
        ]);

        $konto = $this->accountFor($account);

        $kopfzeilen = array_filter([
            'In-Reply-To' => $daten['in_reply_to'] ?? null,
            'References' => $daten['references'] ?? null,
        ]);

        $nachricht = new Outgoing(
            subject: $daten['subject'],
            html: $daten['body_html'].($daten['quoted_html'] ?? ''),
            to: array_values($daten['to']),
            cc: array_values($daten['cc'] ?? []),
            bcc: array_values($daten['bcc'] ?? []),
            text: $daten['body_text'] ?? null,
            attachments: $this->hochgeladene($request),
            headers: $kopfzeilen,
        );

        return $this->versenden($konto, $nachricht, $daten);
    }

    /**
     * Eine Nachricht weiterleiten — mit ihren urspruenglichen Anhaengen.
     *
     * Die Nutzlast kommt als `multipart/form-data`, weil der Absender eigene
     * Dateien dazulegen kann. `quoted_html` ist die zitierte Ursprungsnachricht,
     * die der Verfasser nur als Vorschau sieht: Ohne sie bekaeme der Empfaenger
     * den Kommentar ohne das, was kommentiert wird.
     */
    public function forward(Request $request, int $account): JsonResponse
    {
        $daten = $request->validate([
            'to' => ['required', 'array', 'min:1'],
            'to.*.email' => ['required', 'email'],
            'to.*.name' => ['nullable', 'string', 'max:255'],
            'cc' => ['nullable', 'array'],
            'cc.*.email' => ['required', 'email'],
            'cc.*.name' => ['nullable', 'string', 'max:255'],
            'bcc' => ['nullable', 'array'],
            'bcc.*.email' => ['required', 'email'],
            'bcc.*.name' => ['nullable', 'string', 'max:255'],
            'subject' => ['required', 'string', 'max:500'],
            'body_html' => ['nullable', 'string'],
            'quoted_html' => ['nullable', 'string'],
            'attachments' => ['nullable', 'array'],
            'attachments.*' => ['file'],
            // Woher die Ursprungsnachricht stammt. `imap` heisst hier „vom
            // Mailserver" und gilt auch fuer JMAP-Konten: `Mailboxes::for()`
            // waehlt den Weg, der Aufrufer muss ihn nicht kennen. Alles andere
            // ist die Ablage eines Produkts, und die kennt nur das Produkt
            // (siehe originalAttachments()).
            'source_type' => ['required', 'string'],
            'source_folder' => ['required_if:source_type,imap', 'nullable', 'string'],
            'source_uid' => ['required_if:source_type,imap', 'nullable'],
        ]);

        $konto = $this->accountFor($account);

        try {
            $mitgeschickt = $this->originalAttachments($konto, $daten);
        } catch (\Throwable $e) {
            report($e);

            // Lieber hoerbar scheitern als eine Weiterleitung OHNE die Dateien,
            // um die es geht: Der Absender haelt sie sonst fuer zugestellt, und
            // auffallen wuerde es erst dem Empfaenger.
            return response()->json([
                'success' => false,
                'message' => 'Die Anhänge der Ursprungsnachricht waren nicht zu holen: '.$e->getMessage(),
            ], 502);
        }

        $nachricht = new Outgoing(
            subject: $daten['subject'],
            html: ($daten['body_html'] ?? '').($daten['quoted_html'] ?? ''),
            to: array_values($daten['to']),
            cc: array_values($daten['cc'] ?? []),
            bcc: array_values($daten['bcc'] ?? []),
            attachments: $this->hochgeladene($request),
            rawAttachments: $mitgeschickt,
        );

        return $this->versenden($konto, $nachricht, $daten);
    }

    /**
     * Die Anhaenge der Ursprungsnachricht, mit Bytes.
     *
     * Fuer `source_type = imap` holt sie das Paket selbst — `attachments()`
     * des Vertrags ist genau dafuer gebaut und laesst Inline-Bilder weg. Ein
     * Produkt mit eigener Ablage ueberschreibt das und liefert sie von dort;
     * die Form bleibt dieselbe.
     *
     * @param  array<string, mixed>  $daten
     * @return list<array{contents: string, filename: string, mime?: string|null}>
     */
    protected function originalAttachments(MailAccount $konto, array $daten): array
    {
        if (($daten['source_type'] ?? null) !== 'imap') {
            return [];
        }

        $dateien = Mailboxes::for($konto)->attachments(
            (string) $daten['source_folder'],
            $daten['source_uid'],
        );

        return array_map(static fn (array $datei): array => [
            'contents' => $datei['contents'],
            'filename' => $datei['filename'],
            'mime' => $datei['mime_type'] ?? null,
        ], $dateien);
    }

    /**
     * Was der Absender selbst dazugelegt hat.
     *
     * @return list<array{path: string, filename: string, mime?: string|null}>
     */
    private function hochgeladene(Request $request): array
    {
        $dateien = [];

        foreach ($request->file('attachments', []) ?? [] as $datei) {
            if (! $datei) {
                continue;
            }

            $dateien[] = [
                'path' => $datei->getRealPath(),
                'filename' => $datei->getClientOriginalName(),
                'mime' => $datei->getMimeType(),
            ];
        }

        return $dateien;
    }

    /**
     * Der gemeinsame Teil von Senden und Weiterleiten.
     *
     * Zusammengelegt, weil sich die beiden nur in dem unterscheiden, was VOR
     * dem Versand passiert. Zwei Abschriften hiessen zwei Stellen, an denen
     * die Regel „nichts nach dem Versand darf den Versand kippen" vergessen
     * werden kann.
     *
     * @param  array<string, mixed>  $daten
     */
    /**
     * Der gemeinsame Schluss von `send()` und `forward()`.
     *
     * Bauen, hinausgeben, Kopie in „Gesendet", dann das Produkt benachrichtigen
     * — in dieser Reihenfolge, und ab dem Hinausgeben darf nichts mehr als
     * Fehlschlag beim Absender ankommen.
     *
     * Ueberschreibbar, weil hier die eine Stelle ist, an der eine Nachricht
     * dieses Pakets wirklich hinausgeht: Wer jede ausgehende Mail mitschreiben
     * oder um eine Blindkopie ins Archiv ergaenzen will, setzt hier an statt an
     * zwei Endpunkten.
     */
    protected function versenden(MailAccount $konto, Outgoing $nachricht, array $daten): JsonResponse
    {
        $mailer = new Mailer;
        $mime = $mailer->build($konto, $nachricht);

        try {
            (new \Symfony\Component\Mailer\Mailer($mailer->transportFor($konto)))->send($mime);
        } catch (\Throwable $e) {
            report($e);

            return response()->json([
                'success' => false,
                'message' => 'Die Nachricht konnte nicht versendet werden: '.$e->getMessage(),
            ], 502);
        }

        $messageId = (string) $mime->getHeaders()->get('Message-ID')?->getBodyAsString();

        // Ab hier ist die Nachricht draussen. Nichts, was jetzt noch
        // schiefgeht, darf als Fehlschlag beim Absender ankommen.
        $kopie = $this->speichertGesendetSelbst($konto) ? null : $mailer->copyToSent($konto, $mime);

        try {
            $this->afterSending($konto, $messageId, $daten);
        } catch (\Throwable $e) {
            report($e);
        }

        return response()->json([
            'success' => true,
            'message_id' => $messageId,
            // `null` heisst: gar nicht versucht, weil der Anbieter es selbst tut.
            'copied_to_sent' => $kopie,
        ]);
    }
}
