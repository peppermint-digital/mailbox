<?php

namespace Peppermint\Mailbox\Stores;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Peppermint\Mailbox\Contracts\AccountStore;
use Peppermint\Mailbox\Models\MailAccount;

/**
 * Accounts live centrally in AI Brain.
 *
 * ## The cache is not a speed trick
 *
 * It is the answer to a question that belongs in the design rather than in
 * operations: what happens when the central system is unreachable? The lookup
 * freezes on the last known good state instead of failing. An account whose
 * password changed in the meantime will break — visibly, and acceptably. A
 * mail browser that never appears would not be.
 *
 * Only success is cached. Caching a failure would overwrite the last known
 * good state and turn a short outage into a long one.
 *
 * ## Read-only
 *
 * Changes belong where the truth lives. The UI learns this through
 * {@see isWritable()} and then offers no form whose save button leads nowhere.
 */
class BrainAccountStore implements AccountStore
{
    /**
     * @param  callable(string, array<string, mixed>): ?array  $fetch  Returns
     *         the decoded answer, or null when the central system could not be
     *         reached — null is a state, not an error to be thrown.
     */
    public function __construct(
        private $fetch,
        private readonly int $ttl = 900,
    ) {}

    public function all(?int $ownerId = null): Collection
    {
        $key = 'mailbox.brain.accounts.'.($ownerId ?? 'all');
        $raw = Cache::get($key);

        if ($raw === null) {
            $response = ($this->fetch)('mail.accounts.list', array_filter(['owner_id' => $ownerId]));

            if ($response === null) {
                // No fresh state and no cached one: return nothing rather than
                // throwing. The screen still has to render.
                Log::warning('Mailbox settings unreachable, and no cached state is available.');

                return collect();
            }

            $raw = $response['data'] ?? [];
            Cache::put($key, $raw, $this->ttl);
        }

        return collect($raw)->map(fn (array $row): MailAccount => MailAccount::fromRemote($row));
    }

    public function find(string|int $id): ?MailAccount
    {
        return $this->all()->firstWhere('id', $id);
    }

    public function isWritable(): bool
    {
        return false;
    }
}
