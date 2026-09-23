<?php

namespace Peppermint\Mailbox\Archive;

use Peppermint\Mailbox\Content\Gespraechstext;
use Peppermint\Mailbox\Contracts\Mailbox;
use Peppermint\Mailbox\Models\MailBody;
use Peppermint\Mailbox\Models\MailFolderState;
use Peppermint\Mailbox\Models\MailLocation;
use Peppermint\Mailbox\Models\MailMessage;
use Peppermint\Mailbox\Threading\ThreadKey;
use Throwable;

/**
 * Ein Ordner, einmal durchgesehen: was neu ist und was verschwunden ist.
 *
 * ## Warum beides in einem Lauf
 *
 * Es ist dieselbe Frage. Wer wissen will, was neu ist, vergleicht die
 * Kennungen im Ordner mit denen, die er kennt — und in derselben Bewegung
 * faellt auf, welche seiner Kennungen der Ordner nicht mehr hat. Zwei
 * getrennte Laeufe holten dieselbe Liste zweimal und koennten sich
 * widersprechen, wenn dazwischen jemand etwas verschiebt.
 *
 * ## Verschwunden heisst nicht geloescht
 *
 * Eine Kennung, die der Ordner nicht mehr nennt, schliesst ihren ORT
 * (`gone_at`). Ob die Nachricht damit aus dem Postfach ist, entscheidet sich
 * erst danach: Hat sie keinen offenen Ort mehr, bekommt sie `missing_since`.
 * Liegt sie woanders — weil jemand sie verschoben hat —, ist nichts passiert
 * ausser einem Umzug in der Geschichte.
 *
 * Die Zeile selbst, ihre Rohfassung und ihr Gespraechstext bleiben in jedem
 * Fall. Das ist der Zweck der Uebung und nicht ein Versehen.
 *
 * ## Die Gueltigkeitsnummer entscheidet, ob die Kennungen ueberhaupt gelten
 *
 * Aendert der Server die `uidvalidity` eines Ordners, sind alle gespeicherten
 * Kennungen dieses Ordners auf einen Schlag bedeutungslos. Wer das uebersieht,
 * schliesst dann reihenweise Orte als „verschwunden" und legt dieselben
 * Nachrichten daneben neu an — die Ablage waechst, der Bestand zerfaellt.
 *
 * Deshalb: Stimmt die Nummer nicht mehr mit der gespeicherten ueberein, werden
 * die alten Orte NICHT geschlossen, sondern als ungueltig markiert und neu
 * aufgebaut. Ein Umbruch, der auffaellt, statt eines Zerfalls, der nicht
 * auffaellt.
 */
class Erfassung
{
    public function __construct(
        private readonly Mailbox $postfach,
        private readonly int $accountId,
        private readonly Ablage $ablage,
    ) {}

