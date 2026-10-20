<?php

namespace Peppermint\Mailbox\Http;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Peppermint\Mailbox\Contracts\AccountStore;
use Peppermint\Mailbox\Threading\ThreadKey;
use Peppermint\Mailbox\Werkzeuge\GesamtWerkzeuge;
use Peppermint\Mailbox\Werkzeuge\Vorgang;

/**
 * Die Werkzeugleiste an einer Mail — eigene Verben und die der anderen
 * Systeme, in EINER Leiste.
 *
 * ## Warum nicht „HandlesActions"
 *
 * Weil {@see HandlesMailboxActions} schon so heisst und etwas anderes meint:
 * gelesen, markiert, verschoben, geloescht — was man mit einer Mail IM
 * Postfach tut. Hier geht es um das, was ein Produkt MIT ihr tut. Zwei
 * Begriffe, die beide „Aktion" heissen, sind der Weg in eine Ansicht, in der
 * niemand mehr weiss, welcher Endpunkt gemeint ist.
 *
 * ## Zwei Wege, und sie haben verschiedene Rechte
 *
 * - `GET mailbox/{account}/tools` — welche Verben gibt es hier?
 * - `POST mailbox/{account}/tools/run` — eines ausloesen.
 *
 * Das erste zeigt, das zweite legt etwas an. Beim Ausloesen prueft zusaetzlich
 * das Zielsystem selbst — eine Liste, die durch vier Systeme gereist ist, ist
 * eine Behauptung und keine Erlaubnis.
 *
 * ## Der Riegel ist Pflicht, nicht Option
 *
 * {@see guardWerkzeuge()} ist abstrakt — anders als bei den Zuweisungen, wo
 * ein offener Standard reicht. Grund: Diese Wege verraten nicht nur, was
 * moeglich ist, sie loesen es auch aus, und zwar in FREMDEN Systemen. Ein
 * vergessener Riegel waere hier kein zu weit geoeffnetes Postfach, sondern
 * ein Beleg in der Buchhaltung eines Kollegen.
 *
 * Zweimal hat dieses Projekt fremde Posteingaenge geoeffnet, weil eine
 * Pruefung uebernommen statt gestellt wurde (Memories #4003, #4009). Ein
 * Riegel, den man implementieren MUSS, kann nicht uebersehen werden.
 */
trait HandlesWerkzeuge
{
    /**
     * Wer gerade fragt. Ueblicherweise `$request->user()`.
     *
     * Ein Model, kein Bezeichner: Das andere System findet den Menschen ueber
     * seine Adresse, und die steht am Model.
     */
    abstract protected function werkzeugPerson(): ?Model;

    /**
     * Darf diese Person in diesem Postfach arbeiten?
     *
     * Wirft, wenn nicht — ueblicherweise `abort(403)`. Siehe Klassentext,
     * warum das hier kein optionaler Haken ist.
     */
    abstract protected function guardWerkzeuge(int $account): void;

    /**
     * Welche Kette gemeint ist.
     *
     * Standardmaessig aus den Kopfzeilen, die der Browser mitschickt.
     *
     * ## Ein Produkt mit mehr Wissen hat es nur EINMAL
     *
     * Der Projekt-Manager fuehrt ein Kopfzeilen-Verzeichnis und findet die
     * Wurzel einer Kette auch ohne `In-Reply-To`. Dieses Wissen steckt schon
     * in {@see HandlesAssignments::threadKeyFor()}. Deshalb wird es hier
     * benutzt, wenn der Controller beide Merkmale fuehrt, statt daneben eine
     * zweite Auflosung zu stellen.
     *
     * Das ist nicht Bequemlichkeit: Zwei Stellen, die „dieselbe" Entscheidung
     * treffen, sind zwei Regeln, die heute uebereinstimmen (Memory #4416).
     * Liefen sie auseinander, haenge die Zuweisung an der einen Kette und das
     * Ticket an der anderen — und niemand bekaeme eine Fehlermeldung.
     *
     * @param  array<string, mixed>  $daten
     */
    protected function werkzeugKette(int $account, array $daten): ?string
    {
        if (method_exists($this, 'threadKeyFor')) {
            return $this->threadKeyFor($account, $daten);
        }

        return ThreadKey::fromHeaders(
            (string) ($daten['message_id'] ?? ''),
            $daten['in_reply_to'] ?? null,
            $daten['references'] ?? null,
        );
    }

