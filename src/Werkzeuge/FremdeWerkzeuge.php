<?php

namespace Peppermint\Mailbox\Werkzeuge;

use Illuminate\Support\Facades\Log;

/**
 * Die Werkzeuge der anderen Systeme holen und zusammenlegen.
 *
 * ## Die zwei Faehigkeiten
 *
 * `mailbox.actions` — was kannst du an dieser Mail?
 * `mailbox.actions.run` — tu es.
 *
 * Getrennt, weil es zwei verschiedene Rechte sind: Lesen, welche Verben es
 * gibt, ist harmlos; ein Verb ausloesen legt etwas an. Ein System, das nur
 * die erste Faehigkeit freigibt, zeigt seine Verben, laesst sie aber nicht
 * von aussen ausloesen — und das ist eine zulaessige Haltung, keine
 * Fehlkonfiguration.
 *
 * ## Fail-open, aber nicht stumm
 *
 * Ein System, das nicht antwortet, kostet seine Verben — nicht die Leiste.
 * Es steht aber im Ergebnis, denn eine still verkuerzte Leiste sieht aus wie
 * „hier ist nichts zu tun", und dann sucht man im falschen System.
 *
 * ## Niemand loest im Blindflug aus
 *
 * {@see ausloesen()} nennt das Zielsystem ausdruecklich. Der Gateway kann
 * auch ohne — „irgendwer, der das kann" —, und genau das waere hier falsch:
 * „Aufgabe anhaengen" gibt es in zwei Systemen, und welches gemeint war,
 * hat der Nutzer entschieden, als er den Knopf gedrueckt hat.
 */
class FremdeWerkzeuge
{
    public const CAPABILITY = 'mailbox.actions';

    public const CAPABILITY_RUN = 'mailbox.actions.run';

    public function __construct(private readonly Werkzeugquelle $quelle) {}

    /**
     * @return array{werkzeuge: array<int, Werkzeug>, unreachable: array<int, string>, failed: array<int, string>}
     */
    public function holen(Vorgang $vorgang, string $adresse): array
    {
        $gefunden = [];
        $stumm = [];
        $gescheitert = [];

        foreach ($this->quelle->anbieter() as $anbieter) {
            $schluessel = (string) ($anbieter['key'] ?? $anbieter['slug'] ?? '');
            $slug = (string) ($anbieter['slug'] ?? $schluessel);
            $wort = (string) ($anbieter['label'] ?? $schluessel);

            if ($slug === '') {
                continue;
            }

            try {
                $antwort = $this->quelle->holen($slug, $vorgang, $adresse);
            } catch (\Throwable $fehler) {
                $stumm[] = $wort;

                Log::warning('System hat seine Mail-Werkzeuge nicht herausgegeben.', [
                    'system' => $slug,
                    'error' => $fehler->getMessage(),
                ]);

                continue;
            }

            foreach ($antwort['tools'] as $roh) {
                $werkzeug = Werkzeug::aus(is_array($roh) ? $roh : []);

                if ($werkzeug === null) {
                    continue;
                }

                // Die Herkunft setzt der FRAGENDE, nicht der Befragte — und
                // der Schluessel des Systems ist zugleich die Adresse fuers
                // Ausloesen. Duerfte ein System sich selbst benennen,
                // stuenden in vier Oberflaechen vier verschiedene Woerter,
                // und ein Verb liesse sich nicht mehr zustellen.
                $gefunden[] = $werkzeug->vonSystem($schluessel, $wort);
            }

            foreach (($antwort['failed'] ?? []) as $name) {
                // Mit dem Systemnamen davor, sonst steht in der Ansicht
                // „Beleg" und niemand weiss, in welchem der vier Systeme man
                // nachsehen soll.
                $gescheitert[] = $wort.': '.$name;
            }
        }

        return [
            'werkzeuge' => $gefunden,
            'unreachable' => $stumm,
            'failed' => $gescheitert,
        ];
    }

    /**
     * @param  array<string, mixed>  $daten
     */
    public function ausloesen(string $system, string $schluessel, Vorgang $vorgang, array $daten, string $adresse): Ergebnis
    {
        $slug = $this->slugVon($system);

        if ($slug === null) {
            // Ein System, das die Leiste eben noch angeboten hat und jetzt
            // nicht mehr im Register steht. Als Erfolg zu antworten waere
            // die schlimmere Luege.
            return Ergebnis::gescheitert('Dieses System ist gerade nicht erreichbar.');
        }

        try {
            return Ergebnis::aus($this->quelle->ausloesen($slug, $schluessel, $vorgang, $daten, $adresse));
        } catch (\Throwable $fehler) {
            Log::warning('Fremdes Mail-Werkzeug liess sich nicht ausloesen.', [
                'system' => $slug,
                'key' => $schluessel,
                'error' => $fehler->getMessage(),
            ]);

            // Bewusst vage: Was drueben schiefging, weiss dieses System
            // nicht — und „unbekannter Fehler im CRM" ist ehrlicher als eine
            // erfundene Ursache. Der Grund steht im Log.
            return Ergebnis::gescheitert('Das andere System hat den Vorgang nicht angenommen.');
        }
    }

    /**
     * Vom Kuerzel, das die Oberflaeche zurueckschickt, auf das Zielsystem.
     *
     * Ueber das Register, nicht durch Vertrauen: Die Oberflaeche schickt
     * zurueck, was sie bekommen hat, und ein Kuerzel aus einer alten,
     * offenen Registerkarte zeigt sonst auf ein System, das dieser Nutzer
     * nicht mehr benutzen darf.
     */
    private function slugVon(string $system): ?string
    {
        foreach ($this->quelle->anbieter() as $anbieter) {
            $schluessel = (string) ($anbieter['key'] ?? $anbieter['slug'] ?? '');

            if ($schluessel !== '' && $schluessel === $system) {
                return (string) ($anbieter['slug'] ?? $schluessel);
            }
        }

        return null;
    }
}
