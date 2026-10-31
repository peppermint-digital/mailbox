<?php

namespace Peppermint\Mailbox\Http;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Peppermint\Mailbox\Contracts\Diktat;
use Peppermint\Mailbox\Contracts\Schreibhilfe;

/**
 * Die zwei Endpunkte am Editor: Text verbessern und Gesprochenes abtippen.
 *
 * ## Was das Produkt beisteuert
 *
 * Die Dienste — und nur die. `schreibhilfe()` und `diktat()` geben zurueck,
 * was antworten soll, oder `null`. **`null` ist kein Fehler, sondern die
 * Antwort „gibt es hier nicht":** Die Oberflaeche fragt danach und zeigt den
 * Knopf dann gar nicht erst. Ein Knopf ohne Wirkung sieht aus wie ein Defekt,
 * nicht wie eine fehlende Funktion.
 *
 * Die Vorgabe fuer beide ist `null`. Ein Produkt, das nichts bindet, bekommt
 * eine funktionierende Mailseite ohne Assistenten — und keine kaputte.
 *
 * ## Warum die Verfuegbarkeit einen eigenen Endpunkt hat
 *
 * Weil die Oberflaeche es wissen muss, BEVOR sie zeichnet. Die Alternative
 * waere, den Knopf immer zu zeigen und beim ersten Klick 503 zu antworten —
 * also genau das, was oben als Defekt beschrieben ist.
 */
trait HilftBeimSchreiben
{
    /** Der Dienst, der Text verbessert. `null` heisst: hier gibt es keinen. */
    protected function schreibhilfe(): ?Schreibhilfe
    {
        return null;
    }

    /** Der Dienst, der Gesprochenes abtippt. `null` heisst: hier gibt es keinen. */
    protected function diktat(): ?Diktat
    {
        return null;
    }

    /**
     * Was dieser Editor kann — gefragt, bevor gezeichnet wird.
     */
    public function editorDienste(): JsonResponse
    {
        return response()->json([
            'schreibhilfe' => $this->schreibhilfe() !== null,
            'diktat' => $this->diktat() !== null,
        ]);
    }

    public function textVerbessern(Request $request): JsonResponse
    {
        $daten = $request->validate([
            'text' => ['required', 'string', 'max:50000'],
            'mode' => ['required', 'string', 'in:correct,rephrase'],
            'style' => ['nullable', 'string', 'in:formal,friendly,shorter'],
        ]);

        $dienst = $this->schreibhilfe();

        if ($dienst === null) {
            return response()->json(['message' => 'Der Schreibassistent ist hier nicht eingerichtet.'], 503);
        }

        try {
            return response()->json(['result' => $dienst->verarbeiten(
                $daten['text'],
                $daten['mode'],
                $daten['style'] ?? null,
            )]);
        } catch (\Throwable $e) {
            report($e);

            /*
             * Der Satz des Dienstes, wenn er einen hat. Ein Modell, das „zu
             * lang" oder „gerade ueberlastet" sagt, sagt etwas Brauchbares —
             * „Fehler" sagt nichts.
             */
            return response()->json(['message' => $e->getMessage() ?: 'Der Schreibassistent antwortet nicht.'], 502);
        }
    }

    public function diktatAbtippen(Request $request): JsonResponse
    {
        $daten = $request->validate([
            // 25 MB: Zwei Minuten Opus liegen bei rund 2 MB. Die Grenze ist
            // also reichlich und faengt trotzdem die Datei ab, die niemand
            // abtippen lassen wollte.
            'audio' => ['required', 'file', 'max:25600'],
        ]);

        $dienst = $this->diktat();

        if ($dienst === null) {
            return response()->json(['message' => 'Die Spracheingabe ist hier nicht eingerichtet.'], 503);
        }

        /** @var UploadedFile $datei */
        $datei = $daten['audio'];

        try {
            return response()->json(['text' => $dienst->abtippen(
                (string) $datei->getRealPath(),
                (string) ($datei->getMimeType() ?: 'application/octet-stream'),
            )]);
        } catch (\Throwable $e) {
            report($e);

            return response()->json(['message' => $e->getMessage() ?: 'Die Spracheingabe antwortet nicht.'], 502);
        }
        // Abgelegt wird nichts: Die hochgeladene Datei raeumt Laravel am Ende
        // der Anfrage weg, und das ist genau richtig. Was gesprochen wurde,
        // gehoert dem Menschen, der es gesprochen hat.
    }
}
