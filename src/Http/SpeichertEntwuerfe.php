<?php

namespace Peppermint\Mailbox\Http;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Peppermint\Mailbox\Exceptions\EntwurfsAnhaengeNichtLesbar;
use Peppermint\Mailbox\Mailboxes;
use Peppermint\Mailbox\Models\MailAccount;
use Peppermint\Mailbox\Sending\Mailer;
use Peppermint\Mailbox\Sending\Outgoing;

/**
 * Einen Entwurf ANLEGEN — das Gegenstueck zu {@see HandlesSending}.
 *
 * ## Warum das hierhergehoert
 *
 * Gefunden von `mail-2b` am 31.10.2026 beim Vergleich der vier
 * Verfassen-Seiten: `allowDraft`/`onSaveDraft` am Formular waren nur der
 * KNOPF. Den Serverweg hatte allein der Projekt-Manager, in eigenem Code.
 *
 * Damit konnten CRM, Verwaltung und AI Brain seit v0.189.0 einen Entwurf
 * OEFFNEN und keinen ANLEGEN — eine halbe Faehigkeit, und die unangenehme
 * Haelfte: Wer einen Entwurf weiterbearbeitet und dann „Als Entwurf
 * speichern" drueckt, verliert seine Arbeit an einem Knopf, der danach
 * aussieht, als haette er sie gesichert.
 *
 * Dasselbe Muster wie bei {@see BearbeitetEntwuerfe}: eine Faehigkeit, die in
 * einem Produkt gewachsen ist, in den anderen drei fehlt, und niemand hat das
 * entschieden.
 *
 * ## Was das Produkt beisteuert
 *
 * Eine Route und `accountFor()` — dasselbe, was {@see HandlesSending} schon
 * braucht. Vorschlag fuer die Route, damit niemand zwei Formen lernen muss:
 *
 *     POST {basis}/{account}/draft   -> entwurfSpeichern()
 *     POST {basis}/{account}/send    -> send()
 *
 * ## Die drei Lehren aus Bug #1155 gelten hier genauso
 *
 * Sie sind nicht abgeschrieben, sondern dieselben, weil die Gefahr dieselbe
 * ist — nur dass nicht gesendet, sondern abgelegt wird:
 *
 * 1. **Die Anhaenge der Vorfassung kommen ZUERST.** Beim zweiten Speichern
 *    ist die alte Fassung die einzige Kopie ihrer Dateien. Wer erst die neue
 *    anlegt und dann holt, holt am Ende aus einem Ordner, den er gerade
 *    geleert hat.
 * 2. **Ein Fehlschlag dabei bricht ab.** Vorher einen Entwurf OHNE seine
 *    Anhaenge abzulegen und Erfolg zu melden waere der schlimmere Ausgang:
 *    Die Dateien sind dann weg, und der Mensch erfaehrt es Tage spaeter.
 * 3. **Die Vorfassung wird erst NACH dem erfolgreichen `append` entfernt.**
 *    Und sie wird `purge()`-t und nicht in den Papierkorb gelegt: Ein
 *    Papierkorb, der sich mit jeder Zwischenfassung eines Entwurfs fuellt,
 *    ist nicht, was jemand mit „loeschen" meint.
 *
 * ## Was ein Entwurf NICHT braucht
 *
 * Empfaenger, Betreff, Text — alles optional. Ein Entwurf ist der Zustand
 * „noch nicht fertig"; ihn an denselben Pflichtfeldern wie das Senden zu
 * messen hiesse, dass man erst speichern darf, wenn man nicht mehr muss.
 *
 * ## Was dieser Zug (noch) NICHT kann, und zwar sichtbar
 *
 * **Die Blindkopie wird nicht mitgespeichert.** Die Nutzlast darf `bcc`
 * tragen (das Formular fuehrt es), aber in die abgelegte Nachricht kommt es
 * nicht — und die Antwort sagt das mit `bcc_gespeichert: false`, damit das
 * Produkt es dem Menschen sagen kann statt es zu verschweigen.
 *
 * Der Grund ist nicht Bequemlichkeit, sondern dass es sonst ein stiller
 * Verlust waere: `Contracts\Mailbox` gibt zu einer Nachricht KEIN `bcc`
 * heraus — weder der IMAP- noch der JMAP-Klient kennen das Feld. Es
 * mitzuschreiben und beim Wiederaufnehmen nicht lesen zu koennen waere
 * schlimmer als es gar nicht zu schreiben: Dann stuende es im Postfach und
 * waere fuer den Verfasser doch verloren.
 *
 * Dazu kommt, dass die Entwurfsordner hier teils GETEILTE Postfaecher sind
 * (`info@`, `department.purchasing@`). Blinde Empfaenger in einem Ordner
 * abzulegen, in den Kollegen sehen, ist eine Entscheidung und kein
 * Nebeneffekt — sie gehoert Bastian und nicht diesem Zug.
 */
