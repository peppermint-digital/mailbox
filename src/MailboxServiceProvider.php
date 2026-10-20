<?php

namespace Peppermint\Mailbox;

use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\ServiceProvider;
use Peppermint\Mailbox\Brain\MailboxChat;
use Peppermint\Mailbox\Console\InstallCommand;
use Peppermint\Mailbox\Console\ZustandUmziehenCommand;
use Illuminate\Support\Facades\Event;
use Peppermint\Mailbox\Contracts\AccountStore;
use Peppermint\Mailbox\Events\VerlaufVergessen;
use Peppermint\Mailbox\Contracts\Verlaufsspeicher;
use Peppermint\Mailbox\Contracts\Schlummerspeicher;
use Peppermint\Mailbox\Contracts\Zuweisungsspeicher;
use Peppermint\Mailbox\Stores\BrainAccountStore;
use Peppermint\Mailbox\Stores\BrainVerlaufsspeicher;
use Peppermint\Mailbox\Stores\BrainSchlummerspeicher;
use Peppermint\Mailbox\Stores\BrainZuweisungsspeicher;
use Peppermint\Mailbox\Stores\LokalerSchlummerspeicher;
use Peppermint\Mailbox\Stores\LokalerZuweisungsspeicher;
use Peppermint\Mailbox\Stores\LokalerVerlaufsspeicher;
use Peppermint\Mailbox\Stores\LocalAccountStore;
use Peppermint\Mailbox\Stores\MergedAccountStore;
use Peppermint\Mailbox\Werkzeuge\BridgeWerkzeugquelle;
use Peppermint\Mailbox\Werkzeuge\FremdeWerkzeuge;
use Peppermint\Mailbox\Werkzeuge\GesamtWerkzeuge;
use Peppermint\Mailbox\Werkzeuge\Vorgang;
use Peppermint\Mailbox\Werkzeuge\Werkzeugquelle;
use Peppermint\Mailbox\Werkzeuge\Werkzeugverzeichnis;

class MailboxServiceProvider extends ServiceProvider
{
    /** This class only exists when a usable central store is installed. */
    private const BRIDGE = 'Peppermint\\AiBrainBridge\\Facades\\AiBrain';

    /** Das Ereignis, das die Bridge im Produkt wirft. */
    private const BRIDGE_EVENT = 'Peppermint\\AiBrainBridge\\Events\\AiBrainEventReceived';

    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/mailbox.php', 'mailbox');

        $this->app->singleton(AccountStore::class, fn (): AccountStore => $this->store());
        $this->app->singleton(Verlaufsspeicher::class, fn (): Verlaufsspeicher => $this->verlaufsspeicher());
        $this->app->singleton(Zuweisungsspeicher::class, fn (): Zuweisungsspeicher => $this->zuweisungsspeicher());
        $this->app->singleton(Schlummerspeicher::class, fn (): Schlummerspeicher => $this->schlummerspeicher());

        $this->app->singleton(MailboxChat::class, fn (): MailboxChat => $this->chat());

