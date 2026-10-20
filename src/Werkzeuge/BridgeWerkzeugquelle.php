<?php

namespace Peppermint\Mailbox\Werkzeuge;

use Peppermint\AiBrainBridge\Facades\AiBrain;
use Peppermint\AiBrainBridge\Verbund\Bruecke;

/**
 * Die Produkt-Seite: ueber die Bruecke beim AI Brain fragen.
 *
 * Das Brain steht BEWUSST in der Liste, obwohl es kein ConnectedProduct ist
 * und in keinem Verzeichnis der Faehigkeit auftaucht. Es ist die Nabe, und es
 * hat selbst einen Mailbrowser mit eigenen Verben — ohne diesen Eintrag saehe
 * ein Produkt die Werkzeuge aller anderen, nur nicht die der Mitte.
 *
 * ## Die Rechte entscheidet immer die Gegenstelle
 *
 * Hier wird nichts geprueft ausser der Frage, welche Systeme ueberhaupt
 * antworten duerfen — das sagt `providers()`, also das Faehigkeits-Register
 * in Brain. Ob DIESE Person DIESES Verb ausloesen darf, entscheidet das
 * Zielsystem, mit seinen eigenen Rollen und seiner eigenen Postfach-Freigabe.
 *
 * Das ist keine Bequemlichkeit, sondern die Lehre aus zwei Vorfaellen
 * (Memories #4003, #4009): Eine Sichtbarkeitsregel, die anderswo
 * nachgebildet wird, laeuft auseinander — und dann oeffnet die Nachbildung
 * etwas, das das Original zugehalten haette.
 */
class BridgeWerkzeugquelle implements Werkzeugquelle
{
    private const HUB_SLUG = 'ai-brain';

    private const HUB = ['slug' => self::HUB_SLUG, 'key' => self::HUB_SLUG, 'label' => 'AI Brain'];

    private const WERKZEUG_LISTE = 'list-mailbox-actions-tool';

    private const WERKZEUG_AUSLOESEN = 'run-mailbox-action-tool';

    public function anbieter(): array
    {
        if (! Bruecke::eingerichtet()) {
            // Die Bruecke ist Pflicht, ihre EINRICHTUNG nicht garantiert:
            // AI Brain hat das Paket, ohne es einzurichten — es ist der Hub.
            // Dann bleiben die eigenen Verben, und zwar vollstaendig.
            return [];
        }

        return array_merge([self::HUB], AiBrain::providers(FremdeWerkzeuge::CAPABILITY));
    }

    public function holen(string $slug, Vorgang $vorgang, string $adresse): array
    {
        if ($slug === self::HUB_SLUG) {
            // Die Nabe wird direkt gefragt, nicht ueber den Gateway: Sie
            // wuerde sich sonst selbst weiterreichen.
            return Auspacken::liste((array) AiBrain::call(self::WERKZEUG_LISTE, $vorgang->toArray()));
        }

        $antwort = AiBrain::gateway(
            FremdeWerkzeuge::CAPABILITY,
            $vorgang->toArray(),
            $slug,
            $adresse,
        );

        return Auspacken::liste(is_array($antwort) ? $antwort : []);
    }

    public function ausloesen(string $slug, string $schluessel, Vorgang $vorgang, array $daten, string $adresse): array
    {
        // Der Schluessel reist OHNE Systemkuerzel: Drueben ist er in seinem
        // eigenen Haus eindeutig, und das Kuerzel ist eine Sicht von hier.
        // Schickte man es mit, muesste jedes System wissen, wie es anderswo
        // heisst.
        $nutzlast = $vorgang->toArray() + ['action' => $schluessel, 'input' => $daten];

        if ($slug === self::HUB_SLUG) {
            return Auspacken::ergebnis((array) AiBrain::call(self::WERKZEUG_AUSLOESEN, $nutzlast));
        }

        $antwort = AiBrain::gateway(
            FremdeWerkzeuge::CAPABILITY_RUN,
            $nutzlast,
            $slug,
            $adresse,
        );

        return Auspacken::ergebnis(is_array($antwort) ? $antwort : []);
    }
}
