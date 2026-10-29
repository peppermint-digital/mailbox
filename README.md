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
php artisan mailbox:install
```

The second command does the three things that are easy to miss: it publishes
the config, installs the JavaScript half, and teaches Tailwind to look inside
the package. That last one matters more than it sounds — Tailwind v4 does not
scan `node_modules`, so without a `@source` line every class that appears ONLY
in this package is dropped. Nothing fails: the page builds, the component
appears, it just sits wrong.

Running it twice changes nothing. `--no-npm` skips the JavaScript half for a
backend-only installation.

The package runs standalone with its own table and has no idea AI Brain
exists.

## Where the settings live

```php
// config/mailbox.php
'store' => 'brain',   // read centrally from AI Brain, cached locally — the norm
'store' => 'local',   // own table, for an installation WITHOUT an AI Brain
```

Decided on 2026-10-13, after the fourth product moved over: where there is an AI
Brain, every product reads from it. `local` is for standing alone — open source,
or an installation that has no centre. A third mode existed for a product that
had carried its own mailbox table for years (`merged`); it is a transitional
state, not a target, and no product is on it any more.

**Attaching a new system?** See [docs/INTEGRATION.md](docs/INTEGRATION.md) — it
has the picture and the six steps.

**Adding verbs to a mail** — "book as an incoming invoice", "create a ticket",
"attach a task"? See [docs/WERKZEUGE.md](docs/WERKZEUGE.md). Since 19.10.2026
they meet in one toolbar across all systems instead of one per product window.

`brain` requires `peppermint/ai-brain-bridge`. When it is missing, the package
falls back to the local store **and logs a warning** — silently falling back
would mean someone believes the settings are central while they are local, and
edits the wrong copy on the next rotation.

The central store is read-only: changes belong where the truth lives. Ask
`AccountStore::isWritable()` before offering an edit form.

### Declaring that visibility is complete

A store that really knows who owns a mailbox and who may read it says so, with
one field in the payload:

```php
'sichtbarkeit_verbindlich' => true,
```

It changes what a *gap* means. Without the declaration, a personal mailbox that
arrives without `owner_email` stays visible — an older counterpart, or a grown
table, simply does not ship the field, and a product that does not get the
answer should not silently hide everything. With the declaration, the same gap
means the opposite: whoever claims to know the owner and then names none has a
hole in the payload, and a hole is not a licence on someone else's inbox.

The declaration is explicit and absent by default. A store that does not set it
behaves exactly as before — nothing changes for anyone who does not opt in.

**Why not infer it from the key being present?** The central payload always
carries `owner_email`, with `null` for group mailboxes. "Does not know" and
"knows and has a gap" would look identical.

It tightens the owner case only. An empty reader list still means *everyone*:
that is a statement, not a gap, and the centre sends it deliberately.

**For a product:** only once a store declares this may you rely on the centre
alone for visibility. Until then, keep whatever local check you have beside it —
see the two-part check in the manager's mail browser for the shape of that.

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

### What the list half brings

A mailbox list looks like one list and is really two, switched by a flag: flat
messages, or conversations with expandable members. `rows.ts` holds the rules
for getting from one to the other — every one of which has a way of going wrong
quietly.

```ts
import { buildDisplayRows, navigableRows, bulkTargets } from '@peppermint-digital/mailbox';

const rows = buildDisplayRows({
    messages, threads, groupByThread, isSearchMode, expandedThreads,
    // Your product's own notion of a filter. WHERE it applies — to conversation
    // heads, never to their members — is decided here.
    filter: assignedToMe ? (m) => m.assignedTo === me : undefined,
    toRow: yourThreadMessageToRow,
});
```

| | |
|---|---|
| `buildDisplayRows` | flat list or conversations; search stays flat either way |
| `unreadCountOf` | how many are unread, not just *that* something is |
| `navigableRows` | what the keyboard may land on |
| `bulkTargets` | what a tick really hits — the conversation, except in search |
| `threadActionUids` / `threadOf` | what archiving the open conversation moves |
| `canOpenMessage` / `hasUsableUid` | whether a row may be sent to the mailbox at all |

Three of these earn their place by being easy to get wrong:

- **Search is always flat.** Hits come from every folder, conversations only
  ever from the open one. Mixing them puts rows in a list that cannot hold them.
- **The keyboard skips stored replies.** They cannot be opened, so an arrow key
  landing on one looks like the keyboard is stuck.
- **`UID FETCH 0` is not "nothing happens"**, it is `message set is invalid`. A
  conversation member without its own uid becomes a row with uid 0, and the
  guard belongs before the request rather than after the error.

### Folders

```ts
import { looksLikeFolder, classifyFolder, isProtectedFolder } from '@peppermint-digital/mailbox';

