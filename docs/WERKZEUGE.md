# One toolbar, every system

What a product can *do* with a mail — "book as an incoming invoice", "create a
ticket", "attach a task", "link the contact" — and why those verbs now meet in
**one** toolbar instead of three.

Read [the README](../README.md) for what the package is and
[INTEGRATION.md](INTEGRATION.md) for wiring a system. This file is for the
question **"where do the product's own verbs live?"**

## The decision, and the one it replaces

Decided by the user on **19.10.2026**:

> „Ich kann ja eine Werkzeug-Overlay-Leiste machen für alle Systemtools, die
> mir freigeschaltet sind. Also globalisiere ich ja auch sozusagen die
> Werkzeugleiste und habe dann eine Ansicht, die alles trägt, was ja mehr Sinn
> macht als drei verschiedene Ansichten, wo sich jeder Nutzer wieder umgewöhnen
> muss."

This **overrides** the rule from 14.10.2026, which said the opposite — „Die
Werkzeugleisten, ja, die sollten verschieden sein." That sentence was right
about one thing and wrong about another:

| Still true | No longer true |
|---|---|
| The *verbs* belong to the product. The package must not know what a ticket is. | The *toolbar* belongs to the product. It is one surface, and it carries whatever this person is granted. |

The difference matters because it is the whole point: a verb stays the
product's, but it is no longer trapped in the product's window.

## Why this cannot be an import

Grundsatz (binding, cross-project): **two core packages must not know each
other.** A mailbox package that imports `tasks` cannot be shipped without the
task manager, and after three such decisions everything hangs off everything.

So the package describes only the *shape* of a verb. What it means is known
only to the product that registers it — and across system borders the verbs are
*fetched*, never linked.

This is the same mechanism `peppermint/inbox` already runs on (kinds + sources +
one merged list). Deliberately the same, down to the naming: one pattern for
"many systems contribute to one surface" is enough for anyone to learn.

## The pieces

| Piece | What for |
|---|---|
| `Werkzeuge\Werkzeug` | the shape of a verb — key, word, icon, order, group, fields, state |
| `Werkzeuge\Vorgangswerkzeug` | what **this** system contributes: `werkzeug()` · `anwendbar()` · `zustand()` · `verweis()` · `ausfuehren()` |
| `Werkzeuge\LiefertFelder` | the extra contract for a verb that asks before it acts |
| `Werkzeuge\LiefertAuswahl` | the extra contract for a verb whose choices must be searched |
| `Werkzeuge\SuchendeWerkzeugquelle` | a transport that can carry a field search across systems |
| `Werkzeuge\Werkzeugverzeichnis` | what this system registered |
| `Werkzeuge\GesamtWerkzeuge` | own verbs plus the other systems', merged into one toolbar |
| `Werkzeuge\Vorgang` | **which mail** — by address, thread key and Message-ID |
| `Werkzeuge\VorgangsNachricht` | that mail, fetched and **checked** — for a verb that needs the mail itself |
| `Werkzeuge\Nachrichtenfund` | what it hands out: folder, handle, message — and the attachment **by name** |
| `Http\HandlesWerkzeuge` | the two routes a product mounts |
| `Mcp\ListMailboxActionsTool` / `RunMailboxActionTool` | the provider side, so a system can be asked |

## Registering

Next to everything else the product contributes, in its service provider:

```php
app(Werkzeugverzeichnis::class)->anmelden(AlsEingangsrechnung::class);
```

A product that installs the package and registers nothing has an **empty**
toolbar. That is the correct starting state: the verbs are the product.

