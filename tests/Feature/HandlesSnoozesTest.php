<?php

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Peppermint\Mailbox\Database\SnoozesTable;
use Peppermint\Mailbox\Http\HandlesSnoozes;
use Peppermint\Mailbox\Models\MailSnooze;

/**
 * Schlummern (22.09.2026).
 *
 * Zwei Entscheidungen koennen hier still falsch sein:
 *
 * - Schlummert es fuer ALLE oder nur fuer mich? Fuer alle hiesse: Der
 *   Kollegin verschwindet eine Nachricht, von der sie nichts weiss.
 * - Kommt die Nachricht von selbst zurueck? Es gibt keinen Lauf, der etwas
 *   umschreibt — und genau deshalb kann nichts ausfallen und sie fuer immer
 *   verschwinden lassen.
 */
uses(RefreshDatabase::class);

class TestControllerFuerSchlummern
{
    use HandlesSnoozes;

    public function __construct(private readonly ?int $ich) {}

    protected function currentUserId(): ?int
    {
        return $this->ich;
    }
}

function schlummerAnfrage(array $daten): Request
{
    $r = Request::create('/', 'POST', $daten);
    $r->headers->set('Accept', 'application/json');

    return $r;
}

beforeEach(fn () => SnoozesTable::create());

it('schlummert nur fuer die eigene Person', function () {
    // Der Kern. Wer in einem Gruppenpostfach fuer ALLE verschwinden liesse,
    // nimmt der Kollegin eine Nachricht weg, von der sie nichts weiss.
    (new TestControllerFuerSchlummern(3))->snooze(schlummerAnfrage([
        'message_id' => '<a@example.test>',
        'in_reply_to' => '<wurzel@example.test>',
        'until' => now()->addDay()->toIso8601String(),
    ]), 1);

    $meine = (new TestControllerFuerSchlummern(3))->snoozes(1)->getData(true)['snoozes'];
    $fremde = (new TestControllerFuerSchlummern(4))->snoozes(1)->getData(true)['snoozes'];

    expect($meine)->toHaveCount(1)
        ->and($fremde)->toHaveCount(0);
});

it('zeigt eine abgelaufene Schlummerzeit nicht mehr an', function () {
    // Es gibt keinen Lauf, der etwas zurueckholt: Der Vergleich mit der Uhr
    // reicht — und er kann nicht ausfallen.
    MailSnooze::create([
        'email_account_id' => 1,
        'user_id' => 3,
        'thread_id' => 'kette',
        'message_id' => '<a@example.test>',
        'snooze_until' => now()->subMinute(),
    ]);

    expect((new TestControllerFuerSchlummern(3))->snoozes(1)->getData(true)['snoozes'])->toHaveCount(0);
});

it('behandelt eine Nachricht ohne Bezugszeile als eigene Kette', function () {
    // Gelernt beim Schreiben dieses Tests: `ThreadKey::fromHeaders` faellt
    // ohne `In-Reply-To` und `References` auf die Kennung der Nachricht selbst
    // zurueck — sie ist die Wurzel ihrer eigenen Kette. Das ist richtig: Eine
    // Nachricht, die noch keine Antwort hat, IST die Unterhaltung.
    //
    // Der 422-Zweig greift deshalb nur bei einer Kennung, aus der sich gar
    // nichts machen laesst. Er bleibt stehen, weil genau das vorkommt — nur
    // ist er seltener, als der erste Entwurf annahm.
    $antwort = (new TestControllerFuerSchlummern(3))->snooze(schlummerAnfrage([
        'message_id' => '<allein@example.test>',
        'until' => now()->addDay()->toIso8601String(),
    ]), 1);

    expect($antwort->getStatusCode())->toBe(200)
        ->and(MailSnooze::first()->thread_id)->toBe('allein@example.test');
});

it('nimmt keine Zeit in der Vergangenheit an', function () {
    // Sonst ist die Nachricht sofort wieder da, und der Klick sah aus, als
    // haette er nichts getan.
    expect(fn () => (new TestControllerFuerSchlummern(3))->snooze(schlummerAnfrage([
        'message_id' => '<a@example.test>',
        'in_reply_to' => '<wurzel@example.test>',
        'until' => now()->subHour()->toIso8601String(),
    ]), 1))->toThrow(Illuminate\Validation\ValidationException::class);
});

it('verschiebt eine bestehende Schlummerzeit, statt sie zu verdoppeln', function () {
    $controller = new TestControllerFuerSchlummern(3);
    $daten = ['message_id' => '<a@example.test>', 'in_reply_to' => '<wurzel@example.test>'];

    $controller->snooze(schlummerAnfrage($daten + ['until' => now()->addDay()->toIso8601String()]), 1);
    $controller->snooze(schlummerAnfrage($daten + ['until' => now()->addDays(3)->toIso8601String()]), 1);

    expect(MailSnooze::count())->toBe(1);
});

it('holt sie auf Wunsch sofort zurueck', function () {
    $controller = new TestControllerFuerSchlummern(3);
    $daten = ['message_id' => '<a@example.test>', 'in_reply_to' => '<wurzel@example.test>'];

    $controller->snooze(schlummerAnfrage($daten + ['until' => now()->addDay()->toIso8601String()]), 1);
    $controller->unsnooze(schlummerAnfrage($daten), 1);

    expect(MailSnooze::count())->toBe(0);
});

it('laesst das Produkt einen Riegel vor alle Wege setzen', function () {
    $streng = new class(3) extends TestControllerFuerSchlummern
    {
        protected function guardSnoozes(int $account): void
        {
            abort(403);
        }
    };

    foreach ([
        fn () => $streng->snoozes(1),
        fn () => $streng->snooze(schlummerAnfrage(['message_id' => '<a@b.test>', 'until' => now()->addDay()->toIso8601String()]), 1),
        fn () => $streng->unsnooze(schlummerAnfrage(['message_id' => '<a@b.test>']), 1),
    ] as $i => $weg) {
        expect($weg)->toThrow(Symfony\Component\HttpKernel\Exception\HttpException::class, '', "Weg {$i} ungeschuetzt");
    }
});
