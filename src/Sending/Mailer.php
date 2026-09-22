<?php

namespace Peppermint\Mailbox\Sending;

use Illuminate\Support\Str;
use Peppermint\Mailbox\Mailboxes;
use Peppermint\Mailbox\Models\MailAccount;
use Symfony\Component\Mailer\Mailer as SymfonyMailer;
use Symfony\Component\Mailer\Transport;
use Symfony\Component\Mailer\Transport\Smtp\Auth\XOAuth2Authenticator;
use Symfony\Component\Mailer\Transport\Smtp\EsmtpTransport;
use Symfony\Component\Mailer\Transport\TransportInterface;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Email as MimeEmail;

/**
 * Der Weg nach draussen: Transport aufbauen, Nachricht zusammensetzen,
 * senden, Kopie in den Gesendet-Ordner.
 *
 * ## Warum das ins Paket gehoert
 *
 * Bis zum 22.09.2026 konnte nur der Projekt-Manager senden — 804 Zeilen in
 * `EmailSendService`. CRM und Verwaltung zeigten deshalb keinen
 * Verfassen-Knopf: Die Oberflaeche dafuer liegt laengst im Paket, der Weg
 * nach draussen nicht.
 *
 * Was hier steht, ist in jedem Produkt dasselbe. Was NICHT hier steht, ist
 * die Buchfuehrung: Wer welche gesendete Nachricht wo ablegt, welche Vorlage
 * benutzt wurde, an welcher Aufgabe sie haengt — das bleibt beim Produkt.
 *
 * ## Zwei teuer erkaufte Einzelheiten
 *
 * Beide stehen unten an ihrer Stelle, und beide waeren beim Neuschreiben
 * verlorengegangen: die Einschraenkung auf XOAUTH2 (10,3 s auf 0,3 s je
 * Versand) und die Anmeldung mit der Hauptadresse statt der eingetragenen.
 */
class Mailer
{
    /**
     * Verschickt die Nachricht und gibt ihre Message-ID zurueck.
     *
     * Die Kennung wird HIER erzeugt und nicht dem Server ueberlassen: Das
     * Produkt braucht sie, um die gesendete Nachricht spaeter wiederzufinden
     * und um Antworten darauf derselben Unterhaltung zuzuordnen. Wer sie erst
     * hinterher aus der Antwort des Servers liest, hat sie bei der Haelfte der
     * Anbieter gar nicht.
     */
    public function send(MailAccount $konto, Outgoing $nachricht): string
    {
        $mime = $this->build($konto, $nachricht);

        (new SymfonyMailer($this->transportFor($konto)))->send($mime);

        return (string) $mime->getHeaders()->get('Message-ID')?->getBodyAsString();
    }

    /**
     * Setzt die Nachricht zusammen — ohne Transport, ohne Netz.
     *
     * Getrennt vom Versand, damit der Zusammenbau pruefbar ist, ohne eine
     * SMTP-Verbindung zu brauchen.
     */
    public function build(MailAccount $konto, Outgoing $nachricht): MimeEmail
    {
        $adresse = (string) $konto->field('email');
        $mime = (new MimeEmail)
            ->from(new Address($adresse, (string) ($konto->field('label') ?: $adresse)))
            ->subject($nachricht->subject)
            ->html($nachricht->html);

        if ($nachricht->text !== null && $nachricht->text !== '') {
            $mime->text($nachricht->text);
        }

        foreach ($nachricht->to as $empfaenger) {
            $mime->addTo(new Address($empfaenger['email'], (string) ($empfaenger['name'] ?? '')));
        }

        foreach ($nachricht->cc as $empfaenger) {
            $mime->addCc(new Address($empfaenger['email'], (string) ($empfaenger['name'] ?? '')));
        }

        foreach ($nachricht->bcc as $empfaenger) {
            $mime->addBcc(new Address($empfaenger['email'], (string) ($empfaenger['name'] ?? '')));
        }

        foreach ($nachricht->attachments as $anhang) {
            $mime->attachFromPath($anhang['path'], $anhang['filename'], $anhang['mime'] ?? null);
        }

        foreach ($nachricht->rawAttachments as $anhang) {
            $mime->attach($anhang['contents'], $anhang['filename'], $anhang['mime'] ?? null);
        }

        // Eingebettete Bilder: Das HTML verweist auf sie als `cid:<name>`.
        foreach ($nachricht->inlineImages as $bild) {
            $mime->embed($bild['contents'], $bild['cid'], $bild['mime'] ?? null);
        }

        foreach ($nachricht->headers as $name => $wert) {
            $mime->getHeaders()->addTextHeader($name, $wert);
        }

        // Eine eigene Message-ID, erzeugt aus der Absenderdomain.
        if (! $mime->getHeaders()->has('Message-ID')) {
            $mime->getHeaders()->addIdHeader('Message-ID', Str::uuid()->toString().'@'.Str::after($adresse, '@'));
        }

        return $mime;
    }

    /**
     * Der Postausgang dieses Kontos.
     */
    public function transportFor(MailAccount $konto): TransportInterface
    {
        return $this->nutztOAuth($konto)
            ? $this->oauthTransport($konto)
            : $this->passwortTransport($konto);
    }

    /**
     * Prueft nur die Anmeldung, ohne etwas zu senden.
     *
     * Fuer die Diagnose: Ein Postfach, das liest, aber nicht sendet, faellt
     * sonst erst auf, wenn jemand eine Antwort schreibt.
     */
    public function probeLogin(MailAccount $konto): int
    {
        $begonnen = (int) (microtime(true) * 1000);

        $transport = $this->transportFor($konto);

        if ($transport instanceof EsmtpTransport) {
            $transport->start();
            $transport->stop();
        }

        return (int) (microtime(true) * 1000) - $begonnen;
    }

