<?php

namespace Peppermint\Mailbox\Http;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Peppermint\Mailbox\Contracts\Mailbox;
use Peppermint\Mailbox\Folders\FolderNames;
use Peppermint\Mailbox\Folders\Ordnerliste;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Die Aktionen, die an den Nachrichten eines Postfachs vorgenommen werden:
 * gelesen, ungelesen, markiert, archiviert, verschoben, geloescht — einzeln
 * und sammelweise.
 *
 * ## Warum das hier steht und nicht in jedem Produkt
 *
 * Bis zum 22.09.2026 hatte sie genau ein Produkt. Der Projekt-Manager brachte
 * `setSeen`, `archive`, `move`, `delete` und einen Sammel-Endpunkt mit; CRM
 * und Verwaltung hatten **keinen einzigen** davon. Sie konnten ein Postfach
 * lesen und durchsuchen, aber nichts darin tun.
 *
 * Der Vertrag (`Contracts\Mailbox`) kannte die Verben die ganze Zeit. Was
 * fehlte, war die Strecke vom Browser bis dorthin — und die ist in jedem
 * Produkt dieselbe. Sie dreimal zu schreiben hiesse, dreimal dieselben
 * Feinheiten neu zu entdecken: dass Archivieren NICHT durch die Schleife
 * gehoert (siehe unten), dass ein `false` kein Erfolg ist, dass eine Auswahl
 * aus mehreren Ordnern mehrere Aufrufe braucht.
 *
 * ## Was das Produkt beisteuert
 *
 * Zwei Dinge, die das Paket nicht wissen kann:
 *
 * - `mailboxFor()` — welches Postfach das ist und ob der Anfragende es
 *   ueberhaupt sehen darf. Jedes Produkt regelt Zuteilung und Rechte anders.
 * - `mailboxFailure()` — wie eine Stoerung nach aussen aussieht. Das CRM
 *   unterscheidet dort 404, 409 und 502; der Manager antwortet anders.
 *
 * Die Routen legt das Produkt selbst, unter seinen eigenen Adressen. Dieses
 * Merkmal schreibt keine URLs vor — es fuellt sie.
 */
trait HandlesMailboxActions
{
    /**
     * Das Postfach zu dieser Kennung — oder ein Abbruch, wenn es dem
     * anfragenden Produkt/Benutzer nicht zusteht.
     */
    abstract protected function mailboxFor(int $account): Mailbox;

    /**
     * Fuehrt die Arbeit aus und uebersetzt jeden Fehlschlag in eine Antwort.
     *
     * @param  callable(): array<string, mixed>  $work
     */
    abstract protected function mailboxFailure(callable $work): JsonResponse;

    /**
     * Nach einer Aenderung am Postfach. Standardmaessig passiert nichts.
     *
     * ## Warum es diesen Haken gibt
     *
     * Aufgefallen am 19.10.2026 beim Versuch, den Projekt-Manager auf dieses
     * Merkmal zu ziehen: Seine eigenen Wege sind KEINE Dopplung. Sie rufen
     * dasselbe Paket, raeumen danach aber ihre Zwischenspeicher auf — die
     * Seiten eines Ordners, die Kettenansicht, die einzelne Nachricht.
     *
     * Ohne diesen Haken waere die Uebernahme ein Rueckschritt mit Ansage: Wer
     * eine Mail als gelesen markiert, saehe sie weiter als ungelesen, bis der
     * Zwischenspeicher von selbst ablaeuft. Das sieht nicht wie ein Fehler
     * aus, sondern wie „hat nicht geklickt" — und dann klickt man noch mal.
     *
     * Ein Produkt ohne Zwischenspeicher ueberschreibt nichts und merkt von
     * diesem Haken nie etwas.
     *
     * @param  list<string>  $ordner  Jeder Ordner, dessen Inhalt sich geaendert hat — bei einem Verschieben also ZWEI.
     * @param  list<int|string>  $nachrichten  Die betroffenen Kennungen, wo es welche gibt.
     */
    protected function afterMailboxChange(int $account, array $ordner, array $nachrichten = []): void {}

