<?php

namespace Peppermint\Mailbox\Console;

use Illuminate\Console\Command;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Facades\Schema;
use Peppermint\Mailbox\Contracts\AccountStore;
use Peppermint\Mailbox\Contracts\Schlummerspeicher;
use Peppermint\Mailbox\Contracts\Zuweisungsspeicher;
use Peppermint\Mailbox\Models\MailAssignment;
use Peppermint\Mailbox\Models\MailSnooze;
use Peppermint\Mailbox\Zustand\Person;

/**
 * Den gewachsenen Zustand eines Produkts in die Mitte umziehen.
 *
 * ## Warum es diesen Befehl geben MUSS
 *
 * Sobald ein Produkt auf `store: brain` steht und das Paket aktualisiert ist,
 * liest es Zustaendigkeiten und Geschlummertes aus der Mitte. Seine eigenen
 * Zeilen sind damit unsichtbar — nicht geloescht, aber unerreichbar.
 *
 * Und das faellt nicht als Fehler auf. Es sieht so aus: Jede Mail ist
 * ploetzlich frei, niemand ist mehr zustaendig, weggelegte Unterhaltungen
 * stehen wieder da. „Wer bearbeitet diese Mail" ist lebender Zustand, kein
 * Archiv; ihn beim Umschalten zu verlieren, ist der teuerste stille Ausfall
 * dieses ganzen Umbaus.
 *
 * ## Der Probelauf ist die Vorgabe
 *
 * Ohne `--ausfuehren` wird nichts geschrieben. Gezeigt wird der ECHTE Bestand
 * mit einer Spalte, die sonst niemand aufschreibt: **ist die Person zentral
 * bekannt?** Genau diese Spalte war am 21.09.2026 der einzige Ort, an dem ein
 * vergleichbarer Fehler sichtbar wurde — vier von acht Postfaechern waren der
 * Mitte unbekannt, und die Tests waren gruen.
 *
 * Die Frage vor jedem solchen Umschalten lautet nicht „funktioniert es?",
 * sondern: **Fuer wie viele der vorhandenen Faelle macht die Mitte das — und
 * was passiert mit dem Rest?**
 *
 * ## Es wird nichts geloescht
 *
 * Die lokalen Zeilen bleiben stehen, auch nach dem Umzug. Sie sind der
 * Rueckweg, wenn der Umzug unvollstaendig war, und sie kosten nichts. Wer sie
 * wegraeumt, tut das spaeter und in einem eigenen Schritt — ein Umzug, der im
 * selben Lauf loescht, hat keine Gegenprobe.
 *
 * ## Zweimal laufen ist harmlos
 *
 * Die Mitte schreibt je Kette genau eine Zustaendigkeit; ein zweiter Lauf
 * schreibt dieselbe Aussage noch einmal. Das ist Absicht: Ein Umzug, den man
 * nicht wiederholen darf, wird beim ersten Abbruch zum Ratespiel.
 */
class ZustandUmziehenCommand extends Command
{
    protected $signature = 'mailbox:zustand-umziehen
                            {--ausfuehren : Wirklich schreiben. Ohne diese Angabe passiert nichts.}
                            {--nur= : Nur ein Teil — zuweisungen oder schlummer.}';

    protected $description = 'Zieht Zuständigkeiten und Geschlummertes aus den eigenen Tabellen in die Mitte um. Ohne --ausfuehren nur ein Probelauf mit Zahlen.';

