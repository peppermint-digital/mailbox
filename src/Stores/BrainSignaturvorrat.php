<?php

namespace Peppermint\Mailbox\Stores;

use Illuminate\Support\Facades\Log;
use Peppermint\Mailbox\Contracts\Signaturvorrat;
use Peppermint\Mailbox\Mcp\HandelndePerson;
use Peppermint\Mailbox\Models\MailAccount;
use Peppermint\Mailbox\Werkzeuge\BridgeWerkzeugquelle;

/**
 * Die Signaturen aus der Mitte — eine Zeile Bindung je Produkt.
 *
 * ## Warum es diesen Vorrat gibt
 *
 * Signaturen führte bis zum 01.11.2026 nur der Projekt-Manager, in einer
 * eigenen Tabelle. Die anderen drei übergaben dem Formular eine leere Quelle,
 * und es zeichnete die Zeile gar nicht erst: vier Systeme, dieselbe Maske, in
 * dreien fehlt ein Feld — ohne dass es jemand entschieden hätte.
 *
 * Seitdem liegen sie bei AI Brain, am POSTFACH. Dieser Vorrat holt sie von
 * dort, über dasselbe Werkzeug-Werk, das schon Konten, Zuweisungen und den
 * Gesprächsverlauf trägt. Gebaut wie {@see BrainVerlaufsspeicher}: Der Aufruf
 * kommt als Rückruf herein, damit dieses Paket die Brücke nicht kennen muss.
 *
 * **AI Brain bindet das NICHT so.** Es ist die Nabe; der Weg über die Brücke
 * zu sich selbst wäre ein Umweg mit Netzverkehr. Dort liest eine eigene,
 * lokale Fassung direkt aus der Tabelle — dieselbe Unterscheidung, die
 * {@see BridgeWerkzeugquelle} für die Werkzeuge
 * schon trifft.
 *
 * ## Gefragt wird nach dem PAAR (Person, Postfach)
 *
 * Beides, und zwar die Person über ihre **Adresse**: Derselbe Mensch hat in
 * jedem System eine andere Nummer, und eine davon mitzuschicken funktioniert
 * so lange zufällig, wie sie übereinstimmen. {@see HandelndePerson} löst das
 * schon — über die Brücke die handelnde Person, sonst die angemeldete.
 *
 * Ohne das Postfach bekäme man die persönliche Signatur auch dort, wo man für
 * ein Team schreibt. Genau dagegen ist der Vertrag geschnitten
 * ({@see Signaturvorrat}): Wer an `info@` schreibt, soll nicht mit seiner
 * persönlichen Unterschrift antworten.
 *
 * ## Ein Fehlschlag ist eine leere Liste, kein Abbruch
 *
 * Ohne Signaturen kann man eine Mail schreiben, ohne Formular nicht. Fällt die
 * Mitte aus, bleibt die Zeile weg — und die Verfassen-Seite steht. Dieselbe
 * Entscheidung wie im Verlaufsspeicher, und aus demselben Grund.
 */
class BrainSignaturvorrat implements Signaturvorrat
{
    /** Das Werkzeug der Nabe, Gegenstück zu diesem Vorrat. */
    public const WERKZEUG = 'mail-signatures-tool';

    /**
     * @param  callable(string, array<string, mixed>): ?array  $fetch  Liefert die
     *                                                                 dekodierte Antwort des Werkzeugs — die Hülle `{ok, data}`, wie
     *                                                                 sie drüben über die Leitung geht —, oder null, wenn die Mitte
     *                                                                 nicht erreichbar war.
     * @param  (callable(): ?string)|null  $person  Wessen Signaturen. Ohne
     *                                              Angabe die handelnde bzw. angemeldete Person.
     */
    public function __construct(private $fetch, private $person = null) {}

    /**
     * @return list<array{id: int|string, name: string, body_html: string, is_default: bool}>
     */
    public function signaturen(MailAccount $konto): array
    {
        $adresse = $this->adresse();

        if ($adresse === null) {
            /*
             * Ohne erkennbare Person gibt es nichts zu holen — und das ist die
             * Antwort, kein Fehler. Die Nabe antwortet genauso: „alle
             * Signaturen dieses Postfachs" wäre der stille Weg, mit der
             * Unterschrift eines Kollegen zu antworten.
             */
            return [];
        }

        try {
            $antwort = ($this->fetch)(self::WERKZEUG, [
                'account_id' => $konto->field('id'),
                'owner_email' => $adresse,
            ]);
        } catch (\Throwable $e) {
            // Eine unerreichbare Mitte ist die Lage, fuer die es den Rueckfall
            // gibt — keine Ausnahme, die jemand behandeln muesste.
            Log::warning('Signaturen konnten nicht aus AI Brain gelesen werden: '.$e->getMessage());

            return [];
        }

        return $this->zeilen(is_array($antwort) ? $antwort : []);
    }

    /** Wessen Signaturen gefragt sind. */
    private function adresse(): ?string
    {
        $roh = $this->person !== null
            ? ($this->person)()
            : (is_object($wer = HandelndePerson::finden()) ? ($wer->email ?? null) : null);

        return is_string($roh) && trim($roh) !== '' ? trim($roh) : null;
    }

    /**
     * Die Nutzlast steckt in `data` — dieselbe Hülle wie bei den Postfächern.
     *
     * Eine Zeile ohne `body_html` fällt dabei weg: Sie wäre in der Auswahl
     * sichtbar und beim Einsetzen leer, der Mensch wählte also etwas und
     * bekäme nichts.
     *
     * @param  array<string, mixed>  $antwort
     * @return list<array{id: int|string, name: string, body_html: string, is_default: bool}>
     */
    private function zeilen(array $antwort): array
    {
        $zeilen = [];

        foreach ((array) ($antwort['data'] ?? []) as $zeile) {
            if (! is_array($zeile)) {
                continue;
            }

            $rumpf = (string) ($zeile['body_html'] ?? '');

            if ($rumpf === '') {
                continue;
            }

            $zeilen[] = [
                'id' => $zeile['id'] ?? 0,
                'name' => (string) ($zeile['name'] ?? ''),
                'body_html' => $rumpf,
                'is_default' => (bool) ($zeile['is_default'] ?? false),
            ];
        }

        return $zeilen;
    }
}