trait SpeichertEntwuerfe
{
    use BearbeitetEntwuerfe;
    use Feldnamen;

    abstract protected function accountFor(int $account): MailAccount;

    /**
     * Den Entwurf ablegen und die Vorfassung ersetzen.
     */
    public function entwurfSpeichern(Request $request, int $account): JsonResponse
    {
        $daten = $request->validate([
            /*
             * Alles optional — siehe Klassentext. Die FORM wird trotzdem
             * geprueft: Eine kaputte Adresse gehoert am Feld gemeldet und
             * nicht in den Entwurf geschrieben, wo sie beim Senden ein
             * zweites Mal auffaellt.
             */
            'to' => ['nullable', 'array'],
            'to.*.email' => ['required', 'email'],
            'to.*.name' => ['nullable', 'string', 'max:255'],
            'cc' => ['nullable', 'array'],
            'cc.*.email' => ['required', 'email'],
            'cc.*.name' => ['nullable', 'string', 'max:255'],
            'bcc' => ['nullable', 'array'],
            'bcc.*.email' => ['required', 'email'],
            'bcc.*.name' => ['nullable', 'string', 'max:255'],
            'subject' => ['nullable', 'string', 'max:500'],
            'body_html' => ['nullable', 'string'],
            'body_text' => ['nullable', 'string'],

            /*
             * Antworten: Die beiden Koepfe gehoeren in den Entwurf, nicht
             * erst ins Senden. Ein Entwurf, der sie verliert, beginnt beim
             * Empfaenger einen neuen Strang — und das faellt erst auf, wenn
             * die Antwort drei Tage spaeter allein dasteht.
             */
            'in_reply_to' => ['nullable', 'string', 'max:998'],
            'references' => ['nullable', 'string'],
            'quoted_html' => ['nullable', 'string'],

            'attachments' => ['nullable', 'array'],
            'attachments.*' => ['file'],

            /*
             * Die Vorfassung. Beide oder keines: mit nur einem der beiden
             * gibt es nichts zu ersetzen.
             *
             * Die Namen sind die des Managers (`replace_folder`,
             * `replace_uid`) — uebernommen, damit sein Frontend ohne
             * Aenderung weiterlaeuft. Sie heissen bewusst ANDERS als
             * `draft_folder`/`draft_uid` beim Senden: Dort wird abgeloest,
             * hier ersetzt, und das sind zwei Dinge.
             */
            'replace_folder' => ['nullable', 'string'],
            'replace_uid' => ['nullable'],
            /*
             * Die deutschen Feldnamen im DRITTEN Argument, nicht im zweiten:
             * Das zweite sind eigene Saetze, und die will hier niemand —
             * Laravels Saetze sind richtig, nur die Feldnamen darin waren
             * Datenbankspalten. Siehe {@see Feldnamen}.
             */
        ], [], $this->feldnamen());

        $konto = $this->accountFor($account);
        $postfach = Mailboxes::for($konto);

        $ordner = $postfach->specialFolder('Drafts');

        if ($ordner === null || $ordner === '') {
            /*
             * Kein Entwurfsordner: Das ist eine Lage und kein Absturz. Ein
             * Postfach ohne `\Drafts` und ohne einen Ordner, dessen Name
             * danach aussieht, gibt es — und der Mensch soll erfahren, dass
             * sein Text NICHT gesichert ist, statt es zu glauben.
             */
            return response()->json([
                'success' => false,
                'message' => 'In diesem Postfach gibt es keinen Entwurfsordner.',
            ], 422);
        }

        $vorOrdner = $daten['replace_folder'] ?? null;
        $vorUid = $daten['replace_uid'] ?? null;

        // Lehre 1 und 2 aus #1155: zuerst holen, und ein Fehlschlag bricht ab.
        try {
            $alteAnhaenge = $this->entwurfsAnhaenge($konto, $vorOrdner, $vorUid);
        } catch (EntwurfsAnhaengeNichtLesbar $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], 502);
        }

        $nachricht = new Outgoing(
            subject: (string) ($daten['subject'] ?? ''),
            html: (string) ($daten['body_html'] ?? '').($daten['quoted_html'] ?? ''),
            to: array_values($daten['to'] ?? []),
            cc: array_values($daten['cc'] ?? []),
            // Siehe Klassentext: nicht in die abgelegte Nachricht.
            bcc: [],
            text: $daten['body_text'] ?? null,
            attachments: $this->hochgeladeneEntwurfsdateien($request),
            rawAttachments: $alteAnhaenge,
            headers: array_filter([
                'In-Reply-To' => $daten['in_reply_to'] ?? null,
                'References' => $daten['references'] ?? null,
            ]),
        );

        try {
            $mime = (new Mailer)->build($konto, $nachricht);

            /*
             * `\Draft` sagt dem Postfach, was das ist — danach zeigt jeder
             * Klient es als Entwurf und nicht als empfangene Mail. `\Seen`,
             * weil der eigene Entwurf nichts Ungelesenes ist: Ohne das zaehlt
             * der Ordner mit und meldet dem Verfasser seine eigene Arbeit als
             * neue Post.
             */
            $uid = $postfach->append($ordner, $mime->toString(), ['\\Draft', '\\Seen']);
        } catch (\Throwable $e) {
            report($e);

            return response()->json([
                'success' => false,
                'message' => 'Der Entwurf konnte nicht gespeichert werden.',
            ], 502);
        }

        /*
         * Lehre 3: erst JETZT. Und nur, wenn die neue Fassung wirklich liegt —
         * sonst waere das Ergebnis eines gescheiterten Speicherns ein
         * geloeschter Entwurf.
         */
        $this->vorfassungEntfernen($konto, $vorOrdner, $vorUid);

        return response()->json([
            'success' => true,
            'message' => 'Entwurf gespeichert.',
            'folder' => $ordner,
            /*
             * Die Kennung der neuen Fassung, wenn der Server eine nennt —
             * manche tun es nicht (`append` darf `null` geben). Das Produkt
             * braucht sie fuer das NAECHSTE Speichern: Ohne sie legt der
             * zweite Klick eine zweite Fassung an statt zu ersetzen.
             */
            'uid' => $uid,
            /*
             * Ehrlich statt still: Hat die Nutzlast ein BCC getragen, steht
             * es NICHT im abgelegten Entwurf (Klassentext). Das Produkt kann
             * es damit sagen, statt den Verfasser im Glauben zu lassen.
             *
             * „Verworfen" und nicht „gespeichert": Bei der zweiten Form muesste
             * man `true` auch dann schicken, wenn es gar kein BCC gab — eine
             * Antwort auf eine Frage, die niemand gestellt hat. So ist `false`
             * der Normalfall und `true` die Meldung.
             */
            'bcc_verworfen' => ($daten['bcc'] ?? []) !== [],
        ]);
    }

    /**
     * Die Vorfassung endgueltig entfernen — und nie den Vorgang scheitern lassen.
     *
     * Die neue Fassung liegt an dieser Stelle schon. Wer hier eine
     * Fehlermeldung bekommt, haelt den Entwurf fuer nicht gespeichert und
     * schreibt weiter — oder speichert noch einmal und hat dann drei
     * Fassungen. Zwei Fassungen im Ordner sind haesslich; ein verlorener Text
     * ist schlimmer.
     */
    protected function vorfassungEntfernen(MailAccount $konto, ?string $ordner, int|string|null $uid): void
    {
        if ($ordner === null || $ordner === '' || $uid === null || $uid === '') {
            return;
        }

        try {
            Mailboxes::for($konto)->purge($ordner, is_numeric($uid) ? (int) $uid : (string) $uid);
        } catch (\Throwable $e) {
            report($e);
        }
    }

    /**
     * Die Dateien aus dem Formular.
     *
     * Eigener Name und nicht derselbe wie in {@see HandlesSending}: Zwei
     * Zuege mit einer gleichnamigen privaten Methode lassen sich nicht an
     * derselben Klasse verwenden, und genau das tun alle vier Produkte —
     * senden UND speichern haengen am gleichen Controller.
     *
     * Die FORM ist dieselbe (`path`, `filename`, `mime`), denn sie ist die,
     * die {@see Outgoing::$attachments} liest. Eine eigene Form hier hiesse,
     * dass derselbe Anhang je nach Knopf anders ankommt.
     *
     * @return list<array{path: string|false, filename: string, mime: string|null}>
     */
    private function hochgeladeneEntwurfsdateien(Request $request): array
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
}
