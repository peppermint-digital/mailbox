<?php

namespace Peppermint\Mailbox\Stores;

use Illuminate\Support\Collection;
use Peppermint\Mailbox\Contracts\AccountStore;
use Peppermint\Mailbox\Models\MailAccount;

/**
 * The connection comes from the centre, everything else stays here.
 *
 * The third store, and the one a grown product needs. `local` and `brain` are
 * an either-or, and both are wrong for a product that has carried its own
 * mailbox table for years:
 *
 * - **`local`** means the central settings are copied in by hand. The copy is
 *   only as fresh as the last time someone ran the command, and two systems
 *   rotate the same OAuth refresh token against each other.
 * - **`brain`** hands out accounts that are not table rows. Everything the
 *   product hung on its own mailbox row — notification mode, sent-copy
 *   behaviour, send health, foreign keys from other tables — has nowhere to go.
 *
 * So: the local row stays the anchor, and the fields a protocol client needs
 * to connect are overwritten from the centre on every read.
 *
 * ## Only the connection is overwritten
 *
 * {@see CORE} is spelled out, not derived. "Everything the central row has"
 * would be the convenient rule and the dangerous one: a central `is_active`
 * would switch off a product's mailbox, a central label would rename it in a
 * screen nobody central ever saw. What travels is what it takes to connect —
 * host, port, encryption, login, protocol. Nothing about how a product behaves.
 *
 * ## Matched by address, not by id
 *
 * The same mailbox has different ids in every system; the address is what it
 * IS. Matching on ids would need a mapping table, and a mapping table is a
 * third truth to keep in sync.
 *
 * ## A mailbox the centre does not know stays untouched
 *
 * And says so: {@see MailAccount::isCentrallyManaged()} answers false for it.
 * Silently leaving it on local values would look exactly like a mailbox that
 * IS central — until someone rotates a password in the wrong place.
 */
class MergedAccountStore implements AccountStore
{
    /**
     * What a protocol client needs in order to connect — and nothing else.
     *
     * @var list<string>
     */
    public const CORE = [
        'protocol', 'jmap_url',
        'imap_host', 'imap_port', 'imap_encryption',
        'smtp_host', 'smtp_port', 'smtp_encryption',
        'auth_type', 'username', 'password', 'provider',
        'oauth_tenant_id', 'oauth_client_id', 'oauth_client_secret',
        'oauth_access_token', 'oauth_refresh_token', 'oauth_token_expires_at',
    ];

    public function __construct(
        private readonly AccountStore $local,
        private readonly AccountStore $central,
    ) {}

    public function all(?int $ownerId = null): Collection
    {
        $zentral = $this->centralByAddress();

        return $this->local->all($ownerId)->map(
            fn (MailAccount $konto): MailAccount => $this->merge($konto, $zentral)
        );
    }

    public function find(string|int $id): ?MailAccount
    {
        $konto = $this->local->find($id);

        return $konto === null ? null : $this->merge($konto, $this->centralByAddress());
    }

    /**
     * Writable — but not for the connection.
     *
     * The local row is a real row, and the product keeps writing its own
     * fields to it. That the connection fields would be overwritten on the
     * next read is the product's business to reflect in its forms; the store
     * cannot make half a row read-only.
     */
    public function isWritable(): bool
    {
        return $this->local->isWritable();
    }

    /**
     * The central inventory, keyed by address.
     *
     * @return array<string, MailAccount>
     */
    private function centralByAddress(): array
    {
        $nachAdresse = [];

        foreach ($this->central->all() as $konto) {
            $adresse = $this->address($konto);

            if ($adresse !== null) {
                $nachAdresse[$adresse] = $konto;
            }
        }

        return $nachAdresse;
    }

    /**
     * @param  array<string, MailAccount>  $zentral
     */
    private function merge(MailAccount $lokal, array $zentral): MailAccount
    {
        $adresse = $this->address($lokal);
        $quelle = $adresse === null ? null : ($zentral[$adresse] ?? null);

        if ($quelle === null) {
            // Kein Zufall und kein Fehler: Ein Produkt darf Postfaecher haben,
            // die zentral niemand kennt. Es muss nur unterscheidbar bleiben.
            return $lokal->fromLocalOnly();
        }

        $werte = [];

        foreach (self::CORE as $feld) {
            $wert = $quelle->field($feld);

            if ($wert === null) {
                // Ein zentral leeres Feld ist keine Aussage, sondern eine
                // Luecke — sonst loescht ein noch nicht gepflegtes SMTP-Feld
                // den funktionierenden lokalen Wert.
                continue;
            }

            $werte[MailAccount::column($feld)] = $wert;
        }

        return $lokal->withCentral($werte);
    }

    private function address(MailAccount $konto): ?string
    {
        $adresse = $konto->field('email');

        return is_string($adresse) && $adresse !== '' ? mb_strtolower(trim($adresse)) : null;
    }
}
