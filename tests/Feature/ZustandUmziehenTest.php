<?php

use Illuminate\Auth\Authenticatable as AuthenticatableTrait;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Peppermint\Mailbox\Contracts\Schlummerspeicher;
use Peppermint\Mailbox\Contracts\Zuweisungsspeicher;
use Peppermint\Mailbox\Database\SnoozesTable;
use Peppermint\Mailbox\Models\MailAccount;
use Peppermint\Mailbox\Models\MailAssignment;
use Peppermint\Mailbox\Models\MailSnooze;
use Peppermint\Mailbox\Zustand\Person;
use Peppermint\Mailbox\Zustand\Schlummer;
use Peppermint\Mailbox\Zustand\Schlummerliste;
use Peppermint\Mailbox\Zustand\Zuweisung;
use Peppermint\Mailbox\Zustand\Zuweisungsliste;

/**
 * Der Umzug des gewachsenen Zustands in die Mitte (19.10.2026).
 *
 * Der Befehl existiert, weil das Umschalten sonst still Zustand verliert: Die
 * eigenen Zeilen sind nach dem Umschalten nicht geloescht, sondern
 * unerreichbar — und das sieht aus wie „jede Mail ist frei".
 *
 * Geprueft wird deshalb vor allem das, was NICHT umziehen kann:
 *
 * - Eine Person ohne Adresse. Ihre Nummer gilt nur hier; in der Mitte zeigte
 *   sie auf jemand anderen.
 * - Ein Postfach, das die Mitte nicht kennt.
 * - Eine Mitte, die nicht annimmt.
 *
 * In allen drei Faellen muss der Befehl FEHLSCHLAGEN. Ein Teil-Erfolg mit
 * Rueckgabe 0 bekommt in einer Ausrollkette ein gruenes Haekchen, und die
 * fehlende Zustaendigkeit merkt erst der Mensch, dem seine Arbeit fehlt.
 */
uses(RefreshDatabase::class);

/**
 * Eine Nutzertabelle, wie ein Produkt sie hat.
 *
 * `Authenticatable`, und das ist seit dem 20.10.2026 keine Formalie: Der
 * Umzug meldet sich fuer jede Zeile ALS die zugewiesene Person an, weil die
 * Bruecke die handelnde Person aus `auth()->user()` liest. Ohne den Vertrag
 * koennte `setUser()` sie nicht annehmen — und der Test pruefte einen Weg,
 * den es in Produktion nicht gibt.
 */
class UmzugsNutzer extends Model implements Authenticatable
{
    use AuthenticatableTrait;

    protected $table = 'umzugs_nutzer';

    protected $guarded = [];

    public $timestamps = false;
}

/** Eine Mitte, die mitschreibt, was bei ihr ankommt. */
class MitteMitschrift implements Zuweisungsspeicher
{
    /** @var list<Zuweisung> */
    public array $geschrieben = [];

    public function __construct(public bool $nimmtAn = true) {}

    public function schreibbar(): bool
    {
        return true;
    }

    public function offene(int $konto): Zuweisungsliste
    {
        return Zuweisungsliste::mit($this->geschrieben);
    }

    public function fuer(int $konto, string $nachricht, ?string $kette): ?Zuweisung
    {
        return null;
    }

    public function zuweisen(int $konto, string $nachricht, ?string $kette, Person $an, ?Person $von): ?Zuweisung
    {
        if (! $this->nimmtAn) {
            return null;
        }

        $this->geschrieben[] = $zuweisung = new Zuweisung(
            konto: $konto,
            postfach: 'department@example.test',
            nachricht: $nachricht,
            kette: $kette,
            an: $an,
            von: $von,
        );

        return $zuweisung;
    }

    public function aufheben(int $konto, string $nachricht, ?string $kette): bool
    {
        return true;
    }
}

class SchlummerMitschrift implements Schlummerspeicher
{
    /** @var list<array{person: string, kette: string}> */
    public array $geschrieben = [];

