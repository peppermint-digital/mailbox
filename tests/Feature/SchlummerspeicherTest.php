<?php

use DateTimeImmutable;
use DateTimeInterface;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Peppermint\Mailbox\Contracts\Schlummerspeicher;
use Peppermint\Mailbox\Http\HandlesAssignments;
use Peppermint\Mailbox\Http\HandlesSnoozes;
use Peppermint\Mailbox\Stores\BrainSchlummerspeicher;
use Peppermint\Mailbox\Zustand\Person;
use Peppermint\Mailbox\Zustand\Schlummer;

/**
 * Geschlummertes in der Mitte (19.10.2026).
 *
 * Der teuerste Fehler ist hier ein anderer als bei der Zustaendigkeit: Dort
 * laesst ein stiller Ausfall eine Mail frei aussehen, hier laesst er sie
 * VERSCHWINDEN — bei der falschen Person, und die merkt es nie, weil ihr eine
 * Mail fehlt, von der sie nichts weiss.
 *
 * Deshalb wird geprueft: Reist die Person als Adresse? Reist die Zeit mit
 * Zone? Und laesst ein Ausfall eher auftauchen als verschwinden?
 */
uses(RefreshDatabase::class);

class TestControllerFuerSchlummerMitte
{
    use HandlesSnoozes;

    public function __construct(private readonly ?Person $person) {}

    protected function currentUserId(): ?int
    {
        return $this->person?->nummer;
    }

    protected function schlummerPerson(): ?Person
    {
        return $this->person;
    }
}

/** Ein Controller, der BEIDE Merkmale fuehrt — der Normalfall in den Produkten. */
class TestControllerMitBeidem
{
    use HandlesAssignments;
    use HandlesSnoozes;

    protected function assignableUsers(int $account): array
    {
        return [['id' => 3, 'name' => 'Anna Meier', 'email' => 'anna@example.test']];
    }

    protected function currentUserId(): ?int
    {
        return 3;
    }

    protected function threadKeyFor(int $account, array $daten): ?string
    {
        // Das Kopfzeilen-Verzeichnis eines Produkts — findet die Wurzel auch
        // ohne `In-Reply-To`.
        return 'wurzel-aus-dem-verzeichnis';
    }

    protected function schlummerPerson(): ?Person
    {
        return new Person(adresse: 'anna@example.test', nummer: 3, name: 'Anna Meier');
    }
}

class SchlummerSpion
{
    /** @var list<array{capability: string, args: array<string, mixed>}> */
    public array $gefragt = [];

    public BrainSchlummerspeicher $speicher;

    public function __construct(array|false $antwort, ?string $adresse = 'department@peppermint-digital.de')
    {
        $this->speicher = new BrainSchlummerspeicher(
            function (string $faehigkeit, array $argumente) use ($antwort): ?array {
                $this->gefragt[] = ['capability' => $faehigkeit, 'args' => $argumente];

                if ($antwort === false) {
                    throw new RuntimeException('Zeitueberschreitung.');
                }

                return $antwort;
            },
            fn (int $konto): ?string => $adresse,
        );
    }
}

function schlummerAnna(): Person
{
    return new Person(adresse: 'anna@example.test', nummer: 3, name: 'Anna Meier');
}

function schlummerMitteAnfrage(array $daten): Request
{
    $r = Request::create('/', 'POST', $daten);
    $r->headers->set('Accept', 'application/json');

    return $r;
}

it('schickt die Person als Adresse in die Mitte, nicht als Nummer', function () {
    // Eine verwechselte Nummer versteckt die Nachricht beim FALSCHEN Menschen.
    // Dem faellt es nie auf — ihm fehlt eine Mail, von der er nichts weiss.
    $spion = new SchlummerSpion(['ok' => true, 'data' => []]);

    $spion->speicher->weglegen(42, schlummerAnna(), 'kette-1', '<a@b.test>', new DateTimeImmutable('2026-10-20 09:00:00'));

    expect($spion->gefragt[0]['args']['person'])->toBe('anna@example.test')
        ->and($spion->gefragt[0]['args'])->not->toHaveKey('user_id')
        ->and(array_filter($spion->gefragt[0]['args'], fn ($w): bool => is_int($w)))->toBe([]);
});

it('schickt die Zeit MIT Zeitzone', function () {
    // „Bis morgen 9 Uhr" ohne Zone ist die Ortszeit des schreibenden Systems.
    // Die Nachricht kaeme anderswo zwei Stunden zu frueh oder zu spaet zurueck
    // — und weil niemand die Uhr vergleicht, sucht man im Schlummern.
    $spion = new SchlummerSpion(['ok' => true, 'data' => []]);

    $spion->speicher->weglegen(42, schlummerAnna(), 'kette-1', '<a@b.test>', new DateTimeImmutable('2026-10-20 09:00:00+02:00'));

    expect($spion->gefragt[0]['args']['until'])->toBe('2026-10-20T09:00:00+02:00');
});

