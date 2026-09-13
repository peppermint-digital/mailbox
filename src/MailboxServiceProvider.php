<?php

namespace Peppermint\Mailbox;

use Illuminate\Support\Facades\Log;
use Illuminate\Support\ServiceProvider;
use Peppermint\Mailbox\Contracts\AccountStore;
use Peppermint\Mailbox\Stores\BrainAccountStore;
use Peppermint\Mailbox\Stores\LocalAccountStore;

class MailboxServiceProvider extends ServiceProvider
{
    /** This class only exists when a usable central store is installed. */
    private const BRIDGE = 'Peppermint\\AiBrainBridge\\Facades\\AiBrain';

    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/mailbox.php', 'mailbox');

        $this->app->singleton(AccountStore::class, fn (): AccountStore => $this->store());
    }

    public function boot(): void
    {
        $this->publishes([
            __DIR__.'/../config/mailbox.php' => config_path('mailbox.php'),
        ], 'mailbox-config');

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
    private function store(): AccountStore
    {
        if (config('mailbox.store', 'local') !== 'brain') {
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
        return new BrainAccountStore(
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
    }
}
