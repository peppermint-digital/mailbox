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
    | `merged` — the own table stays the anchor, and the CONNECTION fields are
    |           overwritten from AI Brain on every read. For a grown product:
    |           it keeps its own columns and its foreign keys, and still has
    |           only one truth for host, login and token.
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
    |--------------------------------------------------------------------------
    | The mailbox agent
    |--------------------------------------------------------------------------
    |
    | The tool that hands out the conversation with the agent working this
    | mailbox. Without the bridge installed, nothing here is reached and the
    | browser simply has no assistant.
    */
    'chat_tool' => env('MAILBOX_CHAT_TOOL', 'mailbox-chat-tool'),

    /*
    | Die Werkzeuge der Mitte fuer Zustaendigkeit und Geschlummertes. Nur
    | gebraucht, wenn `store: brain` — ein eigenstaendiges System fuehrt beides
    | in seinen eigenen Tabellen.
    */
    'assignment_tool' => env('MAILBOX_ASSIGNMENT_TOOL', 'mail-assignment-tool'),
    'snooze_tool' => env('MAILBOX_SNOOZE_TOOL', 'mail-snooze-tool'),

    /*
    | Das Werkzeug, ueber das ein Produkt ohne eigene Ablage den
    | Gespraechsverlauf aus der Mitte liest.
    */
    'conversation_tool' => env('MAILBOX_CONVERSATION_TOOL', 'mail-conversation-tool'),

    /*
    | Wie lange der Verlauf zwischengespeichert wird.
    |
    | Die Verfuegbarkeits-Frage stellt die Oberflaeche bei JEDER geoeffneten
    | Nachricht — die darf laenger halten. Der Verlauf selbst waechst, wenn
    | eine Antwort kommt, und soll nicht minutenlang alt aussehen.
    */
    'conversation_cache' => [
        'available_ttl' => (int) env('MAILBOX_CONVERSATION_AVAILABLE_TTL', 300),
        'entries_ttl' => (int) env('MAILBOX_CONVERSATION_TTL', 60),
    ],

    /*
    | How long a central state is kept locally. This is the answer to "what if
    | the central system is down": the lookup freezes on the last known good
    | state instead of failing. Only success is cached.
    |
    | Nach oben gedeckelt, und zwar hart: Mitgespeichert wird ein
    | Zugriffstoken, und die Mitte sichert ihm nur eine Mindestlaufzeit zu. Ein
    | laengeres Fenster hielte ein totes Token vor, und jede Anmeldung daraus
    | scheiterte (Bug #1098). Was darueber steht, wird auf den Deckel gerechnet
    | und einmal je Prozess ins Protokoll geschrieben — die Zahl hier ist also
    | eine Obergrenze fuer eine Obergrenze. Siehe
    | `Peppermint\Mailbox\Accounts\Zwischenspeicherfenster`.
    */
    'cache_ttl' => (int) env('MAILBOX_CACHE_TTL', 900),

    /*
    | How long a mailbox's FOLDER LIST is kept. Measured 23.09.2026: 485 ms per
    | call, and the second call cost the same as the first — there was no cache
    | at all. Folders change almost never, which is why this one list is worth
    | keeping and message lists are not: a minute-old message list means "the
    | mail that just arrived is missing".
    |
    | The cache is cleared explicitly whenever a folder is created, renamed or
    | deleted, so a new folder shows up at once. `0` switches it off.
    */
    'folders_cache_ttl' => (int) env('MAILBOX_FOLDERS_CACHE_TTL', 600),

    /*
    |--------------------------------------------------------------------------
    | Kopfzeilen-Verzeichnis
    |--------------------------------------------------------------------------
    |
    | Verzeichnet, was in einer Kennung steht — Betreff, Absender, Datum,
    | Message-ID, Anhangzahl. Also alles, was sich fuer eine gegebene UID nicht
    | mehr aendern kann. Lesemarken stehen NICHT darin.
    |
    | Gemessen am 20.10.2026 an `department@peppermint-digital.de`, einer Seite
    | von 25 aus 56 Nachrichten, am laufenden Brain:
    |
    |     ohne Verzeichnis      2.045 ms
    |     kalter Ordner         2.168 ms   (einmal je Nachricht)
    |     warmer Ordner       520–605 ms
    |
    | Der kalte Fall ist NICHT besser: Er zahlt einen UID-Abruf und einen
    | Flags-Abruf mehr und spart nur den teureren `count()`. Das Verzeichnis
    | macht keinen Aufruf schneller, den es noch nicht gesehen hat — es macht
    | jeden zweiten schneller.
    |
    | Abgeschaltet verhaelt sich die Liste wie bei einem Ordner, den sie zum
    | ersten Mal sieht — also wie vor dem Verzeichnis. Der Schalter ist fuer den
    | Fall gedacht, dass eine Liste einmal etwas Falsches zeigt: Dann ist er die
    | Antwort von einer Minute, waehrend die Ursache gesucht wird.
    |
    */

    'header_index' => (bool) env('MAILBOX_HEADER_INDEX', true),

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

        // Wer sich um welche Unterhaltung kuemmert. Der Projekt-Manager fuehrt
        // diese Daten seit Juni 2026 unter `email_assignments` und stellt den
        // Namen deshalb um — gewachsene Daten zu bewegen hilft niemandem.
        'assignments' => 'mail_assignments',

        // „Nicht jetzt — erinnere mich wieder ab dann." Pro Person.
        'snoozes' => 'mail_snoozes',

        /*
        | Die Ablage: Nachricht, Orte, Gespraechstext.
        |
        | Sie entsteht nur, wo eine Migration sie anlegt — ein Produkt, das
        | bloss liest, braucht sie nicht.
        */
        'messages' => 'mail_messages',
        'locations' => 'mail_locations',
        'bodies' => 'mail_bodies',
        'folder_states' => 'mail_folder_states',
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
    |--------------------------------------------------------------------------
    | Shared mailboxes
    |--------------------------------------------------------------------------
    |
    | A mailbox without an owner is shared — a team address. Who may work in it
    | is the product's business, so the package only asks where the list is.
    |
    | `table => null` means the product has no access lists: shared mailboxes
    | are open to everyone. With a table configured, a shared mailbox that has
    | NO entry stays open to everyone, and one WITH entries is limited to the
    | people listed. Switching the feature on therefore never takes a mailbox
    | away from someone overnight.
    |
    */
    'sharing' => [
        'table' => 'mail_account_user',
        'account_key' => 'mail_account_id',
        'user_key' => 'user_id',
    ],

    /*
    | Turn off when the tables already exist and are mapped above.
    */
    /*
    |--------------------------------------------------------------------------
    | Die Ablage der Rohfassungen
    |--------------------------------------------------------------------------
    |
    | Wo die Bytes einer archivierten Nachricht liegen. In der Datenbank steht
    | nur Pfad und Pruefsumme — ein Anhang von zwanzig Megabyte macht dort jede
    | Sicherung teurer, ohne dass je darin gesucht wuerde.
    |
    | Wer woanders hin will (Objektspeicher, WORM-Volume, ein Archivsystem),
    | bindet eine eigene Umsetzung von `Archive\Ablage` — dieser Wert gilt
    | dann nicht mehr.
    */
    'archive' => [
        'disk' => env('MAILBOX_ARCHIVE_DISK', 'local'),
    ],

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

    /*
    |--------------------------------------------------------------------------
    | Die Werkzeuge am Vorgang
    |--------------------------------------------------------------------------
    |
    | „Als Eingangsrechnung", „Ticket erstellen", „Aufgabe anhaengen" — die
    | Verben, die ein Produkt an einer Mail anbietet. Angemeldet werden sie im
    | Dienstanbieter des Produkts, nicht hier:
    |
    |     app(Werkzeugverzeichnis::class)->anmelden(AlsEingangsrechnung::class);
    |
    | Der Grund ist derselbe wie bei den Inbox-Arten: Ein Verb kommt selten
    | allein. Wer eines einfuehrt, bringt die Klasse mit, die es ausfuehrt —
    | beides gehoert nebeneinander und nicht auseinandergerissen auf Code und
    | Konfiguration.
    |
    */
    'werkzeuge' => [
        // Platz fuer kuenftige Einstellungen der Leiste. Die Verben selbst
        // werden angemeldet, nicht konfiguriert.
    ],

    /*
    |--------------------------------------------------------------------------
    | An welchem Model die Menschen haengen
    |--------------------------------------------------------------------------
    |
    | Das kann das Paket nicht wissen, und es braucht es an zwei Stellen: Die
    | MCP-Werkzeuge loesen die handelnde Person aus einer Adresse auf, und der
    | Umzug in die Mitte muss zu einer Benutzer-Nummer die Adresse finden.
    |
    | Immer die ADRESSE: Jedes Produkt fuehrt seine eigene Nutzertabelle, und
    | Nutzer 5 ist hier jemand anders als dort.
    |
    */
    'user_model' => env('MAILBOX_USER_MODEL', 'App\\Models\\User'),

];