        /*
         * Die Werkzeuge am Vorgang — „Als Eingangsrechnung", „Ticket
         * erstellen", „Aufgabe anhaengen".
         *
         * Das Verzeichnis ist LEER, wenn ein Produkt nichts anmeldet. Das ist
         * der richtige Ausgangszustand: Die Verben sind das Produkt, und ein
         * Paket, das welche mitbringt, braechte eine Fachlichkeit mit, die es
         * nicht kennen darf (Grundsatz: zwei Core-Pakete duerfen sich nicht
         * kennen).
         */
        $this->app->singleton(Werkzeugverzeichnis::class);
        $this->app->singleton(Werkzeugquelle::class, fn (): Werkzeugquelle => $this->werkzeugquelle());
        $this->app->singleton(
            FremdeWerkzeuge::class,
            fn ($app): FremdeWerkzeuge => new FremdeWerkzeuge($app->make(Werkzeugquelle::class)),
        );
        $this->app->singleton(GesamtWerkzeuge::class);
    }

    public function boot(): void
    {
        /*
         * Sagt die Mitte, dass ein Verlauf entfernt wurde, wird er hier
         * sofort vergessen (#6284).
         *
         * Nur wenn die Bridge ueberhaupt da ist — ohne sie gibt es kein
         * Ereignis, und ein Lauscher auf eine Klasse, die es nicht gibt,
         * waere beim Booten ein Fehler statt einer fehlenden Funktion.
         */
        if (class_exists(self::BRIDGE_EVENT)) {
            Event::listen(self::BRIDGE_EVENT, function (object $ereignis): void {
                $inhalt = $ereignis->event ?? null;

                if ($inhalt === null || ($inhalt->type ?? null) !== VerlaufVergessen::TYP) {
                    return;
                }

                VerlaufVergessen::ausEreignis((array) ($inhalt->payload ?? []));
            });
        }

        $this->publishes([
            __DIR__.'/../config/mailbox.php' => config_path('mailbox.php'),
        ], 'mailbox-config');

        if ($this->app->runningInConsole()) {
            $this->commands([InstallCommand::class, ZustandUmziehenCommand::class]);
        }

        if (config('mailbox.run_migrations', true)) {
            $this->loadMigrationsFrom(__DIR__.'/../database/migrations');
        }

        /*
         * Das Kopfzeilen-Verzeichnis liegt getrennt und haengt NICHT an
         * `run_migrations` (#6119).
         *
         * ## Warum das kein Schlupfloch ist
         *
         * `run_migrations` steht in allen vier Produkten auf `false`, und zwar
         * aus einem Grund: Die Kontotabelle des Pakets wuerde neben der
         * gewachsenen eigenen eine zweite, leere anlegen, die niemand benutzt
         * und die beim naechsten Blick in die Datenbank Raetsel aufgibt.
         *
         * Fuer `mailbox_headers` gilt dieser Grund nicht. Es gibt kein Produkt,
         * das eine eigene Tabelle dafuer haette — das Verzeichnis gehoert dem
         * Paket allein, kein Fremdschluessel zeigt darauf, und ein Produkt
         * liest es nie selbst. Haengte es am selben Schalter, waere die
         * Tabelle in keinem der vier Produkte je entstanden.
         *
         * Gebunden ist es stattdessen an sein eigenes Merkmal: Wer das
         * Verzeichnis abschaltet, bekommt auch die Tabelle nicht.
         *
         * Der Unterordner ist noetig, weil Laravel einen Migrationspfad NICHT
         * rekursiv liest — sonst waere die Datei in beiden Pfaden.
         */
        if (config('mailbox.header_index', true)) {
            $this->loadMigrationsFrom(__DIR__.'/../database/migrations/intern');
        }
    }

    /**
     * Build the configured store, or fall back to the local one — loudly.
     *
     * `store: brain` without the bridge is a misconfiguration, not an edge
     * case. Falling back silently would mean somebody believes the settings
     * are central while they are local — and edits the wrong copy on the next
     * password rotation.
     *
     * The package does not *require* the bridge. It sits under `suggest`, the
     * check is `class_exists`, and installing the package alone yields the
     * local store with no trace of anything else.
     */
    /**
     * Where the conversation history comes from.
     *
     * The archive is ONE truth — it lives where mail is captured and nowhere
     * else. Two archives would be two truths, and those drift apart.
     *
     * Seeing it is a different matter: every product should be able to. So the
     * same split as for accounts — whoever keeps the archive reads locally,
     * everyone else asks the centre.
     *
     * Recognised by the tables, not by a setting: a product that has the
     * archive keeps it, a product that has not cannot. A switch beside that
     * would be a second answer to a question the schema already answers — and
     * the wrong one would look like „no conversations yet".
     */
    private function verlaufsspeicher(): Verlaufsspeicher
    {
        $eigene = config('mailbox.tables.messages', 'mail_messages');

        if (Schema::hasTable($eigene)) {
            return new LokalerVerlaufsspeicher;
        }

        if (! class_exists(self::BRIDGE)) {
            // Keine Ablage und keine Bridge: Dann gibt es eben keinen Verlauf.
            // Der lokale Speicher antwortet auf einer fehlenden Tabelle nicht
            // mit „leer", sondern mit einem Fehler — und das waere der falsche
            // Klang fuer „hier ist das Feature nicht eingerichtet".
            return new class implements Verlaufsspeicher
            {
                public function verfuegbar(int $account, string $thread): bool
                {
                    return false;
                }

                public function verlauf(int $account, string $thread): array
                {
                    return [];
                }
            };
        }

        $bridge = self::BRIDGE;
        $tool = (string) config('mailbox.conversation_tool', 'mail-conversation-tool');

        return new BrainVerlaufsspeicher(
            function (string $_capability, array $arguments) use ($bridge, $tool): ?array {
                return $bridge::call($tool, $arguments);
            },
            (int) config('mailbox.conversation_cache.available_ttl', 300),
            (int) config('mailbox.conversation_cache.entries_ttl', 60),
            // Gefragt wird nach der Adresse: Dieselbe Mailbox hat in jedem
            // System eine andere Nummer. Erst spaet aufgeloest — beim Binden
            // steht der Kontenspeicher unter Umstaenden noch gar nicht.
            fn (int $account): ?string => ($konto = app(AccountStore::class)->find($account))
                ? (string) $konto->field('email')
                : null,
        );
    }

    /**
     * Wo die Zustaendigkeiten liegen.
     *
     * ## Erkannt an der Einstellung, nicht an den Tabellen
     *
     * Anders als beim Verlauf, der sich an der Ablage erkennt. Dort war die
     * Tabelle die richtige Frage: Wer archiviert, HAT den Verlauf, wer nicht,
     * KANN ihn nicht haben.
     *
     * Hier nicht. Alle vier Produkte haben eine Zuweisungstabelle — sie ist ja
     * der Altbestand, den dieser Umbau ersetzt. An ihr zu erkennen, wo die
     * Wahrheit liegt, hiesse: nirgends umschalten. Also entscheidet dieselbe
     * Einstellung wie bei den Postfaechern, und damit liegt beides am selben
     * Schalter.
     *
     * ## Ohne Bridge laut zurueckfallen
     *
     * `store: brain` ohne Bridge ist eine Fehlkonfiguration, kein Grenzfall —
     * dieselbe Lage wie bei den Konten, dieselbe Behandlung. Still
     * zurueckzufallen hiesse: Jemand glaubt, die Zustaendigkeit gilt
     * systemweit, und sie steht lokal.
     */
    private function zuweisungsspeicher(): Zuweisungsspeicher
    {
        if (! $this->zustandZentral('Zustaendigkeiten', 'wer sie fuer systemweit haelt, weist im falschen System zu')) {
            return new LokalerZuweisungsspeicher($this->postfachAdresse());
        }

        return new BrainZuweisungsspeicher($this->mitteRufen(), $this->postfachAdresse());
    }

    /**
     * Wo das Geschlummerte liegt — dieselbe Entscheidung, dasselbe Muster.
     *
     * Bewusst am selben Schalter wie die Zustaendigkeit: Beides sind Aussagen
     * ueber die Sache, und ein System, bei dem das eine zentral und das andere
     * lokal liegt, haette eine Mail, die zugewiesen mitreist und weggelegt
     * nicht. Das waere schwerer zu erklaeren als beides falsch.
     */
    private function schlummerspeicher(): Schlummerspeicher
    {
        if (! $this->zustandZentral('Geschlummertes', 'eine weggelegte Mail taucht im naechsten System wieder auf')) {
            return new LokalerSchlummerspeicher($this->postfachAdresse());
        }

        return new BrainSchlummerspeicher($this->mitteRufen(), $this->postfachAdresse());
    }

    /**
     * Soll dieser Zustand in der Mitte liegen — und wenn nicht, warum?
     *
     * Die Warnung ist der eigentliche Zweck. `store: brain` ohne Bridge ist
     * eine Fehlkonfiguration, kein Grenzfall: Still zurueckzufallen hiesse,
     * dass jemand den Zustand fuer systemweit haelt, waehrend er lokal steht.
     */
    private function zustandZentral(string $was, string $folge): bool
    {
        if (config('mailbox.store', 'local') !== 'brain') {
            return false;
        }

        if (! class_exists(self::BRIDGE)) {
            Log::warning(
                'mailbox.store ist "brain", aber peppermint/ai-brain-bridge fehlt. '
                .$was.' bleiben LOKAL — '.$folge.'.'
            );

            return false;
        }

        return true;
    }

    /**
     * Die Mailadresse zur oertlichen Kennung.
     *
     * Erst spaet aufgeloest: Beim Binden steht der Kontenspeicher unter
     * Umstaenden noch gar nicht.
     *
     * @return callable(int): ?string
     */
    private function postfachAdresse(): callable
    {
        return fn (int $konto): ?string => ($zeile = app(AccountStore::class)->find($konto))
            ? (string) $zeile->field('email')
            : null;
    }

    /**
     * Wie der Zustand in der Mitte angesprochen wird.
     *
     * ## `call()` und ausdruecklich NICHT der Gateway
     *
     * Der Gateway leitet an ein angebundenes PRODUKT weiter. AI Brain ist
     * keines — es ist die Nabe und steht in keinem Faehigkeits-Register.
     * `gateway(..., 'ai-brain')` haette also nie ein Ziel gefunden, und der
     * Ausfall waere als „Mitte nicht erreichbar" durchgegangen: Jede
     * Zustaendigkeit haette gefehlt, ohne dass etwas bricht.
     *
     * Dieselbe Entscheidung wie beim Kontenspeicher, aus demselben Grund —
     * dort steht sie ausfuehrlich begruendet.
     *
     * Die handelnde Person reist trotzdem mit: `brain()` setzt die
     * Acting-User-Kopfzeile, und die Mitte liest daraus, wer zuweist oder
     * weglegt. Sie glaubt die Angabe in der Nutzlast nicht, sie VERGLEICHT
     * sie damit.
     *
     * ## Vom Schluessel zum Werkzeug
     *
     * Die Speicher sprechen in Vorgaengen (`mail.assignments.set`), die Mitte
     * in Werkzeugen mit einer Aktion. Die Uebersetzung steht hier, an einer
     * Stelle — in jedem Speicher den Werkzeugnamen zu kennen hiesse, ihn bei
     * einer Umbenennung an zwei Stellen zu pflegen.
     *
     * @return callable(string, array<string, mixed>): ?array
     */
    private function mitteRufen(): callable
    {
        $bridge = self::BRIDGE;

        $werkzeuge = [
            'mail.assignments' => (string) config('mailbox.assignment_tool', 'mail-assignment-tool'),
            'mail.snoozes' => (string) config('mailbox.snooze_tool', 'mail-snooze-tool'),
        ];

        return function (string $vorgang, array $argumente) use ($bridge, $werkzeuge): ?array {
            $trennung = strrpos($vorgang, '.');
            $bereich = $trennung === false ? $vorgang : substr($vorgang, 0, $trennung);
            $aktion = $trennung === false ? '' : substr($vorgang, $trennung + 1);

            if (! isset($werkzeuge[$bereich])) {
                // Ein Vorgang ohne Werkzeug ist ein Programmfehler, kein
                // Betriebszustand. `null` heisst hier „nicht erreichbar", und
                // genau das soll es sein: Es wird nichts erfunden.
                Log::warning('Fuer diesen Mail-Vorgang ist kein Werkzeug der Mitte bekannt.', ['vorgang' => $vorgang]);

                return null;
            }

            return $bridge::call($werkzeuge[$bereich], ['action' => $aktion] + $argumente);
        };
    }

    /**
     * Woher die Werkzeuge der anderen Systeme kommen — oder eben keine.
     *
     * Ohne Bruecke gibt es keinen Verbund, den man fragen koennte. Dann
     * bleiben die EIGENEN Verben, und zwar vollstaendig: Eine eigenstaendige
     * Installation hat eine Werkzeugleiste, sie traegt nur nichts Fremdes.
     *
     * Eine Ausnahme zu werfen waere hier der falsche Klang — „nicht
     * eingerichtet" ist kein Fehler, sondern eine Betriebsart (siehe Wiki:
     * zwei Betriebsarten, nie beide zugleich).
     */
    private function werkzeugquelle(): Werkzeugquelle
    {
        if (! class_exists(self::BRIDGE)) {
            return new class implements Werkzeugquelle
            {
                public function anbieter(): array
                {
                    return [];
                }

                public function holen(string $slug, Vorgang $vorgang, string $adresse): array
                {
                    return ['tools' => [], 'failed' => []];
                }

                public function ausloesen(string $slug, string $schluessel, Vorgang $vorgang, array $daten, string $adresse): array
                {
                    // Erreichbar nur, wenn die Oberflaeche ein fremdes
                    // Werkzeug anbietet, das sie nie bekommen haben kann.
                    // „Nichts passiert" waere die schlechtere Antwort.
                    return ['ok' => false, 'message' => 'Dieses System ist nicht an den Verbund angeschlossen.'];
                }
            };
        }

        return new BridgeWerkzeugquelle;
    }

    private function store(): AccountStore
    {
        $art = config('mailbox.store', 'local');

        if (! in_array($art, ['brain', 'merged'], true)) {
            return new LocalAccountStore;
        }

        if (! class_exists(self::BRIDGE)) {
            Log::warning(
                'mailbox.store is set to "brain", but peppermint/ai-brain-bridge is not installed. '
                .'Mailboxes are kept LOCALLY — anyone assuming they are central will edit the wrong copy.'
            );

            return new LocalAccountStore;
        }

        $bridge = self::BRIDGE;
        $tool = (string) config('mailbox.brain_tool', 'mail-account-settings-tool');

        // Deliberately the tool path (`call`) and NOT the capability gateway.
        //
        // The gateway routes to ANOTHER product. `mail.accounts.list` exists
        // there and is answered by the mail-owning product with a deliberately
        // sparse list — addresses to pick from, no credentials. Asking it for
        // settings returns an account without a password, the product silently
        // falls back to its own old table, and nothing fails loudly.
        //
        // Central settings live in Brain itself, so they are read from Brain
        // itself.
        $zentral = new BrainAccountStore(
            function (string $_capability, array $arguments) use ($bridge, $tool): ?array {
                try {
                    return $bridge::call($tool, $arguments);
                } catch (\Throwable $e) {
                    // An unreachable central system is not an exception the
                    // caller should handle — it is the case the cache exists
                    // for. Returning null lets the store fall back to the last
                    // known good state.
                    Log::warning('Mailbox settings could not be read from AI Brain: '.$e->getMessage());

                    return null;
                }
            },
            (int) config('mailbox.cache_ttl', 900),
        );

        // `merged`: Die eigene Tabelle bleibt der Anker, die Verbindungsdaten
        // kommen bei jedem Lesen aus der Mitte. Fuer ein gewachsenes Produkt
        // ist das der einzige Weg, der seine eigenen Felder und Fremdschluessel
        // behaelt — siehe MergedAccountStore.
        return $art === 'merged'
            ? new MergedAccountStore(new LocalAccountStore, $zentral)
            : $zentral;
    }

    /**
     * The chat with the mailbox agent — or a version that stays quiet.
     *
     * Without the bridge there is no Brain to talk to. The chat then answers an
     * empty conversation rather than throwing: a product that installs the
     * package alone gets a mail browser, just without an assistant, and nothing
     * in it fails.
     */
    private function chat(): MailboxChat
    {
        if (! class_exists(self::BRIDGE)) {
            return new MailboxChat(fn (string $email): ?array => null, fn (int $chatId, string $content): ?array => null, verfuegbar: false);
        }

        $bridge = self::BRIDGE;
        $tool = (string) config('mailbox.chat_tool', 'mailbox-chat-tool');

        return new MailboxChat(
            // Service mode: the chat belongs to the MAILBOX, not to whoever is
            // logged in right now. Two people looking at the same shared mailbox
            // have to see the same conversation — a per-user chat would split it
            // in two and neither would know the other half exists.
            fn (string $email): ?array => $bridge::asService(
                fn (): array => $bridge::call($tool, ['email' => $email]),
            ),
            // Der Channel-Name ist ein Platzhalter, kein Ziel: `reply()` adressiert
            // allein ueber die chat_id und uebertraegt den Namen gar nicht. Hier
            // etwas Sprechendes hinzuschreiben waere deshalb eine Behauptung —
            // und wer spaeter einen echten Namen einsetzt, sucht den Fehler
            // anschliessend an der falschen Stelle.
            fn (int $chatId, string $content): ?array => $bridge::channel('_')->reply($chatId, $content),
        );
    }
}
