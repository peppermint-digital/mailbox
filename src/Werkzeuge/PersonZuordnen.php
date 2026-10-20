<?php

namespace Peppermint\Mailbox\Werkzeuge;

use Illuminate\Database\Eloquent\Model;
use Peppermint\Mailbox\Contracts\AccountStore;
use Peppermint\Mailbox\Contracts\Zuweisbare;
use Peppermint\Mailbox\Contracts\Zuweisungsspeicher;
use Peppermint\Mailbox\Zustand\Person;

/**
 * „Zuständig" — wer sich um diese Unterhaltung kümmert.
 *
 * ## Warum dieses Verb ins Paket gehoert und die anderen nicht
 *
 * Ticket, Beleg, Aufgabe, Kontakt sind Verben EINES Produkts; die anderen
 * Systeme holen sie. Dieses bedeutet in allen vier Systemen dasselbe, und die
 * Zustaendigkeit ist die eigene Fachlichkeit des Pakets — der
 * {@see Zuweisungsspeicher} liegt hier, nicht in einem Produkt. Vier Produkte,
 * die es je selbst anmelden, waeren vier Kopien derselben zwanzig Zeilen.
 *
 * Deshalb ausdruecklich {@see NurHier}: Jedes System hat es selbst, also darf
 * es nicht noch dreimal von den Nachbarn geholt werden.
 *
 * ## Die Woerter sind Vorgaben, keine Vorschrift
 *
 * Dieselbe Linie wie `germanLabels` auf der JS-Seite: Das Paket BRINGT eine
 * Sprache mit, es imponiert keine. Wer andere Woerter braucht, uebergibt sie
 * beim Anmelden:
 *
 *     $verzeichnis->anmelden(new PersonZuordnen(wort: 'Bearbeiter'));
 *
 * Und es ist ein `new`, keine Klassenangabe — Vorgaben zu aendern geht nur an
 * einer Instanz. Das Verzeichnis nimmt beides.
 *
 * ## Zugewiesen wird eine ADRESSE
 *
 * Nicht die Nummer, die eine Person HIER hat. Nutzer 5 ist in jedem System ein
 * anderer Mensch, und die Zustaendigkeit liegt in der Mitte. Die Auswahlliste
 * fuehrt deshalb Adressen als Werte; wer keine hat, steht nicht darin.
 *
 * ## Die Liste IST die Pruefung
 *
 * `ausfuehren()` nimmt nur, was {@see Zuweisbare} fuer dieses Postfach
 * hergibt — nicht, was im Formular ankam. Ein Formularwert ist eine
 * Behauptung des Browsers; zweimal hat dieses Projekt fremde Posteingaenge
 * geoeffnet, weil eine Pruefung uebernommen statt gestellt wurde (Memories
 * #4003, #4009).
 */
class PersonZuordnen implements LiefertFelder, NurHier, Vorgangswerkzeug
{
    public function __construct(
        private readonly string $wort = 'Zuständig',
        private readonly string $feldWort = 'Person',
        private readonly string $zustandMuster = 'Zuständig: :name',
        private readonly string $symbol = 'user-check',
        private readonly int $reihenfolge = 5,
    ) {}

    public function werkzeug(): Werkzeug
    {
        return new Werkzeug(
            schluessel: 'person',
            wort: $this->wort,
            symbol: $this->symbol,
            // Weit vorne: „Wer kümmert sich" ist die Frage, die vor allen
            // anderen kommt — ein Ticket anzulegen ist der zweite Schritt.
            reihenfolge: $this->reihenfolge,
            gruppe: 'zuordnen',
        );
    }

    public function anwendbar(Vorgang $vorgang, Model $person): bool
    {
        /*
         * Drei Gruende, es NICHT anzubieten, und jeder ist einer, bei dem der
         * Knopf sonst zuverlaessig scheitern wuerde:
         *
         * 1. Kein Postfach gefunden — dann gibt es keine Kontonummer, unter
         *    der die Mitte etwas fuehren koennte.
         * 2. Die Mitte nimmt gerade nichts an (`schreibbar()`): Ein Knopf, der
         *    sicher scheitert, ist schlechter als keiner. Dieselbe Regel wie
         *    beim `writable` der Zuweisungs-Oberflaeche.
         * 3. Niemand ist zuweisbar — eine leere Auswahl ist kein Formular.
         */
        $konto = $this->konto($vorgang);

        if ($konto === null || ! app(Zuweisungsspeicher::class)->schreibbar()) {
            return false;
        }

        return $this->personen($konto) !== [];
    }

    public function felder(Vorgang $vorgang, Model $person): array
    {
        $konto = $this->konto($vorgang);

        return [[
            'name' => 'person',
            'wort' => $this->feldWort,
            'art' => 'select',
            'pflicht' => true,
            'auswahl' => array_map(static fn (array $n): array => [
                // Der WERT ist die Adresse. Stuende hier die Nummer, waere die
                // Zuweisung beim naechsten Systemwechsel ein anderer Mensch.
                'wert' => $n['email'],
                'wort' => $n['name'],
            ], $konto === null ? [] : $this->personen($konto)),
        ]];
    }

