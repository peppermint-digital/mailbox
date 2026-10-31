<?php

namespace Peppermint\Mailbox\Http;

/**
 * Wie die Felder einer Nachricht im Fehlersatz heissen.
 *
 * ## Warum das im Paket steht
 *
 * Gemeldet von `mail-2b` am 1.11.2026, nachdem die Feldfehler aus v0.197.0 in
 * drei Produkten live waren: Am Betreff stand „subject darf höchstens 500
 * Zeichen lang sein." Die Zuordnung traf das richtige Feld — nur der Satz
 * darin sprach vom Datenbankfeld.
 *
 * Ohne diese Liste muesste sie jedes Produkt einmal schreiben, mit denselben
 * Woertern. Vier Abschriften derselben Uebersetzung sind vier Gelegenheiten,
 * `attachments` mit „Anhang" statt „Anhänge" zu uebersetzen — und dann heisst
 * dieselbe Sache je System anders, was genau der Zustand ist, den dieses Paket
 * abbaut.
 *
 * ## Warum deutsch als Vorgabe
 *
 * Weil das Paket seine Saetze schon deutsch ausliefert: „Entwurf gespeichert.",
 * „In diesem Postfach gibt es keinen Entwurfsordner.", `germanLabels`. Eine
 * englische Vorgabe neben deutschen Saetzen waere kein neutraler Stand, sondern
 * ein halber.
 *
 * Wer es anders braucht, ueberschreibt {@see feldnamen()} — eine Methode und
 * keine Konstante, genau dafuer.
 *
 * ## Die Sternchen-Schluessel sind der Punkt
 *
 * `to.*.email` ist der Schluessel, unter dem der Fehler wirklich entsteht.
 * Steht hier nur `to`, bleibt „to.0.email ist keine gültige E-Mail-Adresse"
 * stehen — und zwar unveraendert, obwohl man etwas eingetragen hat. Das ist
 * dieselbe Falle wie bei der Schluesselzuordnung im Browser
 * (`transport.ts`, `feldfehler`), nur eine Schicht tiefer.
 */
trait Feldnamen
{
    /**
     * @return array<string, string>
     */
    protected function feldnamen(): array
    {
        return [
            'to' => 'Empfänger',
            'to.*.email' => 'E-Mail-Adresse des Empfängers',
            'to.*.name' => 'Name des Empfängers',
            'cc' => 'Kopie',
            'cc.*.email' => 'E-Mail-Adresse der Kopie',
            'cc.*.name' => 'Name der Kopie',
            'bcc' => 'Blindkopie',
            'bcc.*.email' => 'E-Mail-Adresse der Blindkopie',
            'bcc.*.name' => 'Name der Blindkopie',
            'subject' => 'Betreff',
            'body_html' => 'Text',
            'body_text' => 'Text',
            'quoted_html' => 'Zitat',
            'attachments' => 'Anhänge',
            'attachments.*' => 'Anhang',
            'in_reply_to' => 'Bezug zur Vorgängernachricht',
            'references' => 'Bezug zum Gesprächsverlauf',
            'draft_folder' => 'Entwurfsordner',
            'draft_uid' => 'Entwurf',
            'replace_folder' => 'Entwurfsordner',
            'replace_uid' => 'Vorfassung',
            // Weiterleiten: Woher die Urspruungsnachricht kommt.
            'source_type' => 'Herkunft',
            'source_folder' => 'Ordner der Ursprungsnachricht',
            'source_uid' => 'Ursprungsnachricht',
        ];
    }
}
