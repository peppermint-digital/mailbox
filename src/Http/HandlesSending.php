<?php

namespace Peppermint\Mailbox\Http;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
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
            // Antworten: Ohne diese beiden beginnt die Nachricht beim
            // Empfaenger einen neuen Strang.
            'in_reply_to' => ['nullable', 'string', 'max:998'],
            'references' => ['nullable', 'string'],
        ]);

        $konto = $this->accountFor($account);

        $kopfzeilen = array_filter([
            'In-Reply-To' => $daten['in_reply_to'] ?? null,
            'References' => $daten['references'] ?? null,
        ]);

        $nachricht = new Outgoing(
            subject: $daten['subject'],
            html: $daten['body_html'],
            to: array_values($daten['to']),
            cc: array_values($daten['cc'] ?? []),
            bcc: array_values($daten['bcc'] ?? []),
            text: $daten['body_text'] ?? null,
            headers: $kopfzeilen,
        );

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
