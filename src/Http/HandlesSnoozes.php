<?php

namespace Peppermint\Mailbox\Http;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Peppermint\Mailbox\Models\MailSnooze;
use Peppermint\Mailbox\Threading\ThreadKey;

/**
 * „Nicht jetzt — erinnere mich wieder ab dann."
 *
 * ## Pro Person
 *
 * Das ist der Unterschied zur Zuweisung, und er ist wichtig: Wer in einem
 * Gruppenpostfach etwas fuer ALLE verschwinden liesse, nimmt der Kollegin
 * eine Nachricht weg, von der sie nichts weiss. Geschlummert wird fuer sich
 * selbst.
 *
 * ## Kein Hintergrundlauf
 *
 * Nichts holt eine Nachricht zurueck. Eine geschlummerte Kette wird beim
 * Anzeigen uebersprungen, solange `snooze_until` in der Zukunft liegt —
 * danach steht sie wieder da, ohne dass jemand etwas tun muss.
 *
 * Ein Lauf, der zur faelligen Zeit Zeilen umschreibt, koennte ausfallen, und
 * dann bliebe die Nachricht fuer immer verschwunden. Ein Vergleich mit der Uhr
 * kann das nicht.
 *
 * ## Ohne Kettenkennung wird nicht geschlummert
 *
 * Eine einzelne Nachricht zu verstecken hiesse: Die naechste Antwort in
 * derselben Sache taucht sofort wieder auf, und der Mensch davor haelt das
 * Schlummern fuer kaputt. Lieber eine ehrliche Absage.
 */
trait HandlesSnoozes
{
    abstract protected function currentUserId(): ?int;

    /**
     * Der Riegel vor jedem Weg — standardmaessig offen.
     *
     * Dieselbe Begruendung wie bei {@see HandlesAssignments}: Diese Wege
     * brauchen kein Postfach, nur die Datenbank, und gingen damit an einer
     * Schranke vorbei, die in `mailboxFor()` sitzt.
     */
    protected function guardSnoozes(int $account): void {}

    /**
     * Die Ketten, die fuer diese Person gerade schlummern.
     */
    public function snoozes(int $account): JsonResponse
    {
        $this->guardSnoozes($account);

        return response()->json([
            'snoozes' => MailSnooze::query()
                ->where('email_account_id', $account)
                ->where('user_id', $this->currentUserId())
                ->where('snooze_until', '>', now())
                ->get()
                ->map(fn (MailSnooze $s): array => [
                    'thread_id' => $s->thread_id,
                    'message_id' => $s->message_id,
                    'until' => $s->snooze_until?->toIso8601String(),
                ])
                ->values(),
        ]);
    }

    public function snooze(Request $request, int $account): JsonResponse
    {
        $this->guardSnoozes($account);

        $daten = $request->validate([
            'message_id' => ['required', 'string', 'max:255'],
            'until' => ['required', 'date', 'after:now'],
            'in_reply_to' => ['nullable', 'string', 'max:998'],
            'references' => ['nullable', 'string'],
        ]);

        $kette = ThreadKey::fromHeaders($daten['message_id'], $daten['in_reply_to'] ?? null, $daten['references'] ?? null);

        if ($kette === null) {
            return response()->json([
                'message' => 'Zu dieser Nachricht lässt sich keine Konversation bestimmen — sie kann nicht schlummern.',
            ], 422);
        }

        MailSnooze::updateOrCreate(
            [
                'email_account_id' => $account,
                'user_id' => $this->currentUserId(),
                'thread_id' => $kette,
            ],
            [
                'message_id' => $daten['message_id'],
                'snooze_until' => $daten['until'],
            ],
        );

        return response()->json(['success' => true]);
    }

    public function unsnooze(Request $request, int $account): JsonResponse
    {
        $this->guardSnoozes($account);

        $daten = $request->validate([
            'message_id' => ['required', 'string', 'max:255'],
            'in_reply_to' => ['nullable', 'string', 'max:998'],
            'references' => ['nullable', 'string'],
        ]);

        $kette = ThreadKey::fromHeaders($daten['message_id'], $daten['in_reply_to'] ?? null, $daten['references'] ?? null);

        MailSnooze::query()
            ->where('email_account_id', $account)
            ->where('user_id', $this->currentUserId())
            ->where(function ($frage) use ($kette, $daten) {
                $frage->where('message_id', $daten['message_id']);

                if ($kette !== null) {
                    $frage->orWhere('thread_id', $kette);
                }
            })
            ->delete();

        return response()->json(['success' => true]);
    }
}
