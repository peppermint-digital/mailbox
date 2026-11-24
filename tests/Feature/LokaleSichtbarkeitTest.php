<?php

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Peppermint\Mailbox\Accounts\SichtbareKonten;
use Peppermint\Mailbox\Models\MailAccount;
use Peppermint\Mailbox\Stores\LocalAccountStore;

/**
 * Wer welches Postfach sieht, wenn die Anwendung ihre Konten SELBST fuehrt.
 *
 * `SichtbareKonten` fragt nach der Adresse. Der Brain-Speicher liefert dafuer
 * `owner_email` und `reader_emails` mit; die eigene Tabelle kennt nur
 * `user_id` und die Zuteilungstabelle. Ohne Uebersetzung galt jedes
 * persoenliche Postfach als „Besitzer unbekannt" — und damit als sichtbar fuer
 * alle. Ein privater Posteingang in der Liste jedes Kollegen.
 */
uses(RefreshDatabase::class);

beforeEach(function () {
    Schema::create('users', function (Blueprint $table): void {
        $table->id();
        $table->string('email');
    });

    config()->set('mailbox.user_model', LokaleSichtbarkeitPerson::class);

    DB::table('users')->insert([
        ['id' => 3, 'email' => 'Anna@Beispiel.de'],
        ['id' => 4, 'email' => 'bert@beispiel.de'],
        ['id' => 5, 'email' => 'carla@beispiel.de'],
    ]);
});

class LokaleSichtbarkeitPerson extends Model
{
    protected $table = 'users';
}

it('zeigt ein persoenliches Postfach nur seinem Besitzer', function () {
    MailAccount::create(['email' => 'anna@beispiel.de', 'user_id' => 3]);

    $sichtbar = new SichtbareKonten(new LocalAccountStore);

    expect($sichtbar->fuer('anna@beispiel.de'))->toHaveCount(1)
        ->and($sichtbar->fuer('bert@beispiel.de'))->toHaveCount(0)
        ->and($sichtbar->fuer(null))->toHaveCount(0);
});

it('zeigt ein Gruppenpostfach mit Zuteilung nur den Zugeteilten', function () {
    $team = MailAccount::create(['email' => 'team@beispiel.de']);
    DB::table('mail_account_user')->insert([
        ['mail_account_id' => $team->id, 'user_id' => 4],
        ['mail_account_id' => $team->id, 'user_id' => 5],
    ]);

    $sichtbar = new SichtbareKonten(new LocalAccountStore);

    expect($sichtbar->fuer('bert@beispiel.de'))->toHaveCount(1)
        ->and($sichtbar->fuer('CARLA@beispiel.de'))->toHaveCount(1)
        ->and($sichtbar->fuer('anna@beispiel.de'))->toHaveCount(0);
});

it('zeigt ein Gruppenpostfach ohne Zuteilung jedem', function () {
    MailAccount::create(['email' => 'info@beispiel.de']);

    $sichtbar = new SichtbareKonten(new LocalAccountStore);

    expect($sichtbar->fuer('anna@beispiel.de'))->toHaveCount(1)
        ->and($sichtbar->fuer(null))->toHaveCount(1);
});

it('zeigt ein Postfach, dessen Besitzer es nicht mehr gibt, niemandem', function () {
    MailAccount::create(['email' => 'weg@beispiel.de', 'user_id' => 77]);

    expect((new SichtbareKonten(new LocalAccountStore))->fuer('anna@beispiel.de'))->toHaveCount(0);
});

it('findet ueber find() dieselbe Sichtbarkeit wie ueber all()', function () {
    $konto = MailAccount::create(['email' => 'anna@beispiel.de', 'user_id' => 3]);

    $gefunden = (new LocalAccountStore)->find($konto->id);

    expect($gefunden->sichtbarFuer('anna@beispiel.de'))->toBeTrue()
        ->and($gefunden->sichtbarFuer('bert@beispiel.de'))->toBeFalse();
});

it('laesst ein Konto mit gelesener Sichtbarkeit weiter speicherbar', function () {
    // Der Token-Erneuerer schreibt in dasselbe Modell zurueck. Steckte die
    // Sichtbarkeit in den Attributen, scheiterte das Speichern an Spalten,
    // die es nicht gibt.
    MailAccount::create(['email' => 'anna@beispiel.de', 'user_id' => 3]);

    $konto = (new LocalAccountStore)->all()->first();
    $konto->forceFill(['label' => 'Anna'])->save();

    expect($konto->fresh()->label)->toBe('Anna');
});

it('fragt die Personen fuer alle Konten in einem Zug ab', function () {
    $abfragenFuer = function (): int {
        DB::flushQueryLog();
        DB::enableQueryLog();
        (new LocalAccountStore)->all();

        return count(DB::getQueryLog());
    };

    $team = MailAccount::create(['email' => 'team@beispiel.de']);
    DB::table('mail_account_user')->insert(['mail_account_id' => $team->id, 'user_id' => 4]);
    MailAccount::create(['email' => 'konto0@beispiel.de', 'user_id' => 3]);
    $beiZwei = $abfragenFuer();

    foreach (range(1, 6) as $n) {
        MailAccount::create(['email' => "konto{$n}@beispiel.de", 'user_id' => 3 + ($n % 3)]);
    }

    expect($abfragenFuer())->toBe($beiZwei);
});