    public function handle(): int
    {
        if (config('mailbox.store') !== 'brain') {
            $this->components->error(
                'Dieses Produkt steht nicht auf `store: brain`. Dann gibt es keine Mitte, '
                .'in die umgezogen werden könnte — und der eigene Bestand ist der richtige.'
            );

            return self::FAILURE;
        }

        $echt = (bool) $this->option('ausfuehren');
        $nur = (string) ($this->option('nur') ?? '');

        if (! $echt) {
            $this->components->warn('Probelauf — es wird nichts geschrieben. Mit --ausfuehren wird es echt.');
        }

        $fehler = 0;

        if ($nur === '' || $nur === 'zuweisungen') {
            $fehler += $this->zuweisungen($echt);
        }

        if ($nur === '' || $nur === 'schlummer') {
            $fehler += $this->schlummer($echt);
        }

        if ($fehler > 0) {
            /*
             * Ein Teil-Erfolg ist kein Erfolg. Wer hier 0 zurueckgibt, weil
             * „das meiste ging", bekommt in einer Ausrollkette ein gruenes
             * Haekchen fuer einen halben Umzug — und die fehlenden
             * Zustaendigkeiten merkt erst der Mensch, dem seine Arbeit fehlt.
             */
            $this->components->error("{$fehler} Zeile(n) konnten nicht umziehen. Siehe oben — der Umzug ist NICHT vollständig.");

            return self::FAILURE;
        }

        $this->components->info($echt ? 'Umzug vollständig.' : 'Probelauf vollständig — nichts geschrieben.');

        return self::SUCCESS;
    }

    private function zuweisungen(bool $echt): int
    {
        $tabelle = (string) config('mailbox.tables.assignments', 'mail_assignments');

        if (! Schema::hasTable($tabelle)) {
            $this->components->twoColumnDetail('Zuständigkeiten', 'keine eigene Tabelle — nichts umzuziehen');

            return 0;
        }

        /*
         * Gelesen wird die EIGENE Tabelle, geschrieben die Mitte. Zwei
         * Speicher in einem Lauf, und das ist der Kern dieses Befehls: Der
         * Container liefert hier bereits den zentralen (das Produkt ist ja
         * umgeschaltet), an den Altbestand kommt man nur noch an ihm vorbei.
         */
        $mitte = app(Zuweisungsspeicher::class);

        $zeilen = [];
        $offen = 0;
        $fehler = 0;

        foreach (MailAssignment::query()->where('status', MailAssignment::STATUS_OPEN)->cursor() as $zeile) {
            $offen++;

            $konto = (int) $zeile->email_account_id;
            $postfach = $this->postfach($konto);
            $person = $this->person((int) $zeile->assigned_to_user_id);

            $hindernis = match (true) {
                $postfach === null => 'Postfach zentral unbekannt',
                $person === null => 'Person hat keine Adresse',
                default => null,
            };

            $nutzer = $this->nutzer((int) $zeile->assigned_to_user_id);

            if ($hindernis !== null) {
                $fehler++;
            } elseif ($echt) {
                /*
                 * ALS die zugewiesene Person schreiben. Die Bruecke liest die
                 * handelnde Person aus `auth()->user()`, und ohne sie weist
                 * die Mitte ab — siehe {@see alsPerson()}.
                 */
                $geschrieben = $this->alsPerson($nutzer, fn () => $mitte->zuweisen(
                    $konto,
                    (string) $zeile->message_id,
                    $zeile->thread_id === null ? null : (string) $zeile->thread_id,
                    $person,
                    $this->person($zeile->assigned_by_user_id === null ? null : (int) $zeile->assigned_by_user_id),
                ));

                if ($geschrieben === null) {
                    $hindernis = 'die Mitte hat nicht angenommen';
                    $fehler++;
                }
            } else {
                /*
                 * Der Probelauf FRAGT die Mitte, statt nur zu pruefen, ob die
                 * Person benennbar ist.
                 *
                 * Vorher stand hier „bereit", sobald Postfach und Adresse
                 * bekannt waren — und am 20.10.2026 scheiterten danach alle
                 * 80 Zeilen an der Sichtpruefung. Ein Probelauf, der eine
                 * VORBEDINGUNG prueft und „bereit" meldet, liest sich wie
                 * „der Schreibvorgang wird klappen".
                 *
                 * Ein Lesen ist harmlos und laeuft durch denselben Riegel wie
                 * das Schreiben. Antwortet die Mitte nicht, ist das hier die
                 * Auskunft, die zaehlt.
                 */
                if ($nutzer === null) {
                    $hindernis = 'kein Nutzersatz — niemand kann handeln';
                    $fehler++;
                } elseif (! $this->darfHinein($mitte, $konto, $nutzer)) {
                    $hindernis = 'die Mitte laesst diese Person nicht hinein';
                    $fehler++;
                }
            }

            $zeilen[] = [
                $postfach ?? "Konto #{$konto}",
                $zeile->thread_id ?? $zeile->message_id,
                $person?->adresse ?? "Nutzer #{$zeile->assigned_to_user_id}",
                // Die Spalte, um die es geht: Nicht „hat geklappt", sondern
                // „ist die Sache ueberhaupt benennbar".
                $person === null ? 'nein' : 'ja',
                $hindernis ?? ($echt ? 'umgezogen' : 'bereit'),
            ];
        }

        $this->newLine();
        $this->components->info("Zuständigkeiten: {$offen} offen in `{$tabelle}`");

        if ($zeilen !== []) {
            $this->table(['Postfach', 'Kette / Nachricht', 'Person', 'zentral bekannt', 'Ergebnis'], $zeilen);
        }

        return $fehler;
    }

