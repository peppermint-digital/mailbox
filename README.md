# peppermint/mailbox

Mailboxes as a shared package: one core table, one contract for **where the
settings live**, and adoption of tables that already exist.

## Why

Three products each grew their own mailbox screen. The code being duplicated
was the visible half of the problem; the invisible half was that the same
mailbox existed three times, with three passwords, and a password rotation
reached two of them.

So this package does not only share code. It decides where the truth lives —
and it can do that without moving a single row, because it adapts to the column
names a product already uses.

## Install

```bash
composer require peppermint/mailbox
```

That is enough. The package runs standalone with its own table and has no idea
AI Brain exists.

## Where the settings live

```php
// config/mailbox.php
'store' => 'local',   // own table (default)
'store' => 'brain',   // read centrally from AI Brain, cached locally
```

`brain` requires `peppermint/ai-brain-bridge`. When it is missing, the package
falls back to the local store **and logs a warning** — silently falling back
would mean someone believes the settings are central while they are local, and
edits the wrong copy on the next rotation.

The central store is read-only: changes belong where the truth lives. Ask
`AccountStore::isWritable()` before offering an edit form.

### When the central system is down

The lookup freezes on the last known good state instead of failing. Only
success is cached — caching a failure would overwrite that state and turn a
short outage into a long one.

## Adoption: joining with a grown table

A package that only runs on fresh tables cannot take over an application that
has carried its own column names for years — so it gets built a second time,
which is the problem this package exists to solve.

```php
'tables' => ['accounts' => 'email_accounts'],

'columns' => [
    'label'           => 'name',
    'imap_host'       => 'host',
    'imap_port'       => 'port',
    'imap_encryption' => 'encryption',
    'last_checked_at' => 'last_health_at',
],

'run_migrations' => false,   // the tables already exist
```

Package code reads core fields through `MailAccount::column()` /
`$account->field('imap_host')`, so it never needs to know what the product
calls them. Fields you do not map keep the package name.

## What is core, and what is not

Core is what a protocol client needs in order to connect and send — an external
measure, not a matter of taste. Sent-folder copies, notification modes and
pending-hint counters describe *our* behaviour and stay with the product: a
column in the shared middle travels into every product that will never use it.

One group escapes that rule and is core anyway. Two products invented the same
five health fields independently, with identical names — `health_status`,
`last_reachable`, `last_auth_ok`, `last_latency_ms`, `last_error`. Two
implementations converging on the same five names is the strongest evidence
that it would look the same everywhere.

## Tests

```bash
composer install
vendor/bin/pest
```
