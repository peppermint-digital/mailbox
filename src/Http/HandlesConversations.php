<?php

namespace Peppermint\Mailbox\Http;

use Illuminate\Http\JsonResponse;
use Peppermint\Mailbox\Contracts\Verlaufsspeicher;

/**
 * Der Gespraechsverlauf: eine Kette, gelesen wie ein Chat.
 *
 * ## Was er zeigt
 *
 * Je Nachricht das, was ein Mensch geschrieben hat — ohne zitierten
 * Vorgaengertext, ohne Signatur, ohne Haftungshinweis. Beim zehnten Hin und
 * Her steht der eine neue Satz sonst am Anfang von achtzig Zeilen
 * Wiederholung.
 *
 * Getrennt wurde beim Erfassen, nicht beim Anzeigen: Die Aufbereitung ist
 * teuer und verbessert sich, und ein Ergebnis, das bei jedem Blick neu
 * entsteht, kann man weder nacharbeiten noch pruefen.
 *
 * ## Warum eine Kette ohne Anfang KEINEN Verlauf bekommt
 *
 * Die Ablage beginnt an einem Stichtag. Eine Konversation, die davor begann,
 * liegt nur teilweise darin — und genau das darf der Verlauf nicht zeigen.
 *
 * Ein Chat, der mit der dritten Antwort anfaengt, sieht nicht aus wie
 * „unvollstaendig", sondern wie „so war es". Wer daraufhin urteilt, urteilt
 * ueber etwas, das er nie ganz gesehen hat. Lieber gar nicht anbieten und den
 * Menschen in der gewohnten Ansicht lassen, in der er die ganze Mail sieht.
 *
 * {@see verfuegbar()} beantwortet das — und die Oberflaeche fragt es, BEVOR
 * sie den Knopf zeigt.
 */
trait HandlesConversations
{
    abstract protected function currentUserId(): ?int;

    /**
     * Der Riegel vor beiden Wegen — standardmaessig offen.
     *
     * Dieselbe Begruendung wie bei den Zuweisungen: Diese Wege brauchen kein
     * Postfach, nur die Datenbank, und gingen damit an einer Schranke vorbei,
     * die in `mailboxFor()` sitzt.
     */
    protected function guardConversations(int $account): void {}

    /**
     * Gibt es zu dieser Kette einen vollstaendigen Verlauf?
     *
     * Die Oberflaeche fragt das fuer die geoeffnete Nachricht und zeigt den
     * Knopf nur dann. Billig genug dafuer: eine Zaehlung, keine Inhalte.
     */
    public function conversationAvailable(int $account, string $thread): JsonResponse
    {
        $this->guardConversations($account);

        return response()->json([
            'available' => $this->verfuegbar($account, $thread),
        ]);
    }

    /**
     * Der Verlauf einer Kette.
     */
    public function conversation(int $account, string $thread): JsonResponse
    {
        $this->guardConversations($account);

        if (! $this->verfuegbar($account, $thread)) {
            // 409 und nicht 404: Die Kette gibt es, nur nicht vollstaendig
            // genug. „Nicht gefunden" schickte jemanden auf die Suche nach
            // einem Fehler, der keiner ist.
            return response()->json([
                'available' => false,
                'message' => 'Diese Konversation begann, bevor die Ablage lief — der Verlauf wäre unvollständig.',
            ], 409);
        }

        return response()->json([
            'available' => true,
            'entries' => $this->verlaufsspeicher()->verlauf($account, $thread),
        ]);
    }

    /**
     * Liegt der ANFANG der Kette in der Ablage?
     *
     * Erkannt an `is_root`: Beim Erfassen wird ausgerechnet, ob der
     * Kettenschluessel auf die Nachricht selbst zeigt — dann hat sie keinen
     * Vorgaenger und ist der Anfang.
     *
     * Beim Lesen zu vergleichen waere der naheliegende Weg und der falsche:
     * Der Schluessel ist normalisiert (`abc@x`), die Message-ID nicht
     * (`<abc@x>`). Der Vergleich waere nie wahr, und der Verlauf saehe nicht
     * kaputt aus, sondern wie „es gibt eben noch keine vollstaendige Kette".
     */
    protected function verfuegbar(int $account, string $thread): bool
    {
        return $this->verlaufsspeicher()->verfuegbar($account, $thread);
    }

    /**
     * Woher der Verlauf kommt.
     *
     * Ueberschreibbar, aber im Regelfall reicht die Bindung: Wer archiviert,
     * bekommt den lokalen Speicher; wer nicht, den ueber die Bridge. Dieselbe
     * Aufteilung wie bei den Postfaechern.
     */
    protected function verlaufsspeicher(): Verlaufsspeicher
    {
        return app(Verlaufsspeicher::class);
    }
}