    public function __construct(public bool $nimmtAn = true) {}

    public function ruhende(int $konto, Person $person): Schlummerliste
    {
        return Schlummerliste::mit([]);
    }

    public function weglegen(int $konto, Person $person, string $kette, string $nachricht, DateTimeInterface $bis): bool
    {
        if (! $this->nimmtAn) {
            return false;
        }

        $this->geschrieben[] = ['person' => $person->adresse, 'kette' => $kette];

        return true;
    }

    public function hervorholen(int $konto, Person $person, ?string $kette, string $nachricht): bool
    {
        return true;
    }
}

beforeEach(function () {
    config()->set('mailbox.store', 'brain');
    config()->set('mailbox.user_model', UmzugsNutzer::class);

    SnoozesTable::create();

    Schema::create('umzugs_nutzer', function ($t) {
        $t->id();
        $t->string('name');
        $t->string('email')->nullable();
    });

    $this->anna = UmzugsNutzer::create(['name' => 'Anna Meier', 'email' => 'anna@example.test']);
    $this->ohne = UmzugsNutzer::create(['name' => 'Ohne Adresse', 'email' => null]);

    $this->postfach = MailAccount::create([
        'label' => 'Abteilung',
        'email' => 'department@example.test',
        'imap_host' => 'mail.example.test',
        'imap_port' => 993,
        'imap_encryption' => 'ssl',
        'username' => 'department',
        'password' => 'geheim',
        'is_active' => true,
    ]);

    $this->mitte = new MitteMitschrift;
    $this->schlummerMitte = new SchlummerMitschrift;

    app()->instance(Zuweisungsspeicher::class, $this->mitte);
    app()->instance(Schlummerspeicher::class, $this->schlummerMitte);
});

it('schreibt im Probelauf nichts', function () {
    // Die Vorgabe. Ein Umzug, der beim ersten Aufruf schon schreibt, hat
    // keinen Moment, in dem man die Zahlen ansieht.
    MailAssignment::create([
        'email_account_id' => $this->postfach->id,
        'message_id' => '<a@example.test>',
        'thread_id' => 'kette-1',
        'assigned_to_user_id' => $this->anna->id,
        'status' => MailAssignment::STATUS_OPEN,
    ]);

    $this->artisan('mailbox:zustand-umziehen')
        ->expectsOutputToContain('Probelauf')
        ->assertSuccessful();

    expect($this->mitte->geschrieben)->toBe([]);
});

it('zieht eine offene Zuständigkeit mit ADRESSEN um', function () {
    MailAssignment::create([
        'email_account_id' => $this->postfach->id,
        'message_id' => '<a@example.test>',
        'thread_id' => 'kette-1',
        'assigned_to_user_id' => $this->anna->id,
        'status' => MailAssignment::STATUS_OPEN,
    ]);

    $this->artisan('mailbox:zustand-umziehen --ausfuehren')->assertSuccessful();

    expect($this->mitte->geschrieben)->toHaveCount(1)
        ->and($this->mitte->geschrieben[0]->an->adresse)->toBe('anna@example.test')
        ->and($this->mitte->geschrieben[0]->kette)->toBe('kette-1');
});

it('schlägt fehl, wenn eine Person keine Adresse hat — und zieht sie nicht um', function () {
    /*
     * Der wichtigste Fall. Ihre Nummer gilt nur hier; in der Mitte zeigte sie
     * auf jemand anderen. Sie zu uebergehen und trotzdem Erfolg zu melden
     * waere die Art Umzug, nach dem eine Zustaendigkeit einfach fehlt.
     */
    MailAssignment::create([
        'email_account_id' => $this->postfach->id,
        'message_id' => '<a@example.test>',
        'thread_id' => 'kette-1',
        'assigned_to_user_id' => $this->ohne->id,
        'status' => MailAssignment::STATUS_OPEN,
    ]);

    $this->artisan('mailbox:zustand-umziehen --ausfuehren')
        ->expectsOutputToContain('NICHT vollständig')
        ->assertFailed();

    expect($this->mitte->geschrieben)->toBe([]);
});

