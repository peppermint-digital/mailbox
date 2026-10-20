<?php

namespace Peppermint\Mailbox\Werkzeuge;

/**
 * Was ein ausgeloestes Werkzeug zurueckmeldet.
 *
 * ## Warum ein Fehlschlag keine Ausnahme ist
 *
 * Ein Werkzeug, das nicht konnte, ist ein normaler Ausgang — das Ticketsystem
 * ist gerade nicht erreichbar, der Beleg hat kein Datum, die Aufgabe braucht
 * ein Projekt. Als Ausnahme geworfen landet das als 500 in der Oberflaeche und
 * sieht aus wie ein Programmfehler. Hier ist es eine Antwort mit `gelungen:
 * false` und einem Satz, den man dem Nutzer zeigen kann.
 *
 * ## `wort` ist fuer Menschen, `zustand` fuer die Leiste
 *
 * `wort` ist die Rueckmeldung („Beleg #4711 angelegt"). `zustand` ist, was aus
 * dem Knopf wird: Steht dort etwas, zeigt die Leiste danach den Verweis statt
 * des Verbs — und ein zweiter Klick legt nicht ein zweites Ticket an.
 *
 * Das ist die Stelle, an der aus „beauftragt" nicht „erledigt" werden darf
 * (Wiki: Ein Werkzeug darf „beauftragt" nicht als „erledigt" verkaufen). Wer
 * eine Aufgabe in eine Warteschlange legt, setzt `gelungen: true` und ein
 * `wort`, das das sagt — aber keinen `zustand`, solange nichts existiert.
 */
final class Ergebnis
{
    /**
     * @param  array{wort: string, verweis?: string|null}|null  $zustand
     * @param  array<string, mixed>  $daten  Was das Produkt der Oberflaeche zusaetzlich mitgibt.
     */
    private function __construct(
        public readonly bool $gelungen,
        public readonly string $wort,
        public readonly ?array $zustand = null,
        public readonly array $daten = [],
    ) {}

    /**
     * @param  array<string, mixed>  $daten
     */
    public static function gelungen(string $wort, ?string $verweis = null, ?string $zustandWort = null, array $daten = []): self
    {
        return new self(
            gelungen: true,
            wort: $wort,
            // Ohne eigenes Wort fuer den Zustand steht die Rueckmeldung auch
            // auf dem Knopf. Das ist meistens richtig („Beleg #4711") und
            // immer besser als ein Knopf, der weiter zum Anlegen einlaedt.
            zustand: $verweis === null && $zustandWort === null ? null : [
                'wort' => $zustandWort ?? $wort,
                'verweis' => $verweis,
            ],
            daten: $daten,
        );
    }

    public static function gescheitert(string $wort): self
    {
        return new self(gelungen: false, wort: $wort);
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'ok' => $this->gelungen,
            'message' => $this->wort,
            'state' => $this->zustand === null ? null : [
                'label' => $this->zustand['wort'],
                'url' => $this->zustand['verweis'] ?? null,
            ],
            'data' => $this->daten,
        ];
    }

    /**
     * @param  array<string, mixed>  $roh
     */
    public static function aus(array $roh): self
    {
        $wort = (string) ($roh['message'] ?? '');

        if (($roh['ok'] ?? false) !== true) {
            // Ein leerer Satz ist schlimmer als ein unschoener: Eine
            // Fehlermeldung ohne Text sieht in der Oberflaeche aus wie ein
            // gelungener Vorgang, bei dem nur nichts passiert ist.
            return self::gescheitert($wort !== '' ? $wort : 'Das Werkzeug hat nicht geantwortet.');
        }

        $zustand = is_array($roh['state'] ?? null) && is_string($roh['state']['label'] ?? null)
            ? ['wort' => (string) $roh['state']['label'], 'verweis' => $roh['state']['url'] ?? null]
            : null;

        return new self(
            gelungen: true,
            wort: $wort,
            zustand: $zustand,
            daten: is_array($roh['data'] ?? null) ? $roh['data'] : [],
        );
    }
}
