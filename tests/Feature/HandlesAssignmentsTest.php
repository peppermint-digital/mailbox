<?php

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Peppermint\Mailbox\Http\HandlesAssignments;
use Peppermint\Mailbox\Models\MailAssignment;

/**
 * Wer sich um welche Unterhaltung kuemmert (22.09.2026).
 *
 * Geprueft wird das, was still falsch sein kann:
 *
 * - Wird die KETTE zugewiesen oder nur die eine Nachricht? Bei der Nachricht
 *   waere die naechste Antwort in derselben Sache wieder niemandem zugeordnet.
 * - Haelt die Pruefung, wer zugewiesen werden darf? Ohne sie landet Arbeit bei
 *   jemandem, der das Postfach gar nicht sehen kann.
 * - Verschwindet beim Aufheben AUCH der Altbestand ohne Kettenkennung?
 */
class TestControllerFuerZuweisungen
{
    use HandlesAssignments;

    /** @param  list<array{id: int, name: string}>  $personen */
    public function __construct(private readonly array $personen, private readonly ?int $ich = 7) {}

    protected function assignableUsers(int $account): array
    {
        return $this->personen;
    }

    protected function currentUserId(): ?int
    {
        return $this->ich;
    }
}

uses(RefreshDatabase::class);

function zuweisungsAnfrage(array $daten): Request
{
    $r = Request::create('/', 'POST', $daten);
    $r->headers->set('Accept', 'application/json');

    return $r;
}

$personen = [['id' => 3, 'name' => 'Anna Meier'], ['id' => 4, 'name' => 'Bernd Schulz']];

it('weist die KETTE zu, nicht nur die Nachricht', function () use ($personen) {
    // Sonst ist die naechste Antwort in derselben Sache wieder niemandem
    // zugeordnet, und dieselbe Unterhaltung wird zweimal sortiert.
    (new TestControllerFuerZuweisungen($personen))->assign(zuweisungsAnfrage([
        'message_id' => '<zweite@example.test>',
        'assigned_to_user_id' => 3,
        'in_reply_to' => '<erste@example.test>',
    ]), 1);

    $zeile = MailAssignment::first();

    expect($zeile->thread_id)->not->toBeNull()
        ->and($zeile->message_id)->toBe('<zweite@example.test>')
        ->and($zeile->assigned_to_user_id)->toBe(3);
});

it('laesst niemanden zuweisen, der nicht ins Postfach darf', function () use ($personen) {
    // Die Liste der zulaessigen Personen IST die Pruefung. Eine zweite daneben
    // liefe irgendwann auseinander.
    $antwort = (new TestControllerFuerZuweisungen($personen))->assign(zuweisungsAnfrage([
        'message_id' => '<a@example.test>',
        'assigned_to_user_id' => 99,
    ]), 1);

    expect($antwort->getStatusCode())->toBe(422)
        ->and(MailAssignment::count())->toBe(0);
});

it('haelt je Kette genau eine Zuweisung', function () use ($personen) {
    // Zweimal zuweisen heisst umhaengen, nicht verdoppeln — sonst zeigt die
    // Liste eine von zwei Zeilen, und welche, entscheidet der Zufall.
    $controller = new TestControllerFuerZuweisungen($personen);
    $daten = ['message_id' => '<a@example.test>', 'in_reply_to' => '<wurzel@example.test>'];

    $controller->assign(zuweisungsAnfrage($daten + ['assigned_to_user_id' => 3]), 1);
    $controller->assign(zuweisungsAnfrage($daten + ['assigned_to_user_id' => 4]), 1);

    expect(MailAssignment::count())->toBe(1)
        ->and(MailAssignment::first()->assigned_to_user_id)->toBe(4);
});

it('haelt die Postfaecher auseinander', function () use ($personen) {
    // Dieselbe Kette kann in zwei Postfaechern liegen; die Zustaendigkeit ist
    // deshalb nicht dieselbe.
    $controller = new TestControllerFuerZuweisungen($personen);
    $daten = ['message_id' => '<a@example.test>', 'in_reply_to' => '<wurzel@example.test>', 'assigned_to_user_id' => 3];

    $controller->assign(zuweisungsAnfrage($daten), 1);
    $controller->assign(zuweisungsAnfrage($daten), 2);

    expect(MailAssignment::count())->toBe(2);
});

it('hebt auch Zuweisungen ohne Kettenkennung auf', function () use ($personen) {
    // Altbestand: Zuweisungen, die entstanden sind, bevor es Kettenkennungen
    // gab. Wer nur nach der Kette sucht, laesst sie stehen — und die Zeile
    // traegt danach ein Kennzeichen, das sich nicht mehr entfernen laesst.
    MailAssignment::create([
        'email_account_id' => 1,
        'message_id' => '<alt@example.test>',
        'thread_id' => null,
        'assigned_to_user_id' => 3,
        'status' => MailAssignment::STATUS_OPEN,
    ]);

    (new TestControllerFuerZuweisungen($personen))->unassign(zuweisungsAnfrage([
        'message_id' => '<alt@example.test>',
    ]), 1);

    expect(MailAssignment::count())->toBe(0);
});

