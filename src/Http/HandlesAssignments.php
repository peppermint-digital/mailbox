<?php

namespace Peppermint\Mailbox\Http;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Peppermint\Mailbox\Models\MailAssignment;
use Peppermint\Mailbox\Threading\ThreadKey;

/**
 * Wer sich um welche Unterhaltung kuemmert.
 *
 * ## Warum das ins Paket gehoert
 *
 * Ein Gruppenpostfach ohne Zuordnung ist ein Raum, in dem alle gleichzeitig
 * anfangen oder alle warten. Bis zum 22.09.2026 konnte das nur der
 * Projekt-Manager — dabei hat jedes Produkt Nutzer, und jedes Produkt hat
 * Gruppenpostfaecher.
 *
 * ## Was das Produkt beisteuert
 *
 * `assignableUsers()` — WER ueberhaupt in Frage kommt. Das kann das Paket
 * nicht wissen: Es kennt weder die Nutzertabelle noch die Regeln, nach denen
 * jemand ein Postfach sehen darf. Die Liste ist zugleich die Pruefung: Wer
 * nicht darin steht, bekommt nichts zugewiesen.
 *
 * Dazu `mailboxFor()` und `mailboxFailure()` wie bei den uebrigen Aktionen —
 * die stehen in {@see HandlesMailboxActions}, und ein Controller, der beide
 * Merkmale benutzt, deklariert sie einmal.
 *
 * ## Zugewiesen wird die KETTE
 *
 * Sonst ist die naechste Antwort in derselben Sache wieder niemandem
 * zugeordnet, und dieselbe Unterhaltung wird zweimal sortiert. Die
 * Kettenkennung kommt aus den Kopfzeilen, die der Browser mitschickt — so
 * braucht es dafuer keinen zweiten Zugriff aufs Postfach.
 */
trait HandlesAssignments
{
    /**
     * Die Personen, denen in diesem Postfach etwas zugewiesen werden darf.
     *
     * @return list<array{id: int, name: string}>
     */
    abstract protected function assignableUsers(int $account): array;

    /**
     * Wer gerade fragt. Ueblicherweise `auth()->id()`.
     */
    abstract protected function currentUserId(): ?int;

    /**
     * Der Riegel vor JEDEM Weg dieses Merkmals.
     *
     * Standardmaessig offen — die Zuteilung des Postfachs regelt schon, wer es
     * ueberhaupt sieht. Produkte mit strengeren Regeln setzen ihn hier, an
     * EINER Stelle.
     *
     * Dass es ihn gibt, hat einen Anlass: Die Verwaltung verlangt `canEdit()`
     * fuers Lesen des Postfachs, und ihre Pruefung sitzt in `mailboxFor()`.
     * Die Zuweisungs-Wege rufen `mailboxFor()` aber gar nicht auf — sie
     * brauchen kein Postfach, nur die Datenbank. Ohne diesen Haken waeren sie
     * an der Schranke vorbei erreichbar gewesen, und zwar lautlos.
     */
    protected function guardAssignments(int $account): void {}

    /**
     * Nach einer Zuweisung. Standardmaessig passiert nichts.
     *
     * `$vorherZustaendig` ist die Person, die es VORHER war — oder `null`.
     * Damit laesst sich unterscheiden, ob jemand neu zustaendig wurde oder ob
     * nur dieselbe Zuweisung bestaetigt wurde. Der Projekt-Manager
     * benachrichtigt nur im ersten Fall; eine Nachricht bei jedem Klick
     * waere nach einer Woche stummgeschaltet.
     *
     * Ohne diesen Haken haette die Umstellung des Managers auf dieses Merkmal
     * seine Benachrichtigungen lautlos abgeschaltet — jemand bekommt Arbeit
     * zugewiesen und erfaehrt nie davon. Genau deshalb gibt es ihn.
     */
    protected function afterAssign(MailAssignment $zuweisung, ?int $vorherZustaendig, array $daten): void {}

    /**
     * Welche Kette gemeint ist.
     *
     * Standardmaessig aus den Kopfzeilen, die der Browser mitschickt — mehr
     * weiss das Paket nicht.
     *
     * Ein Produkt kann mehr wissen. Der Projekt-Manager fuehrt ein
     * Kopfzeilen-Verzeichnis und loest die Kette dagegen auf: Kommt eine
     * Anfrage ohne `In-Reply-To`, findet er trotzdem die Wurzel, waehrend das
     * Paket auf die Nachricht selbst zurueckfaellt. Beides ist richtig — nur
     * mit verschieden viel Wissen.
     *
     * Aufgefallen ist das an einem roten Test bei der Umstellung. Ohne diesen
     * Haken waere die Zuweisung dort danach an der falschen Kette gehangen:
     * kein Fehler, keine Meldung, nur eine Unterhaltung, die sich in zwei
     * teilt.
     *
     * @param  array<string, mixed>  $daten
     */
    protected function threadKeyFor(int $account, array $daten): ?string
    {
        return ThreadKey::fromHeaders(
            (string) $daten['message_id'],
            $daten['in_reply_to'] ?? null,
            $daten['references'] ?? null,
        );
    }

    /**
     * Wie eine Zuweisung nach aussen aussieht.
     *
     * Die Oberflaeche aktualisiert damit die Zeile, ohne die ganze Liste neu
     * zu holen. Produkte mit mehr Feldern ueberschreiben das.
     *
     * @return array<string, mixed>
     */
    protected function formatAssignment(MailAssignment $zuweisung): array
    {
        $name = (string) (collect($this->assignableUsers((int) $zuweisung->email_account_id))
            ->firstWhere('id', $zuweisung->assigned_to_user_id)['name'] ?? '');

        return [
            'message_id' => $zuweisung->message_id,
            'thread_id' => $zuweisung->thread_id,
            'user_id' => $zuweisung->assigned_to_user_id,
            'name' => $name,
            'initials' => static::initialen($name),
        ];
    }

