<?php

namespace Peppermint\Mailbox\Werkzeuge;

use Illuminate\Database\Eloquent\Model;

/**
 * Die Werkzeugleiste ueber alle Systeme.
 *
 * Eigene Verben plus die der anderen — geholt, nicht kopiert. Das ist die
 * Umsetzung der Entscheidung vom 19.10.2026: EINE Leiste, die alles traegt,
 * was dieser Person freigeschaltet ist, statt drei Ansichten, in denen sich
 * jeder Nutzer neu umgewoehnt.
 *
 * ## Warum hier NICHT zwischengespeichert wird
 *
 * Bei der Inbox werden fremde Zeilen eine Minute lang wiederverwendet — vier
 * Systeme bei jedem Seitenaufruf zu fragen kostet mehr, als die Aktualitaet
 * wert ist.
 *
 * Hier ist es umgekehrt, und zwar aus einem Grund: Die Antwort haengt am
 * VORGANG, nicht an der Person. Jede geoeffnete Mail ist eine andere Frage,
 * ein Zwischenspeicher traefe fast nie und muesste trotzdem je Mail
 * verschluesselt werden. Schlimmer: Im Zustand steht, ob das Ticket schon
 * existiert. Eine Minute alt heisst hier „der Knopf laedt noch zum Anlegen
 * ein, obwohl es das Ticket gibt" — und dann liegen zwei Tickets da.
 *
 * ## Sortiert wird nach Reihenfolge, nicht nach System
 *
 * Verben desselben Zwecks sollen beieinander stehen, auch wenn sie aus
 * verschiedenen Systemen kommen — darum erst `gruppe`, dann `reihenfolge`.
 * Nach System zu sortieren haette die Leiste wieder in die Produktkaestchen
 * zerlegt, die sie gerade aufloest.
 */
class GesamtWerkzeuge
{
    public function __construct(
        private readonly Werkzeugverzeichnis $eigene,
        private readonly FremdeWerkzeuge $fremde,
    ) {}

    /**
     * @return array{tools: array<int, array<string, mixed>>, unreachable: array<int, string>, failed: array<int, string>}
     */
    public function fuer(Vorgang $vorgang, Model $person): array
    {
        if (! $vorgang->vollstaendig()) {
            // Ohne Postfach und Nachricht kann kein System die Mail finden.
            // Eine leere Leiste ist die richtige Antwort; eine Anfrage an
            // vier Systeme mit halben Angaben waere es nicht.
            return ['tools' => [], 'unreachable' => [], 'failed' => []];
        }

        $eigen = $this->eigene->sammeln($vorgang, $person);
        $fremd = $this->fremdeWerkzeuge($vorgang, $person);

        $werkzeuge = array_merge($eigen['werkzeuge'], $fremd['werkzeuge']);

        usort($werkzeuge, $this->sortierung(...));

        return [
            'tools' => array_map(fn (Werkzeug $w): array => $w->toArray(), $werkzeuge),
            'unreachable' => $fremd['unreachable'],
            // Eigene Werkzeuge, die gerade nicht konnten. Eine andere Lage
            // als „System nicht erreichbar", deshalb ein eigenes Feld.
            'failed' => array_merge($eigen['failed'], $fremd['failed']),
        ];
    }

    /**
     * Ein Werkzeug ausloesen — eigenes oder fremdes.
     *
     * `$system` ist `null` fuer ein eigenes. Die Oberflaeche schickt genau
     * zurueck, was im Werkzeug stand, und die Weiche steht hier an EINER
     * Stelle: Zwei Endpunkte, einer fuer eigene und einer fuer fremde,
     * haetten die Oberflaeche die Unterscheidung treffen lassen — und die
     * ist ihr gleichgueltig.
     *
     * @param  array<string, mixed>  $daten
     */
    public function ausfuehren(?string $system, string $schluessel, Vorgang $vorgang, array $daten, Model $person): Ergebnis
    {
        if (! $vorgang->vollstaendig()) {
            return Ergebnis::gescheitert('Zu dieser Nachricht fehlen die Angaben, mit denen sie zu finden ist.');
        }

        if ($system === null || $system === '') {
            return $this->eigene->ausfuehren($schluessel, $vorgang, $daten, $person);
        }

        $adresse = $this->adresse($person);

        if ($adresse === null) {
            // Ohne Adresse kann das andere System nicht wissen, WER fragt —
            // und darf deshalb nichts ausloesen. Das ist keine Panne,
            // sondern der Riegel: Ein Vorgang ohne handelnde Person waere
            // ein Vorgang, den niemand verantwortet.
            return Ergebnis::gescheitert('Ohne E-Mail-Adresse am Benutzer kann kein anderes System beauftragt werden.');
        }

        return $this->fremde->ausloesen($system, $schluessel, $vorgang, $daten, $adresse);
    }

    /**
     * @return array{werkzeuge: array<int, Werkzeug>, unreachable: array<int, string>, failed: array<int, string>}
     */
    private function fremdeWerkzeuge(Vorgang $vorgang, Model $person): array
    {
        $adresse = $this->adresse($person);

        if ($adresse === null) {
            // Ohne Adresse kann kein anderes System den Menschen finden —
            // und wuerde es fragen, bekaeme es eine Liste fuer „irgendwen".
            // Die eigenen Verben bleiben, und zwar vollstaendig.
            return ['werkzeuge' => [], 'unreachable' => [], 'failed' => []];
        }

        return $this->fremde->holen($vorgang, $adresse);
    }

    private function adresse(Model $person): ?string
    {
        $adresse = $person->getAttribute('email');

        if (! is_string($adresse) || trim($adresse) === '') {
            return null;
        }

        return mb_strtolower(trim($adresse));
    }

    private function sortierung(Werkzeug $a, Werkzeug $b): int
    {
        return [$a->gruppe ?? '', $a->reihenfolge, $a->wort]
            <=> [$b->gruppe ?? '', $b->reihenfolge, $b->wort];
    }
}