    private function schlummer(bool $echt): int
    {
        $tabelle = (string) config('mailbox.tables.snoozes', 'mail_snoozes');

        if (! Schema::hasTable($tabelle)) {
            $this->components->twoColumnDetail('Geschlummertes', 'keine eigene Tabelle — nichts umzuziehen');

            return 0;
        }

        $mitte = app(Schlummerspeicher::class);

        $zeilen = [];
        $offen = 0;
        $fehler = 0;

        foreach (MailSnooze::query()->where('snooze_until', '>', now())->cursor() as $zeile) {
            $offen++;

            $konto = (int) $zeile->email_account_id;
            $postfach = $this->postfach($konto);
            $person = $this->person((int) $zeile->user_id);

            $hindernis = match (true) {
                $postfach === null => 'Postfach zentral unbekannt',
                $person === null => 'Person hat keine Adresse',
                $zeile->thread_id === null => 'keine Kette — wurde nie richtig weggelegt',
                default => null,
            };

            if ($hindernis !== null) {
                $fehler++;
            } elseif ($echt && ! $this->alsPerson(
                // ALS die Person, der das Geschlummerte gehoert — Schlummern
                // ist ohnehin pro Person, und die Bruecke liest die handelnde
                // aus `auth()->user()`. Siehe {@see alsPerson()}.
                $this->nutzer((int) $zeile->user_id),
                fn (): bool => $mitte->weglegen(
                    $konto,
                    $person,
                    (string) $zeile->thread_id,
                    (string) $zeile->message_id,
                    $zeile->snooze_until->toDateTimeImmutable(),
                ),
            )) {
                $hindernis = 'die Mitte hat nicht angenommen';
                $fehler++;
            }

            $zeilen[] = [
                $postfach ?? "Konto #{$konto}",
                $zeile->thread_id ?? '—',
                $person?->adresse ?? "Nutzer #{$zeile->user_id}",
                $person === null ? 'nein' : 'ja',
                $zeile->snooze_until?->format('d.m.Y H:i') ?? '—',
                $hindernis ?? ($echt ? 'umgezogen' : 'bereit'),
            ];
        }

        $this->newLine();
        $this->components->info("Geschlummertes: {$offen} noch ruhend in `{$tabelle}`");

        if ($zeilen !== []) {
            $this->table(['Postfach', 'Kette', 'Person', 'zentral bekannt', 'bis', 'Ergebnis'], $zeilen);
        }

        return $fehler;
    }

    /**
     * Die Adresse des Postfachs — die Kennung, die über Systemgrenzen trägt.
     */
    private function postfach(int $konto): ?string
    {
        $zeile = app(AccountStore::class)->find($konto);

        if ($zeile === null) {
            return null;
        }

        $adresse = mb_strtolower(trim((string) $zeile->field('email')));

        return $adresse === '' ? null : $adresse;
    }

    /**
     * Darf diese Person in dieses Postfach? Einmal je Paar gefragt.
     *
     * Ein Lesen durch denselben Riegel, durch den auch das Schreiben geht —
     * harmlos, und die einzige Auskunft, die „bereit" verdient. Gemerkt wird
     * je Postfach und Person: Bei 79 Zeilen auf einem Postfach wären es sonst
     * 79 Anfragen an die Mitte für dieselbe Frage.
     *
     * @var array<string, bool>
     */
    private array $sicht = [];