it('nennt im Probelauf, ob die Person zentral benennbar ist', function () {
    // Die Spalte, die sonst niemand aufschreibt — und die beim vergleichbaren
    // Fehler am 21.09.2026 der einzige Ort war, an dem er sichtbar wurde.
    MailAssignment::create([
        'email_account_id' => $this->postfach->id,
        'message_id' => '<a@example.test>',
        'thread_id' => 'kette-1',
        'assigned_to_user_id' => $this->ohne->id,
        'status' => MailAssignment::STATUS_OPEN,
    ]);

    $this->artisan('mailbox:zustand-umziehen')
        ->expectsOutputToContain('zentral bekannt')
        ->assertFailed();
});

it('lässt erledigte Zuständigkeiten liegen', function () {
    // Umgezogen wird lebender Zustand. Eine abgeschlossene Zeile in die Mitte
    // zu schreiben erzeugt dort Arbeit, die niemand hat.
    MailAssignment::create([
        'email_account_id' => $this->postfach->id,
        'message_id' => '<alt@example.test>',
        'thread_id' => 'kette-alt',
        'assigned_to_user_id' => $this->anna->id,
        'status' => MailAssignment::STATUS_DONE,
    ]);

    $this->artisan('mailbox:zustand-umziehen --ausfuehren')->assertSuccessful();

    expect($this->mitte->geschrieben)->toBe([]);
});

it('schlägt fehl, wenn die Mitte nicht annimmt', function () {
    // „Die meisten gingen durch" ist kein Erfolg: In einer Ausrollkette wäre
    // das ein grünes Häkchen für einen halben Umzug.
    $this->mitte->nimmtAn = false;

    MailAssignment::create([
        'email_account_id' => $this->postfach->id,
        'message_id' => '<a@example.test>',
        'thread_id' => 'kette-1',
        'assigned_to_user_id' => $this->anna->id,
        'status' => MailAssignment::STATUS_OPEN,
    ]);

    $this->artisan('mailbox:zustand-umziehen --ausfuehren')
        ->expectsOutputToContain('NICHT vollständig')
        ->assertFailed();
});

it('löscht die eigenen Zeilen nicht', function () {
    // Sie sind der Rückweg, wenn der Umzug unvollständig war. Ein Umzug, der
    // im selben Lauf löscht, hat keine Gegenprobe.
    MailAssignment::create([
        'email_account_id' => $this->postfach->id,
        'message_id' => '<a@example.test>',
        'thread_id' => 'kette-1',
        'assigned_to_user_id' => $this->anna->id,
        'status' => MailAssignment::STATUS_OPEN,
    ]);

    $this->artisan('mailbox:zustand-umziehen --ausfuehren')->assertSuccessful();

    expect(MailAssignment::count())->toBe(1);
});

it('zieht Geschlummertes nur für die Person um, der es gehört', function () {
    MailSnooze::create([
        'email_account_id' => $this->postfach->id,
        'user_id' => $this->anna->id,
        'thread_id' => 'kette-1',
        'message_id' => '<a@example.test>',
        'snooze_until' => now()->addDay(),
    ]);

    $this->artisan('mailbox:zustand-umziehen --ausfuehren')->assertSuccessful();

    expect($this->schlummerMitte->geschrieben)->toBe([
        ['person' => 'anna@example.test', 'kette' => 'kette-1'],
    ]);
});

it('lässt abgelaufenes Geschlummertes liegen', function () {
    // Es versteckt schon nichts mehr. Es umzuziehen hiesse, eine Mail in der
    // Mitte noch einmal wegzulegen.
    MailSnooze::create([
        'email_account_id' => $this->postfach->id,
        'user_id' => $this->anna->id,
        'thread_id' => 'kette-alt',
        'snooze_until' => now()->subDay(),
    ]);

    $this->artisan('mailbox:zustand-umziehen --ausfuehren')->assertSuccessful();

    expect($this->schlummerMitte->geschrieben)->toBe([]);
});