    /**
     * Einen Ordner erfassen.
     */
    public function ordner(string $ordner, int $hoechstens = 200): Ergebnis
    {
        $zustand = $this->postfach->folderState($ordner);

        if ($zustand === null) {
            return Ergebnis::ordnerFehlt($ordner);
        }

        $ergebnis = new Ergebnis($ordner, $zustand['uidvalidity'] ?? null, $zustand['uidnext'] ?? null);

        $gemerkt = MailFolderState::firstOrNew([
            'email_account_id' => $this->accountId,
            'folder' => $ordner,
        ]);

        if ($gemerkt->exists && $gemerkt->unveraendert($zustand)) {
            // Der teure Teil beginnt erst danach. Hier ist die Arbeit fertig:
            // Gueltigkeitsnummer, naechste Kennung und Anzahl sind dieselben
            // wie beim letzten Mal — in diesem Ordner ist nichts passiert.
            $gemerkt->update(['checked_at' => now()]);
            $ergebnis->unveraendert();

            return $ergebnis;
        }

        /*
         * Der Stichtag.
         *
         * Beim ALLERERSTEN Blick in einen Ordner ist jede vorhandene Nachricht
         * „neu" — ein Lauf ohne diese Stelle zoege den gesamten gewachsenen
         * Bestand herein. Genau das soll er nicht: Die alten Mails liegen
         * weiter im Postfach, und wer sie braucht, oeffnet sie (dann greift
         * {@see einzelne()}).
         *
         * Also wird beim ersten Mal nur gemerkt, wo der Ordner gerade steht.
         * Aufgenommen wird ab dem naechsten Lauf, und zwar das, was seitdem
         * dazugekommen ist.
         */
        if (! $gemerkt->exists) {
            $juengste = $this->postfach->newest($ordner, 1);
            $gemerkt->fill([
                'uidvalidity' => $zustand['uidvalidity'] ?? null,
                'uidnext' => $zustand['uidnext'] ?? null,
                'messages' => $zustand['messages'] ?? null,
                'since_handle' => isset($juengste[0]['uid']) ? (string) $juengste[0]['uid'] : null,
                'checked_at' => now(),
            ])->save();

            $ergebnis->stichtagGesetzt();

            return $ergebnis;
        }

        $imOrdner = $this->postfach->handles($ordner);
        $bekannt = $this->bekannteOrte($ordner, $zustand['uidvalidity'] ?? null, $ergebnis);

        /*
         * Was seit dem Stichtag dazugekommen ist — NICHT alles, was der
         * Ordner hat und wir nicht kennen.
         *
         * `newerThan()` fragt genau das: die Zeilen nach einer bestimmten
         * Nachricht. Einen Ordner mit zehntausend alten Mails abzugleichen und
         * die Differenz zu bilden haette bei jedem Lauf zehntausend
         * Kandidaten ergeben, von denen keiner gewollt ist.
         */
        $seit = $gemerkt->since_handle;

        $kandidaten = $seit === null
            // Der Ordner war beim Stichtag LEER. Dann ist alles, was jetzt
            // drin liegt, danach gekommen — und `newerThan` hat nichts, woran
            // es sich orientieren koennte.
            ? array_map(static fn ($u): string => (string) $u, $imOrdner)
            : array_map(static fn (array $z): string => (string) $z['uid'], $this->postfach->newerThan($ordner, $seit, $hoechstens * 2));

        $neu = array_values(array_diff($kandidaten, array_keys($bekannt)));

        $letzteKennung = null;

        foreach (array_slice($neu, 0, $hoechstens) as $uid) {
            try {
                $this->aufnehmen($ordner, $uid, $zustand['uidvalidity'] ?? null, $ergebnis);
                $letzteKennung = $uid;
            } catch (Throwable $e) {
                // Eine Nachricht, die sich nicht holen laesst, darf die
                // uebrigen nicht aufhalten — und sie darf auch nicht
                // stillschweigend fehlen. Sie steht im Ergebnis.
                $ergebnis->fehler($uid, $e->getMessage());
            }
        }

        if (count($neu) > $hoechstens) {
            $ergebnis->offen(count($neu) - $hoechstens);
        }

        $this->verschwundeneSchliessen($imOrdner, $bekannt, $ergebnis);

        /*
         * Den Zustand erst JETZT merken, und nur wenn der Lauf sauber war.
         *
         * Wer ihn auch nach einem halben Lauf speichert, ueberspringt den
         * Ordner beim naechsten Mal — und das Uebersprungene bleibt fuer immer
         * aus. Dasselbe gilt fuer einen Lauf, der am Deckel abgebrochen ist:
         * Solange noch etwas offen ist, hat sich der Ordner fuer uns sehr wohl
         * geaendert.
         */
        if ($ergebnis->sauber() && $ergebnis->offen === 0) {
            $gemerkt->fill([
                'uidvalidity' => $zustand['uidvalidity'] ?? null,
                'uidnext' => $zustand['uidnext'] ?? null,
                'messages' => $zustand['messages'] ?? null,
                // Der Stichtag wandert mit: Beim naechsten Lauf beginnt die
                // Suche hinter dem, was gerade aufgenommen wurde.
                'since_handle' => $letzteKennung ?? $gemerkt->since_handle,
                'checked_at' => now(),
            ])->save();
        }

        return $ergebnis;
    }

