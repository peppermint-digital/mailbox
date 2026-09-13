<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Where mailbox settings come from
    |--------------------------------------------------------------------------
    |
    | `local` — own table. The default. With this the package runs on its own,
    |           without any further integration.
    | `brain` — read centrally from AI Brain, cached locally. Requires
    |           peppermint/ai-brain-bridge; when that is missing the package
    |           falls back to `local` and says so, instead of failing quietly.
    |
    */
    'store' => env('MAILBOX_STORE', 'local'),

    /*
    | The Brain-side tool that hands out the settings. It answers with the
    | mailboxes granted to THIS product — nothing granted means nothing.
    |
    | This is a tool on Brain, not a capability on the gateway: the gateway
    | routes to another product, where a similarly named capability returns a
    | sparse address list without credentials.
    */
    'brain_tool' => env('MAILBOX_BRAIN_TOOL', 'mail-account-settings-tool'),

    /*
    | How long a central state is kept locally. This is the answer to "what if
    | the central system is down": the lookup freezes on the last known good
    | state instead of failing. Only success is cached.
    */
    'cache_ttl' => (int) env('MAILBOX_CACHE_TTL', 900),

    /*
    |--------------------------------------------------------------------------
    | Adoption — so a grown product can join
    |--------------------------------------------------------------------------
    |
    | Mandatory, not optional: a package that only runs on fresh tables cannot
    | take over an application that has carried its own column names for years
    | — and then the package gets built a second time.
    |
    | Map every core field your table names differently. Fields missing here
    | keep the package name.
    |
    */
    'tables' => [
        'accounts' => 'mail_accounts',
    ],

    'columns' => [
        // 'email' => 'email_address',
        // 'imap_host' => 'host',
        // 'imap_port' => 'port',
        // 'imap_encryption' => 'encryption',
        // 'label' => 'name',
        // 'last_checked_at' => 'last_health_at',
    ],

    /*
    | Turn off when the tables already exist and are mapped above.
    */
    'run_migrations' => env('MAILBOX_RUN_MIGRATIONS', true),

    /*
    |--------------------------------------------------------------------------
    | Who may see credentials in clear text
    |--------------------------------------------------------------------------
    |
    | Stays with the product — the package only asks for a name. Authorisation
    | is the product's business; otherwise the package carries role models it
    | knows nothing about.
    |
    */
    'view_secrets_ability' => env('MAILBOX_SECRET_ABILITY'),

];