    /**
     * Die Liste fuer die Auswahl.
     */
    public function assignmentUsers(int $account): JsonResponse
    {
        $this->guardAssignments($account);

        return response()->json([
            'users' => array_map(
                static fn (array $nutzer): array => $nutzer + ['initials' => static::initialen($nutzer['name'])],
                $this->assignableUsers($account),
            ),
        ]);
    }

    /**
     * Die offenen Zuweisungen dieses Postfachs, nach Kette und Nachricht.
     *
     * Beides, damit die Liste auch Zuweisungen zeigt, die vor der
     * Kettenkennung entstanden sind.
     */
    public function assignments(int $account): JsonResponse
    {
        $this->guardAssignments($account);

        $nachName = collect($this->assignableUsers($account))->keyBy('id');

        $zeilen = MailAssignment::query()
            ->where('email_account_id', $account)
            ->where('status', MailAssignment::STATUS_OPEN)
            ->get();

        return response()->json([
            'assignments' => $zeilen->map(fn (MailAssignment $a): array => [
                'message_id' => $a->message_id,
                'thread_id' => $a->thread_id,
                'user_id' => $a->assigned_to_user_id,
                'name' => (string) ($nachName[$a->assigned_to_user_id]['name'] ?? ''),
                'initials' => static::initialen((string) ($nachName[$a->assigned_to_user_id]['name'] ?? '')),
            ])->values(),
        ]);
    }

    /**
     * Eine Unterhaltung jemandem zuweisen.
     */
    public function assign(Request $request, int $account): JsonResponse
    {
        $this->guardAssignments($account);

        $daten = $request->validate([
            'message_id' => ['required', 'string', 'max:255'],
            'assigned_to_user_id' => ['required', 'integer'],
            // Die Kopfzeilen der Nachricht: Damit laesst sich die Kette ohne
            // zweiten Postfach-Zugriff bestimmen.
            'in_reply_to' => ['nullable', 'string', 'max:998'],
            'references' => ['nullable', 'string'],
        ]);

        // Die Liste der zulaessigen Personen IST die Pruefung. Eine zweite
        // daneben liefe irgendwann auseinander.
        $erlaubt = collect($this->assignableUsers($account))->pluck('id')->map(fn ($id) => (int) $id);

        if (! $erlaubt->contains((int) $daten['assigned_to_user_id'])) {
            return response()->json(['message' => 'Diese Person hat keinen Zugriff auf das Postfach.'], 422);
        }

        $kette = $this->threadKeyFor($account, $daten);

        // Wer es VORHER war — vor dem Schreiben gelesen, sonst steht dort
        // schon die neue Person und der Vergleich unten geht immer aus.
        $vorher = MailAssignment::query()
            ->where('email_account_id', $account)
            ->when($kette !== null, fn ($f) => $f->where('thread_id', $kette))
            ->when($kette === null, fn ($f) => $f->where('message_id', $daten['message_id']))
            ->value('assigned_to_user_id');

        $zuweisung = MailAssignment::updateOrCreate(
            [
                'email_account_id' => $account,
                // Ohne Kettenkennung bleibt die Nachricht der Schluessel.
                ...($kette !== null ? ['thread_id' => $kette] : ['message_id' => $daten['message_id']]),
            ],
            [
                'message_id' => $daten['message_id'],
                'thread_id' => $kette,
                'assigned_to_user_id' => (int) $daten['assigned_to_user_id'],
                'assigned_by_user_id' => $this->currentUserId(),
                'status' => MailAssignment::STATUS_OPEN,
            ],
        );

        $this->afterAssign($zuweisung, $vorher !== null ? (int) $vorher : null, $daten);

        return response()->json([
            'success' => true,
            'assignment' => $this->formatAssignment($zuweisung),
        ]);
    }

    /**
     * Die Zuweisung aufheben.
     */
    public function unassign(Request $request, int $account): JsonResponse
    {
        $this->guardAssignments($account);

        $daten = $request->validate([
            'message_id' => ['required', 'string', 'max:255'],
            'in_reply_to' => ['nullable', 'string', 'max:998'],
            'references' => ['nullable', 'string'],
        ]);

        $kette = $this->threadKeyFor($account, $daten);

        // Die Suche nach der `message_id` bleibt daneben stehen, damit auch
        // Zuweisungen ohne Kettenkennung verschwinden.
        MailAssignment::query()
            ->where('email_account_id', $account)
            ->where(function ($frage) use ($kette, $daten) {
                $frage->where('message_id', $daten['message_id']);

                if ($kette !== null) {
                    $frage->orWhere('thread_id', $kette);
                }
            })
            ->delete();

        return response()->json(['success' => true]);
    }

    /**
     * „Anna Meier" wird zu „AM".
     */
    protected static function initialen(string $name): string
    {
        $teile = preg_split('/\s+/', trim($name)) ?: [];
        $teile = array_values(array_filter($teile));

        if ($teile === []) {
            return '?';
        }

        $erste = mb_substr($teile[0], 0, 1);
        $letzte = count($teile) > 1 ? mb_substr($teile[count($teile) - 1], 0, 1) : '';

        return mb_strtoupper($erste.$letzte);
    }
}