    /**
     * Eine einzelne Nachricht nachtraeglich aufnehmen.
     *
     * ## Wofuer
     *
     * Der laufende Betrieb nimmt nur Neues auf — alles, was vor dem Stichtag
     * im Postfach lag, bleibt draussen. Den ganzen Bestand nachzuziehen waere
     * stundenlange Last fuer Nachrichten, die niemand mehr ansieht.
     *
     * Wer aber eine alte Nachricht im Browser OEFFNET, sagt damit, dass sie
     * ihn interessiert — und das Postfach hat sie in diesem Moment ohnehin
     * schon herausgegeben. Sie dabei zu behalten kostet einen zusaetzlichen
     * Abruf der Rohfassung und fuellt die Ablage mit dem, womit gearbeitet
     * wird.
     *
     * ## Nichts tun ist der Normalfall
     *
     * Ist die Nachricht schon da, passiert nichts — kein Abruf, kein
     * Schreibvorgang. Sonst holte jeder zweite Blick auf dieselbe Mail ihre
     * Bytes erneut ueber die Leitung.
     */
    public function einzelne(string $ordner, int|string $uid): bool
    {
        $ergebnis = new Ergebnis($ordner);

        $vorhanden = MailLocation::query()
            ->where('email_account_id', $this->accountId)
            ->where('folder', $ordner)
            ->where('uid', (string) $uid)
            ->whereNull('gone_at')
            ->exists();

        if ($vorhanden) {
            return false;
        }

        $this->aufnehmen($ordner, (string) $uid, null, $ergebnis);

        return $ergebnis->aufgenommen > 0;
    }

    /**
     * Die Orte, die fuer diesen Ordner gespeichert sind — nach Kennung.
     *
     * @return array<string, MailLocation>
     */
    private function bekannteOrte(string $ordner, ?int $uidvalidity, Ergebnis $ergebnis): array
    {
        $orte = MailLocation::query()
            ->where('email_account_id', $this->accountId)
            ->where('folder', $ordner)
            ->whereNull('gone_at')
            ->get();

        if ($orte->isEmpty()) {
            return [];
        }

        // Der Umbruch: Die Gueltigkeitsnummer hat sich geaendert. Alle
        // gespeicherten Kennungen dieses Ordners sind damit bedeutungslos.
        $gespeicherte = $orte->pluck('uidvalidity')->filter()->unique();

        if ($uidvalidity !== null && $gespeicherte->isNotEmpty() && ! $gespeicherte->contains($uidvalidity)) {
            $ergebnis->umbruch($gespeicherte->first(), $uidvalidity);

            // Nicht als „verschwunden" schliessen — das waere eine Aussage
            // ueber die Nachrichten, und die stimmt nicht. Sie liegen noch
            // da, nur unter anderen Nummern.
            MailLocation::query()
                ->whereIn('id', $orte->pluck('id'))
                ->update(['gone_at' => now(), 'updated_at' => now()]);

            return [];
        }

        return $orte->keyBy(fn (MailLocation $o): string => (string) $o->uid)->all();
    }

    /**
     * Eine Nachricht in die Ablage aufnehmen.
     */
    private function aufnehmen(string $ordner, string $uid, ?int $uidvalidity, Ergebnis $ergebnis): void
    {
        $roh = $this->postfach->raw($ordner, $uid);

        if ($roh === null) {
            // Zwischen dem Auflisten und dem Holen verschwunden. Kein Fehler,
            // nur ein geteiltes Postfach.
            $ergebnis->entwischt();

            return;
        }

        $kopf = $this->postfach->message($ordner, $uid);
        $messageId = (string) ($kopf['message_id'] ?? '');

        if ($messageId === '') {
            // Ohne Kennung koennte dieselbe Nachricht bei jedem Lauf neu
            // angelegt werden. Lieber melden als die Ablage aufblaehen.
            $ergebnis->fehler($uid, 'Nachricht ohne Message-ID');

            return;
        }

        $nachricht = $this->nachrichtAnlegen($messageId, $kopf, $roh, $ergebnis);

        MailLocation::updateOrCreate(
            ['mail_message_id' => $nachricht->id, 'folder' => $ordner, 'uid' => $uid],
            [
                'email_account_id' => $this->accountId,
                'uidvalidity' => $uidvalidity,
                'seen_at' => now(),
                'gone_at' => null,
            ],
        );

        $ergebnis->aufgenommen();
    }