it('legt ohne Adresse nichts in der Mitte weg', function () {
    // Ohne Adresse weiss die Mitte nicht, WESSEN Schlummer das ist — und ein
    // Schlummer, der niemandem gehoert, versteckt bei allen oder bei keinem.
    $spion = new SchlummerSpion(['ok' => true, 'data' => []]);

    $gelungen = $spion->speicher->weglegen(
        42,
        new Person(adresse: '', nummer: 3),
        'kette-1',
        '<a@b.test>',
        new DateTimeImmutable('+1 day'),
    );

    expect($gelungen)->toBeFalse()
        ->and($spion->gefragt)->toBe([]);
});

it('laesst einen Ausfall auftauchen und nicht verschwinden', function () {
    // Die Richtung ist Absicht: Wer bei einem Ausfall ausblendet, versteckt
    // eine Mail, die jemand sehen muss.
    $liste = (new SchlummerSpion(false))->speicher->ruhende(42, schlummerAnna());

    expect($liste->schlummer)->toBe([])
        ->and($liste->erreichbar)->toBeFalse();
});

it('zeigt eine abgelaufene Zeile aus der Mitte nicht an', function () {
    // Abgelaufene Zeilen aufzuheben ist Sache der Mitte, sie zu zeigen waere
    // unsere.
    $liste = (new SchlummerSpion(['ok' => true, 'data' => ['snoozes' => [
        ['thread_id' => 'vorbei', 'until' => '2020-01-01T00:00:00+00:00'],
        ['thread_id' => 'ruht', 'until' => '2099-01-01T00:00:00+00:00'],
    ]]]))->speicher->ruhende(42, schlummerAnna());

    expect($liste->ketten())->toBe(['ruht']);
});

it('verwirft eine Zeile ohne Kette oder mit unlesbarer Zeit', function () {
    // Eine unlesbare Zeit als „jetzt" zu deuten brachte die Nachricht sofort
    // zurueck, als „nie" versteckte sie sie fuer immer. Beides waere geraten.
    $liste = (new SchlummerSpion(['ok' => true, 'data' => ['snoozes' => [
        ['until' => '2099-01-01T00:00:00+00:00'],
        ['thread_id' => 'kaputt', 'until' => 'übermorgen vielleicht'],
        ['thread_id' => 'gut', 'until' => '2099-01-01T00:00:00+00:00'],
    ]]]))->speicher->ruhende(42, schlummerAnna());

    expect($liste->ketten())->toBe(['gut']);
});

it('meldet ein Weglegen, das nicht stattfand, als Fehlschlag', function () {
    app()->instance(Schlummerspeicher::class, (new SchlummerSpion(false))->speicher);

    $antwort = (new TestControllerFuerSchlummerMitte(schlummerAnna()))->snooze(schlummerMitteAnfrage([
        'message_id' => '<a@b.test>',
        'until' => now()->addDay()->toIso8601String(),
    ]), 1);

    expect($antwort->getStatusCode())->toBe(503);
});

it('meldet ein Hervorholen, das nicht stattfand, als Fehlschlag', function () {
    // Der Fall, der wirklich weh tut: Wer etwas hervorholt und es bleibt weg,
    // sucht eine Nachricht, die er sich selbst versteckt hat.
    app()->instance(Schlummerspeicher::class, (new SchlummerSpion(false))->speicher);

    $antwort = (new TestControllerFuerSchlummerMitte(schlummerAnna()))->unsnooze(schlummerMitteAnfrage([
        'message_id' => '<a@b.test>',
    ]), 1);

    expect($antwort->getStatusCode())->toBe(503);
});

it('sagt der Oberflaeche, wenn das Geschlummerte nicht zu holen war', function () {
    app()->instance(Schlummerspeicher::class, (new SchlummerSpion(false))->speicher);

    $antwort = (new TestControllerFuerSchlummerMitte(schlummerAnna()))->snoozes(1)->getData(true);

    expect($antwort['snoozes'])->toBe([])
        ->and($antwort['reachable'])->toBeFalse();
});

it('haengt Schlummer und Zuweisung an DIESELBE Kette', function () {
    /*
     * Der Fund vom 19.10.2026: Das Schlummern rechnete die Kette selbst aus
     * (`ThreadKey::fromHeaders`) und ging damit am Kopfzeilen-Verzeichnis des
     * Managers vorbei, das die Zuweisung benutzt. Dieselbe Unterhaltung hatte
     * je Funktion einen anderen Faden — die Zustaendigkeit blieb, die
     * weggelegte Mail kam mit der naechsten Antwort wieder.
     *
     * Jetzt fragen beide dieselbe Funktion. Geprueft wird genau das: Ein
     * Produkt, das `threadKeyFor()` ueberschreibt, wirkt auf BEIDE Wege.
     */
    $gesehen = [];

    app()->instance(Schlummerspeicher::class, new class($gesehen) implements Schlummerspeicher
    {
        public function __construct(public array &$gesehen) {}

        public function ruhende(int $konto, Person $person): Peppermint\Mailbox\Zustand\Schlummerliste
        {
            return Peppermint\Mailbox\Zustand\Schlummerliste::mit([]);
        }

        public function weglegen(int $konto, Person $person, string $kette, string $nachricht, DateTimeInterface $bis): bool
        {
            $this->gesehen[] = $kette;

            return true;
        }

        public function hervorholen(int $konto, Person $person, ?string $kette, string $nachricht): bool
        {
            return true;
        }
    });

    $controller = new TestControllerMitBeidem;

    $controller->snooze(schlummerMitteAnfrage([
        'message_id' => '<zweite@example.test>',
        'until' => now()->addDay()->toIso8601String(),
    ]), 1);

    expect($gesehen)->toBe(['wurzel-aus-dem-verzeichnis']);
});

