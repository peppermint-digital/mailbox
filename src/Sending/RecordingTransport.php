<?php

namespace Peppermint\Mailbox\Sending;

use Peppermint\Mailbox\Mailboxes;
use Symfony\Component\Mailer\Envelope;
use Symfony\Component\Mailer\SentMessage;
use Symfony\Component\Mailer\Transport\TransportInterface;
use Symfony\Component\Mime\Message;
use Symfony\Component\Mime\RawMessage;

/**
 * Ein Transport, der die Nachricht behaelt statt sie hinauszugeben.
 *
 * ## Wofuer
 *
 * Damit ein Test die Frage stellen kann, die sonst niemand stellt: Ist das,
 * was hinausgeht, wirklich das, was gemeint war? Empfaenger, Betreff,
 * Blindkopie, Anhaenge, die Message-ID — alles davon entsteht erst beim
 * Zusammensetzen, und ein Fehler darin faellt nicht beim Absender auf,
 * sondern beim Empfaenger.
 *
 * Ohne so einen Transport braucht diese Frage einen echten Mailserver, und
 * eine Frage, die einen echten Mailserver braucht, wird nicht gestellt. Im
 * Projekt-Manager liefen deshalb fuenf Versand-Tests gegen eine Attrappe des
 * PRODUKT-Dienstes — sie prueften also die eigene Verdrahtung und nie die
 * Nachricht. Nahm man den Dienst aus dem Weg, lief jeder Test in einen
 * SMTP-Zeitablauf von rund 20 Sekunden.
 *
 * ## Was er bewusst NICHT tut
 *
 * Er faengt keine Fehler und spielt keine vor. Wer prueft, was bei einem
 * gescheiterten Versand passiert, gibt einen eigenen Transport mit, der
 * wirft — {@see Mailer::fake()} nimmt jede Closure.
 *
 * Und er ersetzt die Kopie in „Gesendet" nicht: Die laeuft ueber
 * {@see Mailboxes::for()} und hat dort ihre eigene Naht.
 * Beides getrennt zu halten ist Absicht — sonst sagt ein gruener Test „die
 * Mail ist raus und liegt im Ordner", und man weiss nicht, welche Haelfte
 * davon wirklich geprueft wurde.
 */
class RecordingTransport implements TransportInterface
{
    /** @var list<array{nachricht: RawMessage, umschlag: ?Envelope}> */
    private array $mitschrift = [];

    public function send(RawMessage $message, ?Envelope $envelope = null): ?SentMessage
    {
        $this->mitschrift[] = ['nachricht' => $message, 'umschlag' => $envelope];

        /*
         * Ein `SentMessage` zurueckgeben, weil der Aufrufer es auswerten darf.
         * Der Umschlag wird nur dann aus der Nachricht abgeleitet, wenn keiner
         * mitkam — und das verlangt Absender und Empfaenger. Fehlt einer, soll
         * das hier auffallen: Eine Nachricht ohne Absender ist ein Fehler im
         * Zusammensetzen, kein Testdetail.
         */
        $umschlag = $envelope ?? Envelope::create($message);

        return new SentMessage($message, $umschlag);
    }

    public function __toString(): string
    {
        return 'recording://mailbox';
    }

    /**
     * Alles, was hinausgegangen waere — in der Reihenfolge des Versands.
     *
     * @return list<RawMessage>
     */
    public function nachrichten(): array
    {
        return array_map(fn (array $eintrag): RawMessage => $eintrag['nachricht'], $this->mitschrift);
    }

    /**
     * Die letzte Nachricht, oder `null`, wenn nichts gesendet wurde.
     *
     * `null` statt einer Ausnahme, damit „es wurde nichts gesendet" eine
     * Zusicherung sein kann und nicht ein Fehlschlag mit Stapelabzug.
     */
    public function letzte(): ?RawMessage
    {
        $letzter = $this->mitschrift[count($this->mitschrift) - 1] ?? null;

        return $letzter['nachricht'] ?? null;
    }

    /**
     * Die letzte Nachricht als `Message` — fuer Zugriff auf Kopfzeilen.
     *
     * Getrennt von {@see letzte()}, weil `RawMessage` keine Kopfzeilen kennt:
     * Wer `->getHeaders()` auf etwas aufruft, das nur eine Zeichenkette
     * umschliesst, bekommt einen Fehler ueber eine fehlende Methode und sucht
     * ihn im eigenen Test.
     */
    public function letzteNachricht(): ?Message
    {
        $letzte = $this->letzte();

        return $letzte instanceof Message ? $letzte : null;
    }

    public function anzahl(): int
    {
        return count($this->mitschrift);
    }

    /** Die Umschlaege — dort stehen die Blindkopie-Empfaenger. */
    public function umschlaege(): array
    {
        return array_map(fn (array $eintrag): ?Envelope => $eintrag['umschlag'], $this->mitschrift);
    }
}