```php
class AlsEingangsrechnung implements Vorgangswerkzeug
{
    public function werkzeug(): Werkzeug
    {
        return new Werkzeug(
            schluessel: 'beleg',
            wort: 'Als Eingangsrechnung',
            symbol: 'receipt',
            gruppe: 'verbuchen',
        );
    }

    // Is the button sensible here? NOT a permission check.
    public function anwendbar(Vorgang $vorgang, Model $person): bool
    {
        return $vorgang->kette !== null;
    }

    // Does the thing already exist? The package cannot know — you can.
    public function zustand(Vorgang $vorgang, Model $person): ?array
    {
        $beleg = Beleg::query()->where('mail_thread', $vorgang->anker())->first();

        return $beleg ? ['wort' => "Beleg #{$beleg->nummer}", 'verweis' => route('belege.show', $beleg)] : null;
    }

    // Do it — and check the permission yourself, every time.
    public function ausfuehren(Vorgang $vorgang, array $daten, Model $person): Ergebnis
    {
        if (! $person->can('create', Beleg::class)) {
            return Ergebnis::gescheitert('Dafür fehlt dir die Berechtigung.');
        }

        $beleg = Beleg::ausMail($vorgang);

        return Ergebnis::gelungen("Beleg #{$beleg->nummer} angelegt", verweis: route('belege.show', $beleg));
    }
}
```

## The routes

```php
class MailboxController extends Controller
{
    use HandlesWerkzeuge;   // GET {account}/tools + POST {account}/tools/run + /tools/options

    protected function werkzeugPerson(): ?Model
    {
        return request()->user();
    }

    protected function guardWerkzeuge(int $account): void
    {
        abort_unless($this->darfPostfach($account), 403);
    }
}
```

`guardWerkzeuge()` is **abstract**, unlike `guardAssignments()`. These routes do
not only reveal what is possible, they trigger it — in *other* systems. A
forgotten riddle here is not a mailbox opened too wide; it is a receipt booked
in a colleague's accounting.

## Asking before doing: the mask in the bar

A verb may need input. It declares that by implementing `Werkzeuge\LiefertFelder`
— a second interface, asked by `instanceof`, so the three product tools that
exist today do not break when a capability most verbs never need is added.

The fields are collected **in the bar**, in a mask that opens under the verb.
Not in a dialog, and not in the product's own window: a verb that sends you
somewhere to type two words has already lost the thing this toolbar is for.

Three field types, and the third is the interesting one:

| `art` | What the bar draws | Who supplies the choices |
|---|---|---|
| `select` | a dropdown | **you**, in `auswahl` |
| `text` | one line | nobody |
| `anhang` | the attachments of **this** mail | the **package** |
| `suche` | a picker with a search box | **you**, via `LiefertAuswahl` |

`anhang` carries no `auswahl` — anything put there is ignored. The package
already knows the attachments; it is showing them. Having every product collect
them again would be the same list from a worse source, and a foreign verb could
not do it at all. What arrives is the **file name**, never the index: the index
only holds while both sides see the same mail in the same order, and for a mail
another system fetched that is an assumption, not a fact.

Which leaves the verb with a name and a need for bytes. Do **not** count the
attachments yourself — ask for the mail and let it hand the file out:

```php
$fund = app(VorgangsNachricht::class)->finden($vorgang, $konto);   // $konto: YOUR visibility rule

if ($fund === null) {
    return Ergebnis::gescheitert('Diese Mail ist im Postfach nicht mehr zu finden.');
}

try {
    $anhang = $fund->anhang($daten['anhang']);        // one — or ->anhaenge([...]) for `mehrfach`
} catch (AnhangNichtVerwendbar $e) {
    return Ergebnis::gescheitert($e->getMessage());   // missing, ambiguous, or gone
}
```

`finden()` is where the index arithmetic lives, in **one** place and in the
order of the mail it just verified. Three products doing that conversion are
three rules that agree today. And it refuses rather than guesses when two
attachments of the same mail share a name — the bar keys its list by file name,
so neither side knows which one was ticked, and picking one is the same bet the
file name exists to avoid.

A product that stores the mail itself (`verbatim()` fetches body and files in
one go) wants the **position**, not the bytes — `$fund->stelle($name)` and
`$fund->stellen($names)` hand it over, with the same refusals. That position
counts every part of the message, embedded images included, which is why it
comes out of the list rather than out of counting the visible rows.