it('schickt im Schlummer keine Nummer, auch nicht die des Postfachs', function () {
    $draht = (new Schlummer(
        postfach: 'department@peppermint-digital.de',
        person: schlummerAnna(),
        kette: 'kette-1',
        nachricht: '<a@b.test>',
        bis: new DateTimeImmutable('2099-01-01T00:00:00+00:00'),
    ))->toArray();

    expect(array_filter($draht, fn ($w): bool => is_int($w)))->toBe([])
        ->and($draht['mailbox'])->toBe('department@peppermint-digital.de')
        ->and($draht['person'])->toBe('anna@example.test');
});

it('speichert lokal den AUGENBLICK, nicht die Uhrzeit ohne Zone', function () {
    /*
     * Der Fehler, den die Naht-Probe am 19.10.2026 gefunden hat: Eloquent
     * formatiert einen uebergebenen Zeitpunkt in SEINER Zone, ohne
     * umzurechnen. Aus „09:00+02:00" wurde die Zeichenkette „09:00", gelesen
     * als 09:00 UTC — die Nachricht kam zwei Stunden zu spaet zurueck.
     *
     * Geprueft wird deshalb der Augenblick und nicht die Schreibweise. Die
     * Schreibweise DARF sich aendern: Eine Zeitstempel-Spalte kennt keine
     * Zone, und 07:00Z IST 09:00+02:00.
     */
    Peppermint\Mailbox\Database\SnoozesTable::create();

    $speicher = new Peppermint\Mailbox\Stores\LokalerSchlummerspeicher;
    $gewollt = new DateTimeImmutable('2099-01-01T09:00:00+02:00');

    $speicher->weglegen(1, new Person(adresse: 'a@b.test', nummer: 3), 'kette-1', '<a@b.test>', $gewollt);

    $liste = $speicher->ruhende(1, new Person(adresse: 'a@b.test', nummer: 3));

    expect($liste->schlummer)->toHaveCount(1)
        ->and($liste->schlummer[0]->bis->getTimestamp())->toBe($gewollt->getTimestamp());
});

it('haelt je Kette und Person EINE Zeile, auch bei einem zweiten Lauf', function () {
    /*
     * Dieselbe Zusage wie bei den Zuweisungen, und aus demselben Grund: Der
     * Umzug (`mailbox:zustand-umziehen`) ist ausdruecklich wiederholbar, weil
     * ein Umzug nach einem Abbruch sonst zum Ratespiel wird. Dass die Mitte
     * dabei keine zweite Zeile anlegt, war bis zum 20.10.2026 nur im
     * Quelltext zu sehen (`updateOrCreate`), nicht geprueft.
     *
     * Gefunden bei der Selbstdurchsicht vor dem Ausrollen.
     */
    Peppermint\Mailbox\Database\SnoozesTable::create();

    $speicher = new Peppermint\Mailbox\Stores\LokalerSchlummerspeicher;
    $person = new Person(adresse: 'a@b.test', nummer: 3);
    $bis = new DateTimeImmutable('2099-01-01T09:00:00+02:00');

    $speicher->weglegen(1, $person, 'kette-1', '<a@b.test>', $bis);
    $speicher->weglegen(1, $person, 'kette-1', '<a@b.test>', $bis);

    expect($speicher->ruhende(1, $person)->schlummer)->toHaveCount(1);
});

it('legt fuer eine ZWEITE Person eine eigene Zeile an', function () {
    /*
     * Die Gegenprobe, und sie ist hier besonders wichtig: Schlummern ist PRO
     * PERSON. Wer in einem Gruppenpostfach etwas fuer ALLE wegraeumte, nimmt
     * der Kollegin eine Nachricht weg, von der sie nichts weiss.
     *
     * Ein Speicher, der nur auf die Kette abbildet, besteht den Test darueber
     * und bricht genau diese Regel.
     */
    Peppermint\Mailbox\Database\SnoozesTable::create();

    $speicher = new Peppermint\Mailbox\Stores\LokalerSchlummerspeicher;
    $anna = new Person(adresse: 'a@b.test', nummer: 3);
    $bodo = new Person(adresse: 'b@b.test', nummer: 7);
    $bis = new DateTimeImmutable('2099-01-01T09:00:00+02:00');

    $speicher->weglegen(1, $anna, 'kette-1', '<a@b.test>', $bis);
    $speicher->weglegen(1, $bodo, 'kette-1', '<a@b.test>', $bis);

    expect($speicher->ruhende(1, $anna)->schlummer)->toHaveCount(1)
        ->and($speicher->ruhende(1, $bodo)->schlummer)->toHaveCount(1);
});
