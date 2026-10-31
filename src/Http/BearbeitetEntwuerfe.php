<?php

namespace Peppermint\Mailbox\Http;

use Illuminate\Http\Request;
use Peppermint\Mailbox\Mailboxes;
use Peppermint\Mailbox\Models\MailAccount;

/**
 * Einen Entwurf weiterbearbeiten — in jedem Produkt derselbe Weg.
 *
 * ## Warum das hierhergehoert und nicht in vier Controller
 *
 * Gemeldet von Bastian am 31.10.2026: „In der Verwaltung lassen sich
 * Entwuerfe im Mail-Browser nicht weiterbearbeiten, im Manager schon."
 *
 * Die Erkennung des Ordners war nie das Problem — `looksLikeFolder()` findet
 * `Entw&APw-rfe` genauso wie `Drafts` oder die Marke `\Drafts`. Es fehlte
 * schlicht die zweite Haelfte: Nur der Projekt-Manager hatte in seinem
 * Controller den Code, der einen Entwurf in den Verfasser laedt und ihn beim
 * Senden abloest. Die anderen drei hatten eine Verfassen-Seite ohne jede
 * Entwurfs-Unterstuetzung — und folglich blieb auch der Knopf weg, denn die
 * Leseansicht zeigt ihn nur, wenn das Produkt einen Rueckruf dafuer hat.
 *
 * Das ist genau die Form, gegen die dieses Paket gebaut ist: Eine Faehigkeit,
 * die in einem Produkt gewachsen ist und in den anderen drei fehlt, ohne dass
 * jemand das entschieden haette.
 *
 * ## Was das Produkt beisteuert
 *
 * Zwei Zeilen: den Zug einbinden und das Ergebnis von
 * {@see entwurfAusAnfrage()} an die Verfassen-Seite durchreichen. Mehr nicht —
 * die Route und die Seite gehoeren dem Produkt, alles dazwischen liegt hier.
 *
 * ## Die drei Dinge, die hier teuer erkauft sind
 *
 * Sie stehen im Manager seit Bug #1155 und wandern mit, weil sie sonst in drei
 * Produkten neu gelernt werden muessten:
 *
 * 1. **Ein Entwurf ohne HTML bleibt nicht leer.** Entwuerfe, die ein anderes
 *    System abgelegt hat (nanoclaw ueber Graph oder IMAP), haben oft nur
 *    `body_text`. Ohne Rueckfall steht die Maske leer da und der Mensch
 *    schreibt alles noch einmal.
 * 2. **Die Anhaenge kommen aus dem ENTWURF, nicht aus dem Browser** — und sie
 *    muessen VOR dem Versand geholt werden. Bis zum Versand ist der Entwurf
 *    die einzige Kopie seiner Dateien.
 * 3. **Der Entwurf wird erst NACH erfolgreichem Versand entfernt**, und ein
 *    Fehlschlag dabei darf nie als „Fehler beim Senden" ankommen. Sonst
 *    schickt jemand eine Mail ein zweites Mal, die laengst drausssen ist.
 */
trait BearbeitetEntwuerfe
{
    /**
     * Der Entwurf, mit dem die Verfassen-Seite vorbelegt werden soll.
     *
     * `null`, wenn keiner angefragt ist oder er nicht mehr im Postfach liegt.
     * Beides ist kein Fehler: Wer den Entwurf inzwischen woanders geloescht
     * hat, soll eine leere Maske bekommen und keine Fehlerseite.
     *
     * @return array{folder: string, uid: int|string, to: array, cc: array, bcc: array, subject: string, body_html: string, attachments: list<array{filename: string, size: int}>}|null
     */
    protected function entwurfAusAnfrage(Request $request, MailAccount $konto): ?array
    {
        $ordner = $request->query('draft_folder');
        $uid = $request->query('draft_uid');

        if (! is_string($ordner) || $ordner === '' || $uid === null || $uid === '') {
            return null;
        }

        try {
            $nachricht = Mailboxes::for($konto)->message($ordner, is_numeric($uid) ? (int) $uid : (string) $uid);
        } catch (\Throwable $e) {
            report($e);

            return null;
        }

        if ($nachricht === null) {
            return null;
        }

        return [
            'folder' => $ordner,
            'uid' => is_numeric($uid) ? (int) $uid : (string) $uid,
            'to' => $nachricht['to'] ?? [],
            'cc' => $nachricht['cc'] ?? [],
            // BCC steht in keinem abgelegten Entwurf: Es reist nie mit der
            // Nachricht mit, auch nicht in den eigenen Ordner.
            'bcc' => [],
            'subject' => (string) ($nachricht['subject'] ?? ''),
            'body_html' => $this->entwurfsRumpf($nachricht),
            /*
             * Nur Name und Groesse, nicht der Inhalt (Bug #1155, zweite
             * Haelfte). Die Dateien gehen beim Senden mit, aus dem Entwurf —
             * zu SEHEN waren sie bisher trotzdem nicht, und bei einer Mail,
             * die eine Rechnung ankuendigt, ist Vertrauen zu wenig.
             *
             * Der Inhalt bleibt draussen: Er wuerde die Seitenantwort um die
             * volle Dateigroesse aufblaehen, fuer eine Liste von Namen.
             */
            'attachments' => array_values(array_map(
                static fn (array $anhang): array => [
                    'filename' => (string) ($anhang['filename'] ?? 'Anhang'),
                    'size' => (int) ($anhang['size'] ?? 0),
                ],
                $nachricht['attachments'] ?? [],
            )),
        ];
    }

