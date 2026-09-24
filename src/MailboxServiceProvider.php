<?php

namespace Peppermint\Mailbox;

use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\ServiceProvider;
use Peppermint\Mailbox\Brain\MailboxChat;
use Peppermint\Mailbox\Console\InstallCommand;
use Peppermint\Mailbox\Contracts\AccountStore;
use Peppermint\Mailbox\Contracts\Verlaufsspeicher;
use Peppermint\Mailbox\Stores\BrainAccountStore;
use Peppermint\Mailbox\Stores\BrainVerlaufsspeicher;
use Peppermint\Mailbox\Stores\LokalerVerlaufsspeicher;
use Peppermint\Mailbox\Stores\LocalAccountStore;
use Peppermint\Mailbox\Stores\MergedAccountStore;

class MailboxServiceProvider extends ServiceProvider
{
    /** This class only exists when a usable central store is installed. */
    private const BRIDGE = 'Peppermint\\AiBrainBridge\\Facades\\AiBrain';

    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/mailbox.php', 'mailbox');

        $this->app->singleton(AccountStore::class, fn (): AccountStore => $this->store());
        $this->app->singleton(Verlaufsspeicher::class, fn (): Verlaufsspeicher => $this->verlaufsspeicher());

        $this->app->singleton(MailboxChat::class, fn (): MailboxChat => $this->chat());
    }

    public function boot(): void
    {
        $this->publishes([
            __DIR__.'/../config/mailbox.php' => config_path('mailbox.php'),
        ], 'mailbox-config');

        if ($this->app->runningInConsole()) {
            $this->commands([InstallCommand::class]);
        }

        if (config('mailbox.run_migrations', true)) {
            $this->loadMigrationsFrom(__DIR__.'/../database/migrations');
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
        );
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
