<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The core table for mailboxes — for FRESH installations only.
 *
 * A grown product switches it off via `mailbox.run_migrations => false` and
 * maps its existing columns instead. That is exactly why adoption is a
 * mandatory feature and not an extra.
 *
 * ## Where the line runs, and what it is measured against
 *
 * Core is what a protocol client needs in order to connect — an external
 * measure, not a matter of taste. Anything describing our own behaviour stays
 * with the product.
 *
 * One group escapes that rule and is core anyway: the five health fields. Two
 * products invented them independently, with identical names
 * (`health_status`, `last_reachable`, `last_auth_ok`, `last_latency_ms`,
 * `last_error`). Two implementations converging on the same five names is the
 * strongest evidence that it would look the same everywhere.
 *
 * ## What is deliberately NOT here
 *
 * Sent-folder copies, notification modes, pending-hint counters, send health —
 * one product each. A field only one consumer needs does not belong in the
 * shared middle, not even "for later": it travels into every product that will
 * never use it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create(config('mailbox.tables.accounts', 'mail_accounts'), function (Blueprint $table): void {
            $table->id();

            // Without this there is no mailbox.
            $table->string('email');

            // What the protocol asks for. All nullable: a send-only account
            // has no IMAP, a fetch-only account no SMTP.
            $table->string('label')->nullable();
            $table->string('imap_host')->nullable();
            $table->unsignedSmallInteger('imap_port')->nullable();
            $table->string('imap_encryption', 10)->nullable();
            $table->string('smtp_host')->nullable();
            $table->unsignedSmallInteger('smtp_port')->nullable();
            $table->string('smtp_encryption', 10)->nullable();

            // CalDAV belongs on the account, not in the calendar: it is the
            // same login against the same remote. If the URL lived in the
            // calendar package, the credentials would exist twice — and on the
            // next password change one of the two places gets forgotten.
            //
            // ONLY the URL lives here. Everything else about calendars — which
            // ones, how often, what happens with them — stays in the calendar
            // package.
            $table->string('caldav_url')->nullable();

            // Password OR OAuth. `auth_type` says which of the two applies —
            // without it the client guesses, and Office 365 punishes guessing
            // with ten seconds per message (measured 13.09.2026).
            $table->string('auth_type', 20)->default('password');
            $table->string('username')->nullable();
            $table->text('password')->nullable();
            $table->string('provider')->nullable();
            $table->string('oauth_tenant_id')->nullable();
            $table->text('oauth_client_id')->nullable();
            $table->text('oauth_client_secret')->nullable();
            $table->text('oauth_access_token')->nullable();
            $table->text('oauth_refresh_token')->nullable();
            $table->timestamp('oauth_token_expires_at')->nullable();

            // State
            $table->unsignedBigInteger('user_id')->nullable()->index();
            $table->boolean('is_active')->default(true);
            $table->timestamp('last_synced_at')->nullable();

            // Health — invented independently by both products.
            $table->string('health_status', 20)->nullable();
            $table->boolean('last_reachable')->nullable();
            $table->boolean('last_auth_ok')->nullable();
            $table->unsignedInteger('last_latency_ms')->nullable();
            $table->text('last_error')->nullable();
            $table->timestamp('last_checked_at')->nullable();

            $table->timestamps();

            $table->unique(['email', 'user_id']);
        });

        // Who may work in a shared mailbox. Deliberately a separate table and
        // not a column: a team address has several people, and an account with
        // NO entry here stays open to everyone.
        Schema::create(config('mailbox.sharing.table', 'mail_account_user'), function (Blueprint $table): void {
            $table->foreignId(config('mailbox.sharing.account_key', 'mail_account_id'))
                ->constrained(config('mailbox.tables.accounts', 'mail_accounts'))
                ->cascadeOnDelete();
            $table->unsignedBigInteger(config('mailbox.sharing.user_key', 'user_id'));

            $table->primary([
                config('mailbox.sharing.account_key', 'mail_account_id'),
                config('mailbox.sharing.user_key', 'user_id'),
            ]);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists(config('mailbox.sharing.table', 'mail_account_user'));
        Schema::dropIfExists(config('mailbox.tables.accounts', 'mail_accounts'));
    }
};