    /**
     * Wer gerade zustaendig ist — die Auskunft, um die es geht.
     *
     * Ohne sie waere der Knopf in der Leiste eine Frage ohne Antwort: Die
     * Mailseite zeigt die Zustaendigkeit heute als Abzeichen mit Initialen,
     * und wer sie in die Leiste holt, muss die Auskunft mitnehmen. Beim
     * Zusammenlegen darf nichts wegfallen.
     */
    public function zustand(Vorgang $vorgang, Model $person): ?array
    {
        $konto = $this->konto($vorgang);

        if ($konto === null) {
            return null;
        }

        $zuweisung = app(Zuweisungsspeicher::class)->fuer(
            $konto,
            $vorgang->nachricht,
            $vorgang->kette,
        );

        if ($zuweisung === null) {
            return null;
        }

        return [
            'wort' => str_replace(
                ':name',
                $zuweisung->an->name ?? $zuweisung->an->adresse,
                $this->zustandMuster,
            ),
            // Kein Verweis: Die Zustaendigkeit hat keine eigene Seite, sie
            // steht an dieser Mail. Eine Adresse hierhin zu erfinden fuehrte
            // im Kreis.
            'verweis' => null,
        ];
    }

    public function verweis(Vorgang $vorgang, Model $person): ?string
    {
        // Dieses Verb TUT. Beides zu liefern waere ein Widerspruch — es wuerde
        // als Verweis behandelt und nie ausgeloest.
        return null;
    }

    public function ausfuehren(Vorgang $vorgang, array $daten, Model $person): Ergebnis
    {
        $konto = $this->konto($vorgang);

        if ($konto === null) {
            return Ergebnis::gescheitert('Dieses Postfach ist hier nicht bekannt.');
        }

        $gewaehlt = mb_strtolower(trim((string) ($daten['person'] ?? '')));

        if ($gewaehlt === '') {
            return Ergebnis::gescheitert('Es wurde niemand ausgewählt.');
        }

        /*
         * Gegen die LISTE pruefen, nicht gegen den Wert. Der Browser schickt,
         * was er will; zulaessig ist nur, was dieses Postfach hergibt.
         */
        $treffer = null;

        foreach ($this->personen($konto) as $kandidat) {
            if (mb_strtolower($kandidat['email']) === $gewaehlt) {
                $treffer = $kandidat;

                break;
            }
        }

        if ($treffer === null) {
            return Ergebnis::gescheitert('Diese Person kann in diesem Postfach nicht zuständig sein.');
        }

        $zuweisung = app(Zuweisungsspeicher::class)->zuweisen(
            $konto,
            $vorgang->nachricht,
            $vorgang->kette,
            new Person(adresse: mb_strtolower($treffer['email']), name: $treffer['name']),
            $this->handelnde($person),
        );

        if ($zuweisung === null) {
            // Kein erfundener Erfolg. „Niemand war zu sprechen" und „es ist
            // zugewiesen" sind zwei Lagen mit zwei verschiedenen naechsten
            // Schritten.
            return Ergebnis::gescheitert('Die Zuständigkeit konnte nicht festgehalten werden.');
        }

        $satz = str_replace(':name', $treffer['name'], $this->zustandMuster);

        // Der Zustand ausdruecklich MIT Wort: Sonst laedt der Knopf weiter
        // zum Zuweisen ein, obwohl gerade jemand zustaendig geworden ist.
        return Ergebnis::gelungen($satz, zustandWort: $satz);
    }

    /**
     * Wer zuweist — als Person, nicht als Nummer.
     *
     * `null`, wenn die handelnde Person keine Adresse fuehrt. Dann wird sie
     * nicht genannt, statt eine falsche zu behaupten.
     */
    private function handelnde(Model $person): ?Person
    {
        $adresse = mb_strtolower(trim((string) $person->getAttribute('email')));

        if ($adresse === '') {
            return null;
        }

        return new Person(
            adresse: $adresse,
            name: (string) ($person->getAttribute('name') ?? $adresse),
        );
    }

    /**
     * Das Postfach dieses Vorgangs — ueber die ADRESSE gefunden.
     */
    private function konto(Vorgang $vorgang): ?int
    {
        if ($vorgang->postfach === '') {
            return null;
        }

        /*
         * Ueber den Speicher und nicht ueber das Modell: In einem Produkt mit
         * `store: brain` fuehrt die Mitte die Postfaecher, und eine Abfrage
         * auf die eigene Tabelle fand dort nur, was zufaellig auch lokal
         * steht. `all()` ist der Weg, den auch die Oberflaeche nimmt.
         */
        foreach (app(AccountStore::class)->all() as $zeile) {
            if (mb_strtolower(trim((string) $zeile->field('email'))) === $vorgang->postfach) {
                return (int) $zeile->getKey();
            }
        }

        return null;
    }

    /**
     * @return list<array{id: int, name: string, email: string}>
     */
    private function personen(int $konto): array
    {
        $liste = app(Zuweisbare::class)->fuer($konto);

        // Ohne Adresse nicht zuweisbar — siehe Klassentext. Sie hier
        // auszusortieren ist kein Verstecken: Ein Eintrag in der Auswahl, der
        // beim Klick abgelehnt wird, ist ein Knopf, der zuverlaessig scheitert.
        return array_values(array_filter(
            $liste,
            static fn (array $n): bool => trim((string) ($n['email'] ?? '')) !== '',
        ));
    }
}