    /**
     * Gelesen oder ungelesen.
     */
    public function markSeen(Request $request, int $account, int|string $uid): JsonResponse
    {
        $daten = $request->validate([
            'folder' => ['required', 'string'],
            'seen' => ['required', 'boolean'],
        ]);

        return $this->mailboxFailure(function () use ($account, $daten, $uid): array {
            $erfolg = $this->mailboxFor($account)->setSeen($daten['folder'], $uid, (bool) $daten['seen']);

            if ($erfolg) {
                $this->afterMailboxChange($account, [$daten['folder']], [$uid]);
            }

            return ['success' => $erfolg];
        });
    }

    /**
     * Fahne setzen oder wegnehmen.
     */
    public function markFlagged(Request $request, int $account, int|string $uid): JsonResponse
    {
        $daten = $request->validate([
            'folder' => ['required', 'string'],
            'flagged' => ['required', 'boolean'],
        ]);

        return $this->mailboxFailure(function () use ($account, $daten, $uid): array {
            $erfolg = $this->mailboxFor($account)->setFlagged($daten['folder'], $uid, (bool) $daten['flagged']);

            if ($erfolg) {
                $this->afterMailboxChange($account, [$daten['folder']], [$uid]);
            }

            return ['success' => $erfolg];
        });
    }

    /**
     * In einen anderen Ordner.
     */
    public function moveMessage(Request $request, int $account, int|string $uid): JsonResponse
    {
        $daten = $request->validate([
            'folder' => ['required', 'string'],
            'target_folder' => ['required', 'string'],
        ]);

        return $this->mailboxFailure(function () use ($account, $daten, $uid): array {
            $erfolg = $this->mailboxFor($account)->move($daten['folder'], $uid, $daten['target_folder']);

            if ($erfolg) {
                // BEIDE Ordner: Der eine hat eine Nachricht weniger, der
                // andere eine mehr. Nur den Quellordner zu melden liesse die
                // Mail im Ziel fehlen, bis der Zwischenspeicher ablaeuft.
                $this->afterMailboxChange($account, [$daten['folder'], $daten['target_folder']], [$uid]);
            }

            return ['success' => $erfolg];
        });
    }

    /**
     * In den Papierkorb.
     */
    public function deleteMessage(Request $request, int $account, int|string $uid): JsonResponse
    {
        $daten = $request->validate(['folder' => ['required', 'string']]);

        return $this->mailboxFailure(function () use ($account, $daten, $uid): array {
            $erfolg = $this->mailboxFor($account)->delete($daten['folder'], $uid);

            if ($erfolg) {
                $this->afterMailboxChange($account, [$daten['folder']], [$uid]);
            }

            return ['success' => $erfolg];
        });
    }

    /**
     * Einen Anhang herunterladen.
     *
     * Der Anhang wird ueber seine POSITION angesprochen, nicht ueber seinen
     * Namen: Zwei Dateien in einer Mail duerfen gleich heissen, und eine
     * kaputte Mail hat gar keinen Namen. Die Position ist das Einzige, was
     * eindeutig ist.
     *
     * `Content-Disposition: attachment` ist Absicht: Ein PDF, das der Browser
     * selbst oeffnet, kann Schriftarten und Bilder nachladen — und ein
     * praeparierter Anhang bekaeme damit den Kontext dieser Anwendung. Wer die
     * Datei ansehen will, oeffnet sie aus dem Download.
     */
    public function downloadAttachment(Request $request, int $account, int|string $uid, int $index): StreamedResponse|JsonResponse
    {
        $daten = $request->validate(['folder' => ['required', 'string']]);

        $anhang = $this->mailboxFor($account)->attachment($daten['folder'], $uid, $index);

        if ($anhang === null) {
            abort(404, 'Diesen Anhang gibt es nicht (mehr).');
        }

        return response()->streamDownload(
            static function () use ($anhang): void {
                echo $anhang['contents'];
            },
            $anhang['filename'] !== '' ? $anhang['filename'] : 'anhang',
            [
                'Content-Type' => $anhang['mime_type'] !== '' ? $anhang['mime_type'] : 'application/octet-stream',
                'X-Content-Type-Options' => 'nosniff',
            ],
        );
    }