    private function darfHinein(Zuweisungsspeicher $mitte, int $konto, object $nutzer): bool
    {
        $schluessel = $konto.'|'.($nutzer->getAttribute('email') ?? spl_object_id($nutzer));

        return $this->sicht[$schluessel] ??= $this->alsPerson(
            $nutzer,
            fn (): bool => $mitte->offene($konto)->erreichbar,
        );
    }

    /**
     * Das Nutzermodell zu einer örtlichen Nummer.
     *
     * Gebraucht, weil die Brücke die HANDELNDE Person aus `auth()->user()`
     * liest — und ein Konsolenlauf hat keine. Siehe {@see alsPerson()}.
     */
    private function nutzer(?int $nummer): ?object
    {
        if ($nummer === null) {
            return null;
        }

        $modell = (string) config('mailbox.user_model', 'App\\Models\\User');

        return class_exists($modell) ? $modell::query()->whereKey($nummer)->first() : null;
    }

    /**
     * Etwas tun, als wäre diese Person angemeldet.
     *
     * ## Der Fehler, den das behebt — und er war nur live zu sehen
     *
     * `peppermint/ai-brain-bridge` bestimmt die handelnde Person aus
     * `auth()->user()`. In einem Konsolenlauf gibt es keine, und die Mitte
     * weist dann ab: „Die handelnde Person darf in dieses Postfach nicht
     * hineinsehen." Am 20.10.2026 beim Ausrollen so passiert — ALLE 80 Zeilen
     * gescheitert, nachdem der Probelauf sie als „bereit" gemeldet hatte.
     *
     * Warum die ZUGEWIESENE Person die richtige Handelnde ist: Sie ist
     * diejenige, deren Zuständigkeit umzieht, und sie darf das Postfach
     * sehen — sonst wäre sie nie zugewiesen worden. `asService()` der Brücke
     * wäre die Alternative und die falsche: Es umgeht die Sichtprüfung, und
     * ein Umzug ist kein Grund, eine Zugriffsgrenze aufzuheben.
     *
     * @template T
     * @param  callable(): T  $tun
     * @return T
     */
    private function alsPerson(?object $nutzer, callable $tun): mixed
    {
        /*
         * `instanceof Authenticatable` und nicht blind `setUser()`: Der Wächter
         * verlangt den Vertrag, und ein Nutzermodell, das ihn nicht erfüllt,
         * ist eine Fehlkonfiguration — aber keine, die diesen Umzug mit einem
         * TypeError abbrechen darf. Dann läuft der Vorgang ohne handelnde
         * Person, und die Mitte sagt, was sie davon hält.
         */
        if (! $nutzer instanceof Authenticatable || ! function_exists('auth')) {
            return $tun();
        }

        $vorher = auth()->user();

        try {
            auth()->setUser($nutzer);

            return $tun();
        } finally {
            // Zurücksetzen, nicht einfach abmelden: Der nächste Durchlauf
            // soll seine eigene Person bekommen, nicht die vorige.
            $vorher === null ? auth()->forgetUser() : auth()->setUser($vorher);
        }
    }

    /**
     * Die Person zu einer örtlichen Nummer — mit ihrer Adresse.
     *
     * Ohne Adresse `null`, und das ist der ganze Zweck dieser Methode: Eine
     * Person, die hier nur eine Nummer hat, kann in der Mitte nicht stehen.
     * Sie dort trotzdem einzutragen hiesse, die Zuständigkeit an jemand
     * anderen zu hängen — Nutzer 5 ist in jedem System ein anderer Mensch.
     */
    private function person(?int $nummer): ?Person
    {
        if ($nummer === null) {
            return null;
        }

        $modell = (string) config('mailbox.user_model', 'App\\Models\\User');

        if (! class_exists($modell)) {
            return null;
        }

        $person = $modell::query()->whereKey($nummer)->first();

        if ($person === null) {
            return null;
        }

        return Person::aus(
            (string) ($person->getAttribute('email') ?? ''),
            $nummer,
            (string) ($person->getAttribute('name') ?? ''),
        );
    }
}