`finden()` takes a `MailAccount` **or** a `Mailbox`. The first is the short way
for a product on the package's account store; the second is for one that has
its own single place where a product row becomes a connection (the project
manager's `ImapService::postfach()`), because two ways to the same mailbox are
two places where transport and credentials get decided — and the test doubles
hang off the product's way, not the package's.

`mehrfach => true` turns a choice into a multiple choice — then a **list** of
values arrives, even for a single tick, and an empty list when nothing is
ticked. Without the flag a single value arrives. Reading both is exactly the
bug the type is there to prevent.

A field type the bar cannot draw is **not** an error and **not** a dead button:
the verb is not offered, and the bar *says* it cannot draw it. Silently leaving
it out looks like "does not exist", and then someone goes looking for the fault
in themselves.

### `suche` — the picker, and the third route

A contact out of twelve thousand cannot be a dropdown. Implement
`Werkzeuge\LiefertAuswahl` — a third interface, asked by `instanceof`, for the
same reason as `LiefertFelder`:

```php
public function auswahl(Vorgang $vorgang, Model $person, string $feld, string $suche): array
{
    return Aufgabe::query()
        ->where('titel', 'like', "%{$suche}%")
        ->limit(20)
        ->get()
        ->map(fn ($a) => ['wert' => (string) $a->id, 'wort' => $a->titel, 'hinweis' => $a->projekt?->name])
        ->all();
}
```

Mount the third route next to the other two:

```
POST mailbox/{account}/tools/options  → { options: [{value, label, hint?}] }
```

It runs under `mailbox.actions`, **not** a new capability: searching *reads*
what is possible. A third capability would be a third place for a product to
forget it, and the silent result would be a picker that never finds anything —
which looks like "that contact does not exist".

Four rules, and they are the ones that go wrong quietly:

- **Searching is reading, not a free pass.** `anwendbar()` is still not a
  permission check, and these results travel across system borders. Filter to
  what *this* person may see, or the toolbar becomes a directory for anyone
  with a mail open.
- **The empty query is the normal case.** The picker asks once on open, with no
  text, to have something to show. Answer with what makes sense without a
  question — the latest, the nearest, the person's own — or with nothing. Not
  with everything.
- **Limit it yourself.** The package does not truncate, because it does not
  know what a good order is. Twenty hits are a choice; two thousand are a
  connection that times out.
- **A verb that only leads there is not asked.** Same rule as for executing,
  and for the same reason: an older deployment elsewhere might still try.

### What the bar draws for it

Since v0.203.0 the toolbar renders `suche` itself: a search box, a result list,
and keyboard operation — arrow keys, Enter, Escape. The hits come from
`useMailWerkzeuge`'s `auswahl()`, which the surface passes in as
`auswahlQuelle`.

Two details that are not decoration:

- **No source, no button.** A `suche` field without `auswahlQuelle` makes the
  verb *not drawable* — the bar says so instead of offering a search box that
  never finds anything, which would send the person looking for the fault in
  their own spelling.
- **The highlight is readable twice**, in `aria-activedescendant` and in
  `data-highlighted`. A screen reader needs the first; a measurement needs
  something it can see without comparing colours.

Across a system border the search goes through the same gateway as the list
(`list-mailbox-actions-tool`, told apart by the `field` it carries — searching
reads, so a third capability would only be a third place to forget). A
deployment that does not know the field search yet answers with its **verb
list** — a success for the gateway and nonsense for the picker. So only what
really looks like options is unpacked (`Auspacken::auswahl`), and anything else
yields nothing. Until v0.204.0 the provider side of this was **missing**: the
MCP tool never read `field`, so every cross-system picker stayed empty while
the documentation said it worked. If one is empty on rollout, that is still the
first suspect — the target system is not on v0.204+ yet. Offering a verb list as a choice would be the worse
answer: twenty buttons that select nothing.

The transport contract for it is `SuchendeWerkzeugquelle`, which **extends**
`Werkzeugquelle` rather than growing it. Adding the method to the mandatory
contract invalidates every existing implementation at the next `composer
update` — the package's own test double broke within the minute, which in a
product would have been a deploy. A source without the search contract means no
hits from *foreign* verbs; the system's own verbs keep searching.

`rueckfrage` is asked in the same place — a box in the bar with the sentence
and two buttons. It used to be `window.confirm`, which stops the whole tab,
cannot be translated, and is the one thing this design is about: a question
that is **not** in the bar.

## Where the mail lies — and why that may travel

A `Vorgang` names the mail the way every system recognises it: mailbox
**address**, thread key, Message-ID. That rule is not negotiable; an account id
across a system border works by accident while the numbers match and then
delivers the wrong conversation, silently.

It leaves a verb that needs the mail *itself* with nothing to hold, though —
and three of the four verbs do: the receipt needs an attachment's bytes, the
task stores body and attachments, the ticket writes the body into its
description. A Message-ID is not resolvable over IMAP without a search. That,
and not the mask, is why those verbs only ever **led** somewhere.

So `Vorgang::$zusatz` carries what the **asking** system knows about the open
mail beyond its identity — `folder` and `uid`, on the wire as `extra`:

```
GET  …/tools?message_id=…&extra[folder]=INBOX&extra[uid]=4711
POST …/tools/run     { …, "extra": {"folder": "INBOX", "uid": "4711"} }
POST …/tools/options { …, "extra": {"folder": "INBOX", "uid": "4711"} }
```

Folder and handle are not an account id: they are properties of the **mailbox**,
and the same mailbox is the same mailbox on the same server for all four
systems. `MailboxFlaeche` fills them in on its own.

Three things make this safe rather than clever, and none is optional:

- **It is a claim, and it is checked.** `VorgangsNachricht` re-reads the mail
  and compares its Message-ID with the `Vorgang` before handing it over. A
  wrong `uid` — another protocol's handle, a changed `UIDVALIDITY` — is then
  merely ineffective instead of dangerous, and the fallback search takes over.
- **Only known keys arrive.** `folder`, `uid`, `from_name`, nothing else. What
  a browser puts next to them never reaches a verb, which might take it for
  checked.
- **The body does not travel.** `body_html` carries inline images as `data:`
  URIs and is routinely a megabyte; the list request is a GET. Whoever needs
  the body takes it from the mail that was fetched and verified — and has
  therefore checked it.

`zusatz` was in the contract from the first day and **nobody filled it**:
neither the HTTP side nor the MCP tools read `extra`. „Ticket erstellen" read
its description out of it and consequently created tickets without one from
19.10.2026 on, whenever it came from the toolbar. Found 1.11.2026 — the same
class of hole as „a field in the wire form with no interface behind it", only
the other way round. Both times the fix was the same: name a reader for every
key, or do not send it.

## A verb that only leads there

Some verbs need a chooser. "Link the contact" needs a contact — a searchable
picker — and a foreign system cannot draw the CRM's picker. A text field for a
contact id would be an imposition.

So such a verb answers `verweis()` and becomes a **link**, not a button:

```php
public function verweis(Vorgang $vorgang, Model $person): ?string
{
    return route('mailbox.index', ['thread' => $vorgang->anker()]);
}
```

It is a method and not a field on `werkzeug()` for a reason: the address hangs
off the *mail*. "Link the contact" does not lead somewhere in general, it leads
to **this** conversation — a link to the overview makes the person search, and
then the click was wasted. The first real link verb had the address in
`werkzeug()` and could only guess there.

A verb that *does* returns `null` here. Answering both is a contradiction; it
is then treated as a link and never executed.

**This is the exception that is being retired.** Since v0.195.0 a verb whose
input the bar *can* collect belongs in the bar — see the section above. What is
still missing is the searchable picker (a contact out of twelve thousand); it
arrives as the field type `suche` with `Werkzeuge\LiefertAuswahl`, and only
then is a link verb the rare case rather than the normal one. Until then both
ways stand side by side: a verb whose input nobody here can collect had better
lead somewhere than fail reliably.

A link verb is **never executed**. `Werkzeugverzeichnis::ausfuehren()` refuses
it — and the rule lives there rather than in the interface, because it has to
hold for a call from another system too: that system knows the shape, but an
older deployment of it might still try. `ausfuehren()` on such a verb is
usually not meaningfully implemented; calling it anyway means reaching for a
method that was never meant for this.

Do not confuse the two links: `verweis` on the verb leads to the chooser,
`zustand.verweis` leads to the thing that already exists.

## The rules that are easy to get wrong

**`anwendbar()` is not a permission check — `ausfuehren()` is.** The toolbar is
assembled across system borders, and a list that travelled through four systems
is a claim, not an entitlement. This project has opened other people's inboxes
twice by reading a visibility rule as a right; the same mistake here would
create rows in someone else's books.

**Identity travels as the address.** Never an account id. The same mailbox has a
different number in every system — it works by accident while the numbers match
and then delivers the wrong conversation, silently. See trap #1 in
INTEGRATION.md.

**A verb attaches to the conversation.** `Vorgang::anker()` is the one place
that decides "thread, else message". It stood in three places before, and that
is exactly how a state arises that neither side knows about.

**The asker stamps the origin, never the asked.** A system does not know what
key it is filed under elsewhere. If it could name itself, four interfaces would
show four different words, and the verb could not be delivered at all.

**Two layers of "ok".** The gateway's `ok` says the *delivery* worked. Whether
the *action* worked is in the tool's own answer. Reading only the outer shell
reports success for something that never happened — `Werkzeuge\Auspacken`
exists for that, and a gateway answer with no payload counts as a failure, not
as a quiet success.

**`zustand()` is what keeps a second click from creating a second ticket.** The
package cannot deduplicate — it does not know what a ticket is. The system that
keeps the link does, and reporting it turns the button into a reference.

**A failure costs its verbs, never the toolbar.** Each tool is asked and
guarded on its own; a silent short toolbar looks like "nothing to do here" and
then people look in the wrong system. What failed is in the answer
(`failed`, `unreachable`), and the interface says so.

## What it looks like on the wire

```
GET  mailbox/{account}/tools         → { tools: [...], unreachable: [...], failed: [...] }
POST mailbox/{account}/tools/run     → { ok, message, state, data }
POST mailbox/{account}/tools/options → { options: [{value, label, hint?}] }
```

All three also take `extra` — `folder`, `uid`, `from_name` of the open mail.
See *Where the mail lies* above for what that is for and why it is checked
rather than believed.

A tool carries `system: null` when it is this system's own, and the key it is
filed under otherwise. The interface sends back exactly what it got — the
switch between "own" and "foreign" sits in `GesamtWerkzeuge::ausfuehren()`, at
one place, because the interface does not care.

## Capabilities

| Capability | Tool | Who may |
|---|---|---|
| `mailbox.actions` | `list-mailbox-actions-tool` | read what is possible |
| `mailbox.actions.run` | `run-mailbox-action-tool` | trigger it |

Two, not one, because they are two different rights. A system may show its
verbs without letting them be triggered from outside; that is a stance, not a
misconfiguration.

Both answer **only this system's own** verbs — never the fetched ones. Four
systems asking each other for their merged toolbar is not a list, it is a loop.

## Not in here, on purpose

**Caching.** The inbox reuses foreign rows for a minute; here that would be
wrong. The answer hangs off the *mail*, not the person, so a cache would almost
never hit — and the state says whether the ticket already exists. A minute old
means "the button still invites you to create it", and then there are two
tickets.