    /**
     * Die Ordnerliste dieses Postfachs — gemerkt, statt jedes Mal geholt.
     *
     * Der Weg, auf dem ein Produkt seine Ordner holt. Gemessen am 23.09.2026:
     * 485 ms je Abfrage, beim zweiten Mal 525 ms — es gab keinen
     * Zwischenspeicher, und jede Ordnerumschaltung baute eine frische
     * IMAP-Sitzung auf (Aufgabe #6119).
     *
     * Geleert wird er im Paket, bei jedem Weg, der Ordner aendert. Ein Produkt
     * mit einem EIGENEN solchen Weg muss {@see Ordnerliste::vergessen()}
     * selbst rufen — sonst legt jemand denselben Ordner zweimal an, weil der
     * erste nicht auftaucht.
     *
     * @return list<array<string, mixed>>
     */
    protected function ordnerliste(int $account): array
    {
        return Ordnerliste::fuer($account, fn (): array => $this->mailboxFor($account)->folders());
    }

    /**
     * Einen Ordner anlegen.
     *
     * Ein Unterordner entsteht ueber den Pfad: „Kunden/2026".
     */
    public function createFolder(Request $request, int $account): JsonResponse
    {
        $daten = $request->validate(['path' => ['required', 'string', 'max:255']]);

        return $this->mailboxFailure(function () use ($daten, $account): array {
            $this->mailboxFor($account)->createFolder($daten['path']);

            // Die Ordnerliste hat sich geaendert — ohne diese Meldung zeigt ein
            // Produkt mit Zwischenspeicher den neuen Ordner erst spaeter.
            Ordnerliste::vergessen($account);
            $this->afterMailboxChange($account, [$daten['path']]);

            return ['success' => true];
        });
    }

    /**
     * Einen Ordner umbenennen.
     */
    public function renameFolder(Request $request, int $account): JsonResponse
    {
        $daten = $request->validate([
            'path' => ['required', 'string', 'max:255'],
            'name' => ['required', 'string', 'max:255'],
        ]);

        $this->schuetzeOrdner($daten['path'], $account);

        return $this->mailboxFailure(function () use ($daten, $account): array {
            $neu = $this->mailboxFor($account)->renameFolder($daten['path'], $daten['name']);

            // Beide Namen: der alte verschwindet, der neue kommt.
            Ordnerliste::vergessen($account);
            $this->afterMailboxChange($account, [$daten['path'], $neu]);

            return ['success' => true, 'path' => $neu];
        });
    }

    /**
     * Einen Ordner loeschen.
     */
    public function deleteFolder(Request $request, int $account): JsonResponse
    {
        $daten = $request->validate(['path' => ['required', 'string', 'max:255']]);

        $this->schuetzeOrdner($daten['path'], $account);

        return $this->mailboxFailure(function () use ($daten, $account): array {
            $this->mailboxFor($account)->deleteFolder($daten['path']);

            Ordnerliste::vergessen($account);
            $this->afterMailboxChange($account, [$daten['path']]);

            return ['success' => true];
        });
    }

    /**
     * Haelt den Posteingang und die Standardordner aus Umbenennen und Loeschen
     * heraus.
     *
     * Bis zum 22.09.2026 gab es diese Pruefung NUR im Browser: Sie blendete
     * das Kontextmenue aus, und das war alles. Ein Aufruf des Endpunkts von
     * Hand haette den Posteingang geloescht, ohne dass irgendwo etwas
     * widersprochen haette.
     *
     * Die Marken des Servers zaehlen mehr als der Name — deshalb wird, wo
     * moeglich, die Ordnerliste gefragt. Antwortet sie nicht, bleibt der Name
     * als Anhaltspunkt: lieber einmal zu viel geschuetzt als einmal zu wenig.
     */
    protected function schuetzeOrdner(string $pfad, int $account): void
    {
        $marken = [];

        try {
            foreach ($this->mailboxFor($account)->folders() as $ordner) {
                $o = (array) $ordner;

                if (($o['path'] ?? null) === $pfad) {
                    $marken = (array) ($o['flags'] ?? []);
                    break;
                }
            }
        } catch (\Throwable) {
            // Ohne Ordnerliste entscheidet der Name allein.
        }

        abort_if(
            FolderNames::isProtected($pfad, $marken),
            422,
            'Dieser Ordner gehört zur Grundausstattung des Postfachs und lässt sich nicht ändern.',
        );
    }