looksLikeFolder({ path: 'Entw&APw-rfe' }, 'Drafts');          // true
looksLikeFolder({ path: 'Kladde', flags: ['\\Drafts'] }, 'Drafts'); // true
```

**Flags first, names second.** A server that sets `\Drafts` has answered the
question. Where the mark is missing, the alias table decides — the same table
`Folders\FolderNames` uses on the PHP side, including the IMAP modified UTF-7
spellings (`Entw&APw-rfe`, `Gel&APY-schte Elemente`) that a hand-written pattern
misses.

### Putting a whole mail browser together

Four components and five hooks. The components render, the hooks hold state
and talk to your endpoints — you supply the addresses, they supply the
sequence.

```tsx
import {
    MailboxBrowser, MailFolderList, MailMessageList, MailMessageView,
    useMailboxFolders, useMailboxList, useMessageSelection, useOpenMessage,
} from '@peppermint-digital/mailbox';

const folders  = useMailboxFolders({ source: yourFolderEndpoints });
const list     = useMailboxList({ source: yourMessageEndpoints });
const opened   = useOpenMessage({ source: yourMessageEndpoint });
const selected = useMessageSelection({ threads: list.threads, grouped: list.grouped, isSearchMode });

<MailboxBrowser
    showFolders={sidebarOpen}
    folders={<MailFolderList folders={folders.folders} labels={…} onSelect={…} />}
    list={<MailMessageList rows={rows} labels={…} onOpen={…} />}
    view={<MailMessageView message={opened.message} labels={…} />}
    aside={yourOwnColumn}
/>
```

| Hook | Holds |
|---|---|
| `useMailboxList` | messages, conversations, paging, the two loading states |
| `useMailboxFolders` | the sidebar, the move targets |
| `useOpenMessage` | the one message that is open, and which folder it really sits in |
| `useMessageSelection` | what is ticked, which conversations are open |

#### What every source must decide

Each hook takes a `source` whose methods you write — that is where your URLs
live. Two answers are expected of them, and keeping them apart matters more
than it looks:

```ts
async list(params) {
    const response = await fetch(yourUrl(params));
    const data = await response.json();

    // The server answered and refused — it usually knows why.
    if (!data.success) {
        return { failure: data.message };
    }

    return { messages: data.messages, threads: data.threads ?? [], total: data.total };
    // Throwing instead means the request never arrived. The hooks report that
    // as a different kind, because a refusal has a reason and a dead line
    // does not.
}
```

#### Three behaviours worth knowing before you wire them

**Nothing is shown that was overtaken.** Click two folders quickly and the
second wins, even if the first answers last. Every hook carries a run counter;
a late answer to an old question is dropped rather than rendered.

**A failed reload keeps the rows that were there.** An empty list and a broken
connection look identical to a reader, so the hook refuses to make one look
like the other.

**A message that left the mailbox is `list.messageLeft(uid)`, not
`removeRows`.** In conversation mode the row lives in a bundle whose count and
unread dot would start lying — so the list comes back from the server instead.

## Tests

```bash
composer install
vendor/bin/pest      # PHP half
npm install
npm test             # JavaScript half
```

### Testing sending, without a mail server

Sending has a seam, like mailboxes do. `Mailer::fake()` takes a closure and
returns whatever it builds instead of a real SMTP transport:

```php
use Peppermint\Mailbox\Sending\Mailer;
use Peppermint\Mailbox\Sending\RecordingTransport;

$outbox = new RecordingTransport;
Mailer::fake(fn () => $outbox);

// … send through your controller …

expect($outbox->anzahl())->toBe(1);
expect($outbox->letzteNachricht()->getHeaders()->get('To')->getBodyAsString())
    ->toContain('customer@example.test');

Mailer::fake(null);   // in afterEach — a test that forgets leaves the next
                      // one green without ever having sent anything
```

The closure receives the account, so one test can let one mailbox succeed while
another fails. For a failure, hand it a transport that throws — then you can
assert the thing that is otherwise invisible: that **nothing after the send ran**.
That order carries a rule worth keeping — once the message is out, nothing may
reach the sender as a failure, or they will send it a second time.

Without this seam that question needs a real mail server, and a question that
needs a real mail server does not get asked. What it cost before is on record:
five send tests in the project manager faked the *product's* service instead, so
they checked their own wiring and never the message. The moment that service
gave way to the package path, each of them ran into a 20-second SMTP timeout.

Two things it deliberately leaves alone. It does not touch the copy into the
Sent folder — that runs through `Mailboxes::for()` and has its own seam, and
keeping them apart means a green test cannot quietly claim both halves. And it
never pretends a failure: a transport that should fail is one you pass in.