it('weigert sich, wenn das Produkt gar nicht auf die Mitte zeigt', function () {
    // Dann ist der eigene Bestand der richtige, und ein Umzug wäre eine
    // Kopie, die sofort auseinanderläuft.
    config()->set('mailbox.store', 'local');

    $this->artisan('mailbox:zustand-umziehen --ausfuehren')
        ->expectsOutputToContain('nicht auf')
        ->assertFailed();

    expect($this->mitte->geschrieben)->toBe([]);
});

it('kann zweimal laufen', function () {
    // Ein Umzug, den man nicht wiederholen darf, wird beim ersten Abbruch zum
    // Ratespiel.
    MailAssignment::create([
        'email_account_id' => $this->postfach->id,
        'message_id' => '<a@example.test>',
        'thread_id' => 'kette-1',
        'assigned_to_user_id' => $this->anna->id,
        'status' => MailAssignment::STATUS_OPEN,
    ]);

    $this->artisan('mailbox:zustand-umziehen --ausfuehren')->assertSuccessful();
    $this->artisan('mailbox:zustand-umziehen --ausfuehren')->assertSuccessful();

    // Zweimal dieselbe Aussage geschickt — die Mitte haelt je Kette eine.
    expect($this->mitte->geschrieben)->toHaveCount(2)
        ->and($this->mitte->geschrieben[0]->kette)->toBe($this->mitte->geschrieben[1]->kette);
});

it('handelt ALS die zugewiesene Person — sonst weist die Mitte ab', function () {
    /*
     * Der Fehler vom 20.10.2026, live beim Ausrollen.
     *
     * `peppermint/ai-brain-bridge` bestimmt die handelnde Person aus
     * `auth()->user()`. Ein Konsolenlauf hat keine, und die Mitte antwortete:
     * „Die handelnde Person darf in dieses Postfach nicht hineinsehen."
     *
     * ALLE 80 Zeilen scheiterten — nachdem der Probelauf sie als „bereit"
     * gemeldet hatte. Kein Test hatte es gesehen, weil die Attrappe hier
     * keine handelnde Person braucht. Also prueft dieser Test genau das:
     * WER war angemeldet, als geschrieben wurde.
     */
    $anna = UmzugsNutzer::create(['id' => 3, 'email' => 'anna@example.test', 'name' => 'Anna']);

    MailAssignment::create([
        'email_account_id' => 1,
        'message_id' => '<a@b.test>',
        'thread_id' => 'kette-1',
        'assigned_to_user_id' => $anna->id,
        'status' => MailAssignment::STATUS_OPEN,
    ]);

    $gesehen = [];
    $mitte = new class($gesehen) implements Zuweisungsspeicher
    {
        public function __construct(private array &$gesehen) {}

        public function zuweisen(int $konto, string $nachricht, ?string $kette, Person $an, ?Person $von): ?Zuweisung
        {
            // Die Frage dieses Tests: Wer ist in diesem Augenblick angemeldet?
            $this->gesehen[] = auth()->user()?->getAttribute('email');

            return new Zuweisung(konto: $konto, postfach: 'a@b.test', nachricht: $nachricht, kette: $kette, an: $an, von: $von);
        }

        public function offene(int $konto): Zuweisungsliste
        {
            return new Zuweisungsliste(zuweisungen: [], erreichbar: true);
        }

        public function fuer(int $konto, string $nachricht, ?string $kette): ?Zuweisung
        {
            return null;
        }

        public function aufheben(int $konto, string $nachricht, ?string $kette): bool
        {
            return true;
        }

        public function schreibbar(): bool
        {
            return true;
        }
    };

    app()->instance(Zuweisungsspeicher::class, $mitte);

    $this->artisan('mailbox:zustand-umziehen --ausfuehren --nur=zuweisungen')->assertSuccessful();

    expect($gesehen)->toBe(['anna@example.test']);
});