    /**
     * Die Werkzeuge fuer eine Nachricht.
     */
    public function tools(Request $request, int $account): JsonResponse
    {
        $this->guardWerkzeuge($account);

        $daten = $request->validate([
            'message_id' => ['required', 'string', 'max:255'],
            'in_reply_to' => ['nullable', 'string', 'max:998'],
            'references' => ['nullable', 'string'],
            'subject' => ['nullable', 'string', 'max:998'],
            'from' => ['nullable', 'string', 'max:998'],
        ]);

        $person = $this->werkzeugPerson();

        if ($person === null) {
            return response()->json(['tools' => [], 'unreachable' => [], 'failed' => []]);
        }

        return response()->json(
            app(GesamtWerkzeuge::class)->fuer($this->vorgang($account, $daten), $person)
        );
    }

    /**
     * Ein Werkzeug ausloesen.
     */
    public function runTool(Request $request, int $account): JsonResponse
    {
        $this->guardWerkzeuge($account);

        $daten = $request->validate([
            'action' => ['required', 'string', 'max:255'],
            // Leer heisst „eigenes Werkzeug". Ein Kuerzel heisst „genau
            // dieses System" — nie „irgendwer, der das kann": „Aufgabe
            // anhaengen" gibt es in zwei Systemen, und welches gemeint war,
            // hat der Nutzer beim Klick entschieden.
            'system' => ['nullable', 'string', 'max:64'],
            'message_id' => ['required', 'string', 'max:255'],
            'in_reply_to' => ['nullable', 'string', 'max:998'],
            'references' => ['nullable', 'string'],
            'subject' => ['nullable', 'string', 'max:998'],
            'from' => ['nullable', 'string', 'max:998'],
            'input' => ['nullable', 'array'],
        ]);

        $person = $this->werkzeugPerson();

        if ($person === null) {
            // Kein angemeldeter Mensch, aber ein Vorgang, der etwas anlegen
            // soll. Das ist kein Grenzfall, den man still durchlaesst.
            return response()->json(['ok' => false, 'message' => 'Nicht angemeldet.'], 401);
        }

        $ergebnis = app(GesamtWerkzeuge::class)->ausfuehren(
            $daten['system'] ?? null,
            $daten['action'],
            $this->vorgang($account, $daten),
            is_array($daten['input'] ?? null) ? $daten['input'] : [],
            $person,
        );

        // 200 auch beim Fehlschlag: Ein Werkzeug, das nicht konnte, ist eine
        // Antwort und kein Serverfehler. Die Oberflaeche liest `ok` und zeigt
        // den Satz — ein 500 haette daraus eine rote Wolke ohne Erklaerung
        // gemacht.
        return response()->json($ergebnis->toArray());
    }

    /**
     * @param  array<string, mixed>  $daten
     */
    private function vorgang(int $account, array $daten): Vorgang
    {
        return new Vorgang(
            // Die ADRESSE, nicht die Nummer: Dasselbe Postfach hat in jedem
            // System eine andere Kennung. Das ist die erste Falle in
            // docs/INTEGRATION.md, und sie faellt lautlos aus.
            postfach: mb_strtolower((string) (app(AccountStore::class)->find($account)?->field('email') ?? '')),
            kette: $this->werkzeugKette($account, $daten),
            nachricht: (string) $daten['message_id'],
            betreff: $daten['subject'] ?? null,
            absender: $daten['from'] ?? null,
        );
    }
}
