<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Peppermint\Mailbox\Models\MailAccount;

/**
 * Which protocol this mailbox is reached with.
 *
 * Until now the answer was "IMAP", everywhere, and it did not need saying. Our
 * own Stalwart speaks JMAP (RFC 8620/8621), which is one HTTP request where
 * IMAP is a connection per call — but Office 365 and Google do not offer it,
 * so this is a second way, not a new one.
 *
 * ## Why this is core and not a product's business
 *
 * The line for this table is "what a protocol client needs in order to
 * connect". Which protocol to speak is the first thing it needs; without it
 * the client guesses, and a guess here is a login against the wrong endpoint.
 *
 * ## Why the default carries the weight
 *
 * `imap` as the column default means every existing row is correct without
 * being touched, and a product that never migrates reads `null` and gets the
 * same answer from {@see MailAccount::transport()}.
 * Switching a mailbox over is a single field, and switching it back is too —
 * the IMAP credentials stay where they are.
 *
 * ## Why jmap_url is separate and nullable
 *
 * Discovery is defined: `https://<host>/.well-known/jmap`. For Stalwart the
 * mail host answers that, so nothing needs to be stored. Providers that split
 * the two (Fastmail serves its API from a different host than its IMAP) would
 * otherwise be unreachable, and the alternative — a second account row — would
 * mean the same mailbox with two passwords.
 */
return new class extends Migration
{
    public function up(): void
    {
        $tabelle = config('mailbox.tables.accounts', 'mail_accounts');

        Schema::table($tabelle, function (Blueprint $table) use ($tabelle): void {
            if (! Schema::hasColumn($tabelle, 'protocol')) {
                $table->string('protocol', 10)->default('imap')->after('email');
            }

            if (! Schema::hasColumn($tabelle, 'jmap_url')) {
                $table->string('jmap_url')->nullable()->after('imap_encryption');
            }
        });
    }

    public function down(): void
    {
        $tabelle = config('mailbox.tables.accounts', 'mail_accounts');

        Schema::table($tabelle, function (Blueprint $table): void {
            $table->dropColumn(['protocol', 'jmap_url']);
        });
    }
};