it('handelt als der ZUWEISENDE, wenn es einen gibt', function () {
    /*
     * Die zweite Haelfte des Fundes vom 20.10.2026, und sie kostete einen
     * zweiten Anlauf in Produktion.
     *
     * Die Mitte prueft, dass der genannte Zuweisende die handelnde Person
     * ist — „damit die Zustaendigkeit nicht auf den Falschen geraet". Der
     * erste Versuch handelte als die ZUGEWIESENE Person; 48 von 79 Zeilen
     * wurden abgewiesen, genau die mit zwei verschiedenen Menschen.
     *
     * Fuer einen Umzug ist der Zuweisende aus der alten Zeile eine Tatsache,
     * und wer sie eintraegt, muss er selbst sein.
     */
    $anna = UmzugsNutzer::create(['id' => 3, 'email' => 'anna@example.test', 'name' => 'Anna']);
    $bodo = UmzugsNutzer::create(['id' => 7, 'email' => 'bodo@example.test', 'name' => 'Bodo']);

    MailAssignment::create([
        'email_account_id' => 1,
        'message_id' => '<a@b.test>',
        'thread_id' => 'kette-1',
        'assigned_to_user_id' => $anna->id,
        'assigned_by_user_id' => $bodo->id,
        'status' => MailAssignment::STATUS_OPEN,
    ]);

    $gesehen = [];
    $mitte = new class($gesehen) implements Zuweisungsspeicher
    {
        public function __construct(private array &$gesehen) {}

        public function zuweisen(int $konto, string $nachricht, ?string $kette, Person $an, ?Person $von): ?Zuweisung
        {
            // Beides festhalten: WER handelt und WEN die Zeile als Zuweisenden
            // nennt. Die Mitte verlangt, dass es derselbe ist.
            $this->gesehen[] = [auth()->user()?->getAttribute('email'), $von?->adresse];

            return new Zuweisung(konto: $konto, postfach: 'a@b.test', nachricht: $nachricht, kette: $kette, an: $an, von: $von);
        }

        public function offene(int $konto): Zuweisungsliste
        {
            return new Zuweisungsliste(zuweisungen: [], erreichbar: true);
        }

        public function fuer(int $konto, string $nachricht, ?string $kette): ?Zuweisung
        {
            return null;
        }

        public function aufheben(int $konto, string $nachricht, ?string $kette): bool
        {
            return true;
        }

        public function schreibbar(): bool
        {
            return true;
        }
    };

    app()->instance(Zuweisungsspeicher::class, $mitte);

    $this->artisan('mailbox:zustand-umziehen --ausfuehren --nur=zuweisungen')->assertSuccessful();

    // Handelnde Person UND genannter Zuweisender muessen Bodo sein.
    expect($gesehen)->toBe([['bodo@example.test', 'bodo@example.test']]);
});

it('laesst nach dem Umzug niemanden angemeldet zurueck', function () {
    /*
     * Die Gegenprobe zur Anmeldung: Ein Befehl, der eine Person angemeldet
     * liegen laesst, vererbt sie an alles, was im selben Prozess danach
     * laeuft — in Octane waere das der naechste Request.
     */
    $anna = UmzugsNutzer::create(['id' => 3, 'email' => 'anna@example.test', 'name' => 'Anna']);

    MailAssignment::create([
        'email_account_id' => 1,
        'message_id' => '<a@b.test>',
        'thread_id' => 'kette-1',
        'assigned_to_user_id' => $anna->id,
        'status' => MailAssignment::STATUS_OPEN,
    ]);

    $this->artisan('mailbox:zustand-umziehen --ausfuehren --nur=zuweisungen');

    expect(auth()->user())->toBeNull();
});
