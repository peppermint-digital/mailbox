<?php

namespace Peppermint\Mailbox\Http;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Peppermint\Mailbox\Contracts\Mailbox;

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
     * Gelesen oder ungelesen.
     */
    public function markSeen(Request $request, int $account, int|string $uid): JsonResponse
    {
        $daten = $request->validate([
            'folder' => ['required', 'string'],
            'seen' => ['required', 'boolean'],
        ]);

        return $this->mailboxFailure(fn (): array => [
            'success' => $this->mailboxFor($account)->setSeen($daten['folder'], $uid, (bool) $daten['seen']),
        ]);
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

        return $this->mailboxFailure(fn (): array => [
            'success' => $this->mailboxFor($account)->setFlagged($daten['folder'], $uid, (bool) $daten['flagged']),
        ]);
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

        return $this->mailboxFailure(fn (): array => [
            'success' => $this->mailboxFor($account)->move($daten['folder'], $uid, $daten['target_folder']),
        ]);
    }

    /**
     * In den Papierkorb.
     */
    public function deleteMessage(Request $request, int $account, int|string $uid): JsonResponse
    {
        $daten = $request->validate(['folder' => ['required', 'string']]);

        return $this->mailboxFailure(fn (): array => [
            'success' => $this->mailboxFor($account)->delete($daten['folder'], $uid),
        ]);
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
            'uids' => ['required', 'array', 'min:1'],
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

                $ok ? $erledigt++ : $fehlgeschlagen++;
            }

            return [
                'success' => $fehlgeschlagen === 0,
                'processed' => $erledigt,
                'failed' => $fehlgeschlagen,
            ];
        });
    }
}
