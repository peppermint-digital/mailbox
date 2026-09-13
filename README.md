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

## The rules that come with it

Beyond storing settings, the package carries the parts of a mail browser that
are the same everywhere — pure logic, no IMAP, no database, so every one of
them is checkable without a mailbox.

| | |
|---|---|
| `Threading\ThreadKey` | which conversation a message belongs to (RFC 5322 headers) |
| `Threading\ThreadGrouper` | message rows in, conversations out |
| `Content\CidReplacer` | `cid:` references in an HTML body → data URLs or real URLs |
| `Search\ResultMerger` | hits from the mailbox and from an archive, de-duplicated |
| `Health\HealthStatus` / `HealthRules` | what `health_status` may say, and when a mailbox counts as slow |
| `Index\MessageLocator` | finding a message the header index misplaced |
| `Imap\MessageFormatter` | an IMAP message → rows (needs `directorytree/imapengine`, a `suggest`) |
| `Folders\FolderNames` | which folder is trash, drafts, sent — by name, in several languages and IMAP's modified UTF-7 |

Two shapes recur, and both are deliberate.

**Rows in, rows out.** Anything that would need the product's own tables takes
them as plain arrays instead: `ThreadGrouper` receives the product's stored
replies, `ResultMerger` its archive hits. The package never learns what those
tables look like — which is the only reason it fits more than one product.

**The lookup is passed in.** `ThreadKey::resolveAgainst()` needs to know whether
a message is already known; that is a query, so the caller hands one in. A
package that guessed the message table would fit exactly the product it was
extracted from.

**No user-facing text.** A conversation without a subject gets `null`, not a
label. What a reader sees instead is the product's decision, in the product's
language.

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

## The JavaScript half

`@peppermint-digital/mailbox` carries the browser-side logic that is the same
everywhere. Same rule as on the PHP side: **no user-facing text**. Quoting an
original asks the product for its words and its date format, because "Am …
schrieb …" is a sentence in one language and this package does not get to pick
it.

```bash
npm install github:peppermint-digital/mailbox
```

```ts
import { buildReplyQuote } from '@peppermint-digital/mailbox';

buildReplyQuote(source, {
    labels: { repliedOn: (date, sender) => `Am ${date} schrieb ${sender}:`, /* … */ },
    formatDate: (iso) => new Date(iso).toLocaleString('de-DE'),
});
```

## Tests

```bash
composer install
vendor/bin/pest
```