    /**
     * Eine Auswahl auf einmal.
     *
     * Die Antwort zaehlt `processed` und `failed` getrennt, weil beides
     * vorkommt: Eine Kennung, die in DIESEM Ordner nicht liegt, wirft keine
     * Ausnahme — die Methode gibt `false` zurueck. Wer nur auf Ausnahmen
     * achtet, meldet Erfolg, waehrend nichts passiert ist (im Manager am
     * 13.09.2026 genau so gemeldet: mehrere Mails angekreuzt, geloescht,
     * keine Fehlermeldung und keine Wirkung).
     */
    public function bulk(Request $request, int $account): JsonResponse
    {
        $daten = $request->validate([
            'folder' => ['required', 'string'],
            'action' => ['required', 'string', 'in:read,unread,flag,unflag,move,delete,archive'],
            /*
             * `max:200` ist ein Riegel gegen die Schleife darunter, nicht
             * gegen den Menschen davor: Jede Kennung ist ein eigener Aufruf
             * am Mailserver, und O365 drosselt pro Postfach ueber alle
             * Verbindungen (#4159). Aus dem Manager gehoben, der ihn hatte.
             *
             * KEINE Regel auf `uids.*`: Der Manager verlangte dort `integer`,
             * und das ist fuer JMAP falsch — dort ist eine Kennung eine
             * Zeichenkette. Die Uebernahme repariert das mit.
             *
             * Was die Grenze NICHT kann: Sie antwortet mit 422, und `postCounted()`
             * macht aus allem, was nicht 2xx ist, ein `null` — die Oberflaeche
             * sagt dann „nicht erreichbar". Erreichbar ist das heute nicht, weil
             * keine Liste mehr als 200 Zeilen auf einmal zeigt. Zeigt sie es
             * einmal, braucht der Transport einen eigenen Zweig fuer 422.
             */
            'uids' => ['required', 'array', 'min:1', 'max:200'],
            'target_folder' => ['required_if:action,move', 'string'],
        ]);

        return $this->mailboxFailure(function () use ($daten, $account): array {
            $postfach = $this->mailboxFor($account);
            $ordner = $daten['folder'];
            $kennungen = array_values($daten['uids']);

            // Archivieren laeuft bewusst NICHT durch die Schleife: Es braucht
            // den Archiv-Ordner des Postfachs, und den je Nachricht neu zu
            // suchen hiesse, sich je Nachricht neu anzumelden. O365 drosselt
            // pro Postfach ueber alle Verbindungen — bei 30 angekreuzten Mails
            // ist das der Unterschied zwischen einer Sekunde und einer
            // Drosselung (im Manager als #4159 gemessen).
            if ($daten['action'] === 'archive') {
                $ergebnis = $postfach->archive($ordner, $kennungen);

                /*
                 * Der ZIELORDNER gehoert dazu, nicht nur der Quellordner.
                 * Stand hier bis zum 20.10.2026 nicht — und der Manager hat
                 * es in seinem eigenen Weg gemacht, also waere die Uebernahme
                 * ein Rueckschritt gewesen: Das Archiv haette nach dem
                 * Archivieren weiter den alten Stand gezeigt.
                 *
                 * Nur bei Erfolg nachfragen: `specialFolder()` kostet eine
                 * Ordnerliste, und wenn nichts verschoben wurde, ist auch
                 * nichts im Archiv zu vergessen.
                 */
                $ziele = [$ordner];

                if ($ergebnis['archived'] > 0) {
                    $ziele[] = $postfach->specialFolder('Archive');
                }

                $this->afterMailboxChange($account, array_values(array_filter($ziele)), $kennungen);

                return [
                    'success' => true,
                    'processed' => $ergebnis['archived'],
                    'failed' => $ergebnis['failed'],
                ];
            }

            $erledigt = 0;
            $fehlgeschlagen = 0;

            foreach ($kennungen as $uid) {
                $ok = match ($daten['action']) {
                    'read' => $postfach->setSeen($ordner, $uid, true),
                    'unread' => $postfach->setSeen($ordner, $uid, false),
                    'flag' => $postfach->setFlagged($ordner, $uid, true),
                    'unflag' => $postfach->setFlagged($ordner, $uid, false),
                    'move' => $postfach->move($ordner, $uid, $daten['target_folder']),
                    'delete' => $postfach->delete($ordner, $uid),
                    default => false,
                };

                if ($ok) {
                    $erledigt++;

                    continue;
                }

                $fehlgeschlagen++;

                /*
                 * Ein `false` heisst: Die Kennung liegt in DIESEM Ordner
                 * nicht. Das wirft keine Ausnahme — und wurde deshalb nirgends
                 * festgehalten, waehrend der Aufruf Erfolg meldete. Wer
                 * mehrere Mails ankreuzte und loeschte, sah keine
                 * Fehlermeldung und keine Wirkung (im Projekt-Manager am
                 * 13.09.2026 gemeldet).
                 *
                 * Die Zahl allein reicht nicht: „3 von 5 fehlgeschlagen" sagt
                 * nicht, WELCHE — und ohne das ist es nicht nachvollziehbar.
                 * Deshalb steht es im Paket und nicht in einem Haken: Der
                 * Fehler gehoert jedem, der die Mehrfachauswahl benutzt.
                 */
                Log::warning('Massenaktion: Nachricht im Ordner nicht gefunden.', [
                    'account' => $account,
                    'folder' => $ordner,
                    'uid' => $uid,
                    'action' => $daten['action'],
                ]);
            }

            /*
             * EINMAL am Ende, nicht je Nachricht: Eine Mehrfachauswahl mit
             * fuenfzig Zeilen wuerde sonst fuenfzig Mal dieselben
             * Zwischenspeicher leeren. Beim Verschieben gehoert der
             * Zielordner dazu.
             */
            $this->afterMailboxChange(
                $account,
                array_values(array_filter([$ordner, $daten['action'] === 'move' ? ($daten['target_folder'] ?? null) : null])),
                $kennungen,
            );

            /*
             * `success` heisst hier: DIE ANFRAGE IST GELAUFEN — nicht „alles
             * hat geklappt". Das Ergebnis tragen `processed` und `failed`.
             *
             * Die Unterscheidung ist nicht kosmetisch, sie entscheidet, was
             * der Mensch davor liest. Hier stand bis zum 20.10.2026
             * `$fehlgeschlagen === 0`, und das machte den Teilerfolgs-Zweig
             * der Oberflaeche zu totem Code:
             *
             *   postCounted() gibt bei `success: false` ein `null` zurueck
             *   → runBulkAcrossFolders() setzt `unreachable`
             *   → die Oberflaeche sagt „Sammelaktion fehlgeschlagen"
             *
             * Also genau die Meldung, die der Fund vom 13.09.2026 abschaffen
             * sollte: „3 von 5 bearbeitet, 2 im Ordner nicht gefunden" waere
             * nie erschienen, und „keine einzige konnte bearbeitet werden,
             * laden Sie die Liste neu" auch nicht. Beides hat der Nutzer
             * gemeldet, beides war gebaut, beides war unerreichbar.
             *
             * Schlimmer noch: `unreachable` klebt ueber Ordner hinweg. Faellt
             * Ordner A teilweise und gelingt B vollstaendig, hiesse es
             * „fehlgeschlagen", waehrend zwanzig Mails verschoben wurden.
             *
             * Beide Haelften waren fuer sich getestet und gruen. Der Test auf
             * dieser Seite behauptete `success === false` ausdruecklich.
             * Gefunden nur, indem Server und Transport GEGENEINANDER gelesen
             * wurden.
             */
            return [
                'success' => true,
                'processed' => $erledigt,
                'failed' => $fehlgeschlagen,
            ];
        });
    }
}