    /**
     * @param  array<string, mixed>|null  $kopf
     * @param  array{raw: string, size: int|null, complete: bool}  $roh
     */
    private function nachrichtAnlegen(string $messageId, ?array $kopf, array $roh, Ergebnis $ergebnis): MailMessage
    {
        $hash = MailMessage::hash($messageId);
        $gesendet = isset($kopf['date']) ? \Carbon\Carbon::parse($kopf['date']) : null;
        $anhaenge = count((array) ($kopf['attachments'] ?? []));
        $pfad = Pfad::fuer($this->accountId, $hash, $gesendet);

        $this->ablage->ablegen($pfad, $roh['raw']);

        if (! $roh['complete']) {
            // Eine Kopie als wortgetreu zu fuehren, obwohl sie es vielleicht
            // nicht ist, ist schlimmer als gar keine.
            $ergebnis->unvollstaendig($messageId);
        }

        $nachricht = MailMessage::updateOrCreate(
            ['email_account_id' => $this->accountId, 'message_id_hash' => $hash],
            [
                'message_id' => $messageId,
                'thread_key' => ThreadKey::fromHeaders(
                    $messageId,
                    $kopf['in_reply_to'] ?? null,
                    $kopf['references'] ?? null,
                ),
                'subject' => $kopf['subject'] ?? null,
                'from_email' => $kopf['from_address'] ?? ($kopf['from'] ?? null),
                'from_name' => $kopf['from_name'] ?? null,
                'recipients' => $this->empfaenger($kopf),
                'sent_at' => $gesendet,
                'size_bytes' => $roh['size'],
                // Aus der Liste gezaehlt und nicht aus einem Feld gelesen:
                // `message()` liefert `attachments`, nicht `attachment_count`.
                // Beim ersten Livelauf stand deshalb bei einer
                // Auftragsbestaetigung mit 105-KB-PDF „0 Anhaenge" — falsch,
                // und still falsch: Die Zahl sah plausibel aus.
                'has_attachments' => $anhaenge > 0,
                'attachment_count' => $anhaenge,
                'raw_path' => $pfad,
                'raw_sha256' => hash('sha256', $roh['raw']),
                'source' => 'poll',
                'captured_at' => now(),
                'last_seen_at' => now(),
                // Sie ist wieder da. Ein `missing_since` von frueher waere
                // jetzt falsch — etwa weil jemand sie aus dem Papierkorb
                // zurueckgeholt hat.
                'missing_since' => null,
            ],
        );

        $this->textAufbereiten($nachricht, $kopf);

        return $nachricht;
    }

    /**
     * @param  array<string, mixed>|null  $kopf
     */
    private function textAufbereiten(MailMessage $nachricht, ?array $kopf): void
    {
        $text = ($kopf['body_text'] ?? null) !== null && trim((string) $kopf['body_text']) !== ''
            ? Gespraechstext::aus((string) $kopf['body_text'])
            : Gespraechstext::ausHtml((string) ($kopf['body_html'] ?? ''));

        MailBody::updateOrCreate(
            ['mail_message_id' => $nachricht->id],
            MailBody::felder($text),
        );
    }

    /**
     * @param  array<string, mixed>|null  $kopf
     * @return list<array{email: string, name: string|null, type: string}>
     */
    private function empfaenger(?array $kopf): array
    {
        $liste = [];

        foreach (['to', 'cc', 'bcc'] as $art) {
            foreach ((array) ($kopf[$art] ?? []) as $eintrag) {
                $liste[] = [
                    'email' => (string) ($eintrag['email'] ?? $eintrag),
                    'name' => $eintrag['name'] ?? null,
                    'type' => $art,
                ];
            }
        }

        return $liste;
    }

    /**
     * Orte schliessen, deren Kennung der Ordner nicht mehr nennt.
     *
     * @param  list<int|string>  $imOrdner
     * @param  array<string, MailLocation>  $bekannt
     */
    private function verschwundeneSchliessen(array $imOrdner, array $bekannt, Ergebnis $ergebnis): void
    {
        $vorhanden = array_flip(array_map(static fn ($u): string => (string) $u, $imOrdner));

        foreach ($bekannt as $uid => $ort) {
            if (isset($vorhanden[$uid])) {
                continue;
            }

            $ort->update(['gone_at' => now()]);
            $ergebnis->verschwunden();

            // Liegt sie noch woanders, ist nichts weiter passiert. Erst wenn
            // sie nirgends mehr offen ist, ist sie aus dem Postfach.
            $nachricht = $ort->message;

            if ($nachricht !== null && $nachricht->aktuelleOrte()->count() === 0) {
                $nachricht->update(['missing_since' => now()]);
                $ergebnis->ausDemPostfach();
            }
        }
    }
}
