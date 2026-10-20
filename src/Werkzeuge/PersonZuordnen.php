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
            /*
             * Der aktuelle Stand als Vorbelegung — NICHT als Zustand.
             *
             * Das ist der Unterschied zu „Ticket anlegen": Dort schlaegt der
             * Zustand das Verb, weil ein zweites Ticket ein Fehler waere. Hier
             * ist das Aendern der Normalfall. Am 20.10.2026 im Browser
             * gesehen: Mit `zustand()` stand an einer zugewiesenen Mail nur
             * noch ein Text, und die Zustaendigkeit liess sich von dort gar
             * nicht mehr aendern — nur ueber das Abzeichen in der Listenzeile.
             */
            'wert' => $konto === null ? '' : $this->zustaendigeAdresse($konto, $vorgang),
            'auswahl' => array_map(static fn (array $n): array => [
                // Der WERT ist die Adresse. Stuende hier die Nummer, waere die
                // Zuweisung beim naechsten Systemwechsel ein anderer Mensch.
                'wert' => $n['email'],
                'wort' => $n['name'],
            ], $konto === null ? [] : $this->personen($konto)),
        ]];
    }

    /**
     * KEIN Zustand — und das ist eine Entscheidung, keine Luecke.
     *
     * Ein Zustand SCHLAEGT in der Leiste das Verb: Statt des Knopfes steht
     * dann der Hinweis, dass es die Sache schon gibt. Fuer „Ticket anlegen"
     * ist das richtig — ein zweites Ticket waere ein Fehler.
     *
     * Fuer die Zustaendigkeit ist es falsch, und das war im Browser sofort zu
     * sehen (20.10.2026): An einer zugewiesenen Mail stand nur noch
     * „Zustaendig: …", und wer die Zustaendigkeit AENDERN wollte, konnte es
     * von dort nicht. Das Aendern ist hier aber der Normalfall.
     *
     * Der aktuelle Stand steht deshalb als Vorbelegung im Auswahlfeld. Dann
     * zeigt die Leiste ihn UND laesst ihn aendern.
     */
    public function zustand(Vorgang $vorgang, Model $person): ?array
    {
        return null;
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
     * Die Adresse der Person, die gerade zustaendig ist — oder ''.
     *
     * ## Warum das aufgeloest werden MUSS
     *
     * Der ORTSEIGENE Speicher gibt eine Person nur mit NUMMER heraus, ohne
     * Adresse und ohne Namen — er fuehrt in seiner Tabelle nichts anderes
     * (siehe `LokalerZuweisungsspeicher::alsWert()`). Die zentrale Fassung
     * gibt die Adresse. Beide Formen kommen hier an, je nachdem, worauf das
     * Produkt steht.
     *
     * Genau daran ist der erste Entwurf im Browser gescheitert: An einer
     * zugewiesenen Mail stand „Zustaendig:" — ohne Namen. Der Code las
     * `$an->name ?? $an->adresse`, und beide waren im AI Brain der leere
     * String. `??` greift bei `null`, nicht bei `''`.
     *
     * {@see Zuweisbare} ist die einzige Stelle, die Nummer UND Adresse kennt.
     * Deshalb wird hier darueber aufgeloest, und nicht geraten.
     */
    private function zustaendigeAdresse(int $konto, Vorgang $vorgang): string
    {
        $zuweisung = app(Zuweisungsspeicher::class)->fuer(
            $konto,
            $vorgang->nachricht,
            $vorgang->kette,
        );

        if ($zuweisung === null) {
            return '';
        }

        $adresse = mb_strtolower(trim($zuweisung->an->adresse));
        $nummer = $zuweisung->an->nummer;

        foreach ($this->personen($konto) as $kandidat) {
            $trefferAdresse = $adresse !== '' && mb_strtolower($kandidat['email']) === $adresse;
            $trefferNummer = $nummer !== null && (int) $kandidat['id'] === $nummer;

            if ($trefferAdresse || $trefferNummer) {
                return $kandidat['email'];
            }
        }

        /*
         * Zugewiesen, aber nicht in der Liste dieses Postfachs: Dann steht in
         * der Auswahl NICHTS vorbelegt. Das ist richtig — eine Vorbelegung,
         * die nicht in der Auswahl steht, waere ein Feld mit einem Wert, den
         * man nicht wiederherstellen kann, wenn man einmal daneben klickt.
         *
         * Vorkommen kann es: Im Projekt-Manager haengt die Liste an der
         * Zuteilung des Postfachs, und die kann sich geaendert haben,
         * nachdem zugewiesen wurde.
         */
        return '';
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