    /**
     * Der Rumpf fuer den Editor — mit Rueckfall auf den Text.
     *
     * `nl2br(e(...))`: Erst entschaerfen, dann Umbrueche setzen. Andersherum
     * waere das `<br>` gleich wieder Text.
     *
     * @param  array<string, mixed>  $nachricht
     */
    protected function entwurfsRumpf(array $nachricht): string
    {
        $html = (string) ($nachricht['body_html'] ?? '');

        if ($html !== '') {
            return $html;
        }

        $text = (string) ($nachricht['body_text'] ?? '');

        return $text === '' ? '' : nl2br(e($text));
    }

    /**
     * Die Anhaenge des Entwurfs, mit Bytes — VOR dem Versand zu holen.
     *
     * @return list<array{contents: string, filename: string, mime?: string|null}>
     */
    protected function entwurfsAnhaenge(MailAccount $konto, ?string $ordner, int|string|null $uid): array
    {
        if ($ordner === null || $ordner === '' || $uid === null || $uid === '') {
            return [];
        }

        try {
            $dateien = Mailboxes::for($konto)->attachments($ordner, $uid);
        } catch (\Throwable $e) {
            report($e);

            return [];
        }

        return array_values(array_map(static fn (array $datei): array => [
            'contents' => $datei['contents'],
            'filename' => $datei['filename'],
            'mime' => $datei['mime_type'] ?? null,
        ], $dateien));
    }

    /**
     * Den Entwurf abloesen, nachdem die Nachricht draussen ist.
     *
     * ## Warum `purge()` und nicht `delete()` — mit einer Ausnahme
     *
     * `purge()` loescht endgueltig, und der Vertrag nennt genau diesen Fall
     * beim Namen: „a draft that was just replaced". `delete()` legt in den
     * Papierkorb, und ein Papierkorb, der sich mit jeder Zwischenfassung eines
     * Entwurfs fuellt, ist nichts, was jemand bestellt hat.
     *
     * **Die Ausnahme ist der Grund, warum hier ueberhaupt unterschieden wird.**
     * Endgueltig loeschen darf man nur, wenn es noch eine Kopie GIBT. Die
     * Kopie in „Gesendet" kann fehlschlagen, ohne dass der Versand scheitert
     * (so ist es gewollt, siehe {@see HandlesSending::versenden()}). Faellt sie
     * aus und wuerde der Entwurf trotzdem endgueltig geloescht, stuende der
     * Mensch mit NICHTS da: keine Kopie im Gesendet-Ordner und kein Entwurf.
     *
     * Deshalb: `$kopie === false` heisst „wir wissen, dass keine Kopie
     * entstanden ist" → Papierkorb statt endgueltig. `true` heisst „Kopie
     * liegt", `null` heisst „der Anbieter legt sie selbst ab" — in beiden
     * Faellen ist der Entwurf wirklich entbehrlich.
     *
     * Ein Fehlschlag hier wird gemeldet und verschluckt. Die Nachricht ist
     * drausssen; nichts danach darf beim Absender als Fehler ankommen.
     */
    protected function entwurfAbloesen(MailAccount $konto, ?string $ordner, int|string|null $uid, ?bool $kopie): void
    {
        if ($ordner === null || $ordner === '' || $uid === null || $uid === '') {
            return;
        }

        try {
            $postfach = Mailboxes::for($konto);

            if ($kopie === false) {
                $postfach->delete($ordner, $uid);

                return;
            }

            $postfach->purge($ordner, $uid);
        } catch (\Throwable $e) {
            report($e);
        }
    }
}