it('gibt die Zuweisungen mit Namen und Initialen heraus', function () use ($personen) {
    (new TestControllerFuerZuweisungen($personen))->assign(zuweisungsAnfrage([
        'message_id' => '<a@example.test>',
        'assigned_to_user_id' => 3,
    ]), 1);

    $daten = (new TestControllerFuerZuweisungen($personen))->assignments(1)->getData(true);

    expect($daten['assignments'][0])->toMatchArray([
        'user_id' => 3,
        'name' => 'Anna Meier',
        'initials' => 'AM',
    ]);
});

it('macht aus einem einzelnen Namen brauchbare Initialen', function () {
    $controller = new TestControllerFuerZuweisungen([['id' => 1, 'name' => 'Cher'], ['id' => 2, 'name' => 'Anna von Meier']]);

    $nutzer = collect($controller->assignmentUsers(1)->getData(true)['users'])->keyBy('id');

    expect($nutzer[1]['initials'])->toBe('C')
        ->and($nutzer[2]['initials'])->toBe('AM');
});

it('laesst das Produkt einen Riegel vor ALLE Wege setzen', function () {
    // Der Anlass: Die Verwaltung verlangt `canEdit()` fuers Lesen des
    // Postfachs, und ihre Pruefung sitzt in `mailboxFor()`. Die
    // Zuweisungs-Wege rufen `mailboxFor()` gar nicht auf — sie brauchen kein
    // Postfach, nur die Datenbank. Ohne diesen Haken waeren sie an der
    // Schranke vorbei erreichbar, und zwar lautlos.
    $streng = new class(([['id' => 3, 'name' => 'Anna Meier']])) extends TestControllerFuerZuweisungen
    {
        protected function guardAssignments(int $account): void
        {
            abort(403);
        }
    };

    foreach ([
        fn () => $streng->assignmentUsers(1),
        fn () => $streng->assignments(1),
        fn () => $streng->assign(zuweisungsAnfrage(['message_id' => '<a@b.test>', 'assigned_to_user_id' => 3]), 1),
        fn () => $streng->unassign(zuweisungsAnfrage(['message_id' => '<a@b.test>']), 1),
    ] as $i => $weg) {
        expect($weg)->toThrow(Symfony\Component\HttpKernel\Exception\HttpException::class, '', "Weg {$i} ungeschuetzt");
    }

    expect(MailAssignment::count())->toBe(0);
});

it('sagt beim Zuweisen, WER es vorher war', function () use ($personen) {
    // Der Projekt-Manager benachrichtigt nur bei einem Wechsel. Ohne diese
    // Angabe muesste er bei jedem Klick benachrichtigen — und waere nach
    // einer Woche stummgeschaltet.
    //
    // Dass es diesen Haken gibt, hat einen konkreten Anlass: Ohne ihn haette
    // die Umstellung des Managers auf dieses Merkmal seine Benachrichtigungen
    // lautlos abgeschaltet.
    $gesehen = [];

    $controller = new class($personen, $gesehen) extends TestControllerFuerZuweisungen
    {
        public function __construct(array $personen, public array &$gesehen)
        {
            parent::__construct($personen);
        }

        protected function afterAssign(Peppermint\Mailbox\Models\MailAssignment $zuweisung, ?int $vorher, array $daten): void
        {
            $this->gesehen[] = [$vorher, $zuweisung->assigned_to_user_id];
        }
    };

    $anfrage = ['message_id' => '<a@example.test>', 'in_reply_to' => '<wurzel@example.test>'];

    $controller->assign(zuweisungsAnfrage($anfrage + ['assigned_to_user_id' => 3]), 1);
    $controller->assign(zuweisungsAnfrage($anfrage + ['assigned_to_user_id' => 4]), 1);
    $controller->assign(zuweisungsAnfrage($anfrage + ['assigned_to_user_id' => 4]), 1);

    expect($gesehen)->toBe([[null, 3], [3, 4], [4, 4]]);
});

it('gibt die Zuweisung zurueck, damit die Zeile sich ohne Neuladen aendert', function () use ($personen) {
    $antwort = (new TestControllerFuerZuweisungen($personen))->assign(zuweisungsAnfrage([
        'message_id' => '<a@example.test>',
        'assigned_to_user_id' => 3,
    ]), 1)->getData(true);

    expect($antwort['assignment'])->toMatchArray(['user_id' => 3, 'name' => 'Anna Meier', 'initials' => 'AM']);
});

it('laesst das Produkt die Kette selbst bestimmen', function () use ($personen) {
    // Der Projekt-Manager fuehrt ein Kopfzeilen-Verzeichnis und findet die
    // Wurzel auch ohne `In-Reply-To`. Das Paket faellt dann auf die Nachricht
    // selbst zurueck — beides richtig, nur mit verschieden viel Wissen.
    //
    // Ohne diesen Haken haette die Umstellung des Managers seine Zuweisungen
    // an die falsche Kette gehaengt: kein Fehler, keine Meldung, nur eine
    // Unterhaltung, die sich in zwei teilt.
    $controller = new class($personen) extends TestControllerFuerZuweisungen
    {
        protected function threadKeyFor(int $account, array $daten): ?string
        {
            return 'wurzel-aus-dem-verzeichnis';
        }
    };

    $controller->assign(zuweisungsAnfrage([
        'message_id' => '<zweite@example.test>',
        'assigned_to_user_id' => 3,
    ]), 1);

    expect(MailAssignment::first()->thread_id)->toBe('wurzel-aus-dem-verzeichnis');
});
