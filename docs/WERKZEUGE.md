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
| `Werkzeuge\Werkzeugverzeichnis` | what this system registered |
| `Werkzeuge\GesamtWerkzeuge` | own verbs plus the other systems', merged into one toolbar |
| `Werkzeuge\Vorgang` | **which mail** — by address, thread key and Message-ID |
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

## The two routes

```php
class MailboxController extends Controller
{
    use HandlesWerkzeuge;   // GET {account}/tools + POST {account}/tools/run

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
GET  mailbox/{account}/tools        → { tools: [...], unreachable: [...], failed: [...] }
POST mailbox/{account}/tools/run    → { ok, message, state, data }
```

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