    /**
     * Legt eine Kopie in den Gesendet-Ordner.
     *
     * ## Diese Stufe darf den Versand NIE scheitern lassen
     *
     * Die Nachricht ist an dieser Stelle bereits beim Mailserver. Wer hier
     * eine Fehlermeldung liest, haelt sie fuer nicht zugestellt und schickt
     * sie ein zweites Mal.
     *
     * Viele Anbieter (Exchange, Office 365, Gmail) legen die Kopie selbst ab.
     * Dann gehoert dieser Aufruf unterlassen, sonst steht alles doppelt da —
     * das entscheidet das Produkt ueber `save_to_sent` am Konto.
     */
    public function copyToSent(MailAccount $konto, MimeEmail $mime): bool
    {
        try {
            $postfach = Mailboxes::for($konto);
            $ordner = $postfach->specialFolder('Sent');

            if ($ordner === null) {
                return false;
            }

            $postfach->append($ordner, $mime->toString(), ['\\Seen']);

            return true;
        } catch (\Throwable) {
            return false;
        }
    }

    private function nutztOAuth(MailAccount $konto): bool
    {
        return (string) $konto->field('auth_type') === 'oauth';
    }

    private function oauthTransport(MailAccount $konto): TransportInterface
    {
        $token = (string) $konto->field('oauth_access_token');

        if ($token === '') {
            throw new \RuntimeException('Für dieses Postfach liegt kein gültiges Zugriffstoken vor.');
        }

        [$host, $port] = $this->smtpZiel($konto);

        // Angemeldet wird mit der HAUPTADRESSE des Postfachs, nicht mit der
        // eingetragenen: Ist letztere ein Zweitname, weist der Postausgang sie
        // ab. Als Absender steht weiterhin die eingetragene Adresse in der
        // Nachricht — das regelt der Aufbau der Mail, nicht die Anmeldung.
        $anmeldung = $this->hauptadresse($token) ?? (string) $konto->field('email');

        $transport = Transport::fromDsn(sprintf(
            'smtp://%s:%s@%s:%d?encryption=tls',
            urlencode($anmeldung),
            urlencode($token),
            $host,
            $port,
        ));

        // 10,3 Sekunden je Versand — daher diese drei Zeilen.
        //
        // Symfony probiert die Anmeldeverfahren in fester Reihenfolge durch:
        // CRAM-MD5, LOGIN, PLAIN, und XOAUTH2 zuletzt. Office 365 lehnt die
        // ersten drei ab, laesst eine Fehlanmeldung aber bewusst ins Leere
        // laufen. Erst danach kommt das Verfahren, das funktioniert.
        //
        // Auf der Produktion gemessen (13.09.2026): mit der Vorgabeliste
        // 10602 ms, mit dieser Einschraenkung 298 ms. Die roh nachgestellte
        // XOAUTH2-Anmeldung braucht 210 ms — die Zeit ging also nie ans Netz
        // und nie an den Anbieter, sondern an die Fehlversuche davor.
        //
        // `auth_mode=xoauth2` im DSN sieht aus wie die Loesung und ist keine:
        // `EsmtpTransportFactory` liest den Schluessel nicht.
        if ($transport instanceof EsmtpTransport) {
            $transport->setAuthenticators([new XOAuth2Authenticator]);
        }

        return $transport;
    }

    private function passwortTransport(MailAccount $konto): TransportInterface
    {
        [$host, $port] = $this->smtpZiel($konto);
        $verschluesselung = (string) ($konto->field('smtp_encryption') ?: 'tls');

        return Transport::fromDsn(sprintf(
            'smtp://%s:%s@%s:%d%s',
            urlencode((string) ($konto->field('username') ?: $konto->field('email'))),
            urlencode((string) $konto->field('password')),
            $host,
            $port,
            $verschluesselung !== '' && $verschluesselung !== 'none' ? '?encryption='.$verschluesselung : '',
        ));
    }

    /**
     * @return array{0: string, 1: int}
     */
    private function smtpZiel(MailAccount $konto): array
    {
        $host = (string) $konto->field('smtp_host');
        $port = (int) $konto->field('smtp_port');

        if ($host !== '' && $port > 0) {
            return [$host, $port];
        }

        // Ohne Eintrag die bekannten Ziele der beiden Anbieter. Ein Postfach
        // ohne SMTP-Angaben ist bei OAuth der Normalfall — dort traegt niemand
        // Hostnamen ein, die seit Jahren gleich sind.
        return match ((string) $konto->field('oauth_provider')) {
            'google' => ['smtp.gmail.com', 587],
            default => ['smtp.office365.com', 587],
        };
    }

    /**
     * Die Hauptadresse aus dem Zugriffstoken, falls es eine nennt.
     *
     * Der Nutzdatenteil eines JWT ist base64url-kodiertes JSON. Hier wird
     * NICHT geprueft, ob die Signatur stimmt — das hat der Anbieter getan, als
     * er das Token ausgestellt hat. Gelesen wird nur ein Name, und im
     * schlimmsten Fall steht dort Unsinn, dann greift der Rueckfall.
     */
    private function hauptadresse(string $token): ?string
    {
        $teile = explode('.', $token);

        if (count($teile) < 2) {
            return null;
        }

        $roh = base64_decode(strtr($teile[1], '-_', '+/'), false);

        if ($roh === false) {
            return null;
        }

        $daten = json_decode($roh, true);

        if (! is_array($daten)) {
            return null;
        }

        foreach (['upn', 'preferred_username', 'email', 'unique_name'] as $feld) {
            $wert = $daten[$feld] ?? null;

            if (is_string($wert) && str_contains($wert, '@')) {
                return $wert;
            }
        }

        return null;
    }
}
