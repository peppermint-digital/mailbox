<?php

namespace Peppermint\Mailbox\Stores;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Peppermint\Mailbox\Contracts\AccountStore;
use Peppermint\Mailbox\Contracts\VerwirftKonten;
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
 *
 * ## Und was, wenn der gespeicherte Stand nicht mehr gilt?
 *
 * Dafür gibt es {@see verwerfen()}. Der Zwischenspeicher hält auch ein
 * Zugriffstoken, und das kann noch vor seinem Fenster ungültig werden — dann
 * steht hier eine Angabe, die beim Anmelden abgelehnt wird. Siehe
 * {@see VerwirftKonten}.
 */
class BrainAccountStore implements AccountStore, VerwirftKonten
{
    /**
     * Unter welchem Schlüssel das Verzeichnis der benutzten Schlüssel steht.
     *
     * Ohne das wüsste {@see verwerfen()} ohne Besitzer-Angabe nicht, welche
     * Listen überhaupt im Speicher liegen — `mailbox.brain.accounts.*` lässt
     * sich nicht auflisten, kein Laravel-Treiber kann das über alle Backends.
     * Ein halbes Verwerfen wäre schlimmer als keines: Es sähe nach Aufräumen
     * aus und ließe die Liste stehen, aus der das tote Token kommt.
     */
    private const VERZEICHNIS = 'mailbox.brain.accounts.__schluessel';

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
        $key = $this->schluessel($ownerId);
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
            $this->merken($key);
        }

        return collect($raw)->map(fn (array $row): MailAccount => MailAccount::fromRemote($row));
    }

    /**
     * Vergisst die gespeicherte Kontenliste — mehr nicht.
     *
     * Hier wird bewusst **nichts erneuert**. Das Produkt wirft weg, was es
     * gespeichert hat; das nächste {@see all()} fragt die Mitte, und die prägt
     * beim Lesen ein frisches Token. Würde das Produkt selbst erneuern,
     * entwertete es das Erneuerungs-Token der Mitte — der Fehler fiele dann
     * irgendwo anders auf.
     *
     * Die Liste ohne Besitzer wird immer mit vergessen, auch wenn eine
     * bestimmte Person genannt ist: Das Konto, um das es geht, steht in beiden.
     */
    public function verwerfen(?int $ownerId = null): void
    {
        if ($ownerId !== null) {
            Cache::forget($this->schluessel($ownerId));
            Cache::forget($this->schluessel(null));

            return;
        }

        foreach ((array) Cache::get(self::VERZEICHNIS, []) as $key) {
            Cache::forget((string) $key);
        }

        Cache::forget($this->schluessel(null));
        Cache::forget(self::VERZEICHNIS);
    }

    private function schluessel(?int $ownerId): string
    {
        return 'mailbox.brain.accounts.'.($ownerId ?? 'all');
    }

    /**
     * Hält fest, dass unter diesem Schlüssel etwas liegt.
     *
     * Das Verzeichnis lebt länger als die Listen selbst (doppelte Dauer), damit
     * ein Schlüssel nicht aus dem Verzeichnis fällt, während seine Liste noch
     * steht — sonst bliebe genau die eine Liste beim Verwerfen zurück.
     */
    private function merken(string $key): void
    {
        $bekannt = (array) Cache::get(self::VERZEICHNIS, []);

        if (in_array($key, $bekannt, true)) {
            return;
        }

        $bekannt[] = $key;
        Cache::put(self::VERZEICHNIS, array_values($bekannt), $this->ttl * 2);
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
