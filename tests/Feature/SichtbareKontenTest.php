<?php

use Illuminate\Support\Collection;
use Peppermint\Mailbox\Accounts\SichtbareKonten;
use Peppermint\Mailbox\Contracts\AccountStore;
use Peppermint\Mailbox\Models\MailAccount;

/**
 * Welche Postfächer darf diese Person sehen (#8439).
 *
 * ## Was dieser Test festhält
 *
 * Nicht die Regel — die steht in `MailAccount::sichtbarFuer()` und ist
 * gesondert geprüft. Hier geht es um den STAPEL: dass genau zwei Filter gelten
 * (in Betrieb, für diese Person) und kein dritter dazukommt, der sich in einem
 * Produkt einnistet.
 *
 * Gemessen am 21.10.2026 für `henneberg@peppermint-digital.de`, vor dieser
 * Klasse: Brain 8, CRM 6, Verwaltung 6, Manager 5 Postfächer. Vier Antworten
 * auf eine Frage.
 */
function kontoZeile(int $id, string $adresse, ?string $besitzer = null, array $leser = [], bool $aktiv = true): array
{
    return [
        'id' => $id,
        'email' => $adresse,
        'label' => $adresse,
        'user_id' => $besitzer === null ? null : 99,
        'owner_email' => $besitzer,
        'reader_emails' => $leser,
        'is_active' => $aktiv,
        'password' => 'geheim',
        'imap_host' => 'imap.beispiel.de',
        'auth_type' => 'password',
        'oauth_access_token' => null,
        'oauth_token_expires_at' => null,
    ];
}

function speicherMit(array $zeilen): AccountStore
{
    return new class($zeilen) implements AccountStore
    {
        public int $aufrufe = 0;

        public function __construct(private array $zeilen) {}

        public function all(?int $ownerId = null): Collection
        {
            $this->aufrufe++;

            return collect($this->zeilen)->map(fn (array $z): MailAccount => MailAccount::fromRemote($z));
        }

        public function find(string|int $id): ?MailAccount
        {
            return $this->all()->first(fn (MailAccount $k): bool => (string) $k->field('id') === (string) $id);
        }

        public function isWritable(): bool
        {
            return false;
        }
    };
}

it('zeigt ein Gruppenpostfach ohne Leseliste jedem', function () {
    $sichtbar = new SichtbareKonten(speicherMit([kontoZeile(1, 'info@beispiel.de')]));

    expect($sichtbar->fuer('wer@beispiel.de'))->toHaveCount(1)
        ->and($sichtbar->fuer(null))->toHaveCount(1);
});

it('zeigt ein Gruppenpostfach mit Leseliste nur den Eingetragenen', function () {
    $speicher = speicherMit([kontoZeile(1, 'team@beispiel.de', leser: ['a@beispiel.de', 'b@beispiel.de'])]);
    $sichtbar = new SichtbareKonten($speicher);

    expect($sichtbar->fuer('a@beispiel.de'))->toHaveCount(1)
        ->and($sichtbar->fuer('fremd@beispiel.de'))->toHaveCount(0)
        ->and($sichtbar->fuer(null))->toHaveCount(0);
});

it('zeigt ein persoenliches Postfach nur seinem Besitzer', function () {
    $sichtbar = new SichtbareKonten(speicherMit([
        kontoZeile(1, 'eigen@beispiel.de', besitzer: 'eigen@beispiel.de'),
        kontoZeile(2, 'fremd@beispiel.de', besitzer: 'fremd@beispiel.de'),
    ]));

    $meine = $sichtbar->fuer('eigen@beispiel.de');

    expect($meine)->toHaveCount(1)
        ->and($meine->first()->field('email'))->toBe('eigen@beispiel.de');
});

it('laesst ein abgeschaltetes Postfach weg — in JEDEM Produkt', function () {
    /*
     * Der Unterschied, der am 21.10.2026 auffiel: Der Manager hat `is_active`
     * immer geprüft, CRM und Verwaltung nicht. Ein Postfach, das sich nicht
     * verbinden kann, anzuzeigen heisst, jemanden eines auswaehlen zu lassen,
     * das nur einen Fehler liefert.
     */
    $sichtbar = new SichtbareKonten(speicherMit([
        kontoZeile(1, 'laeuft@beispiel.de'),
        kontoZeile(2, 'aus@beispiel.de', aktiv: false),
    ]));

    expect($sichtbar->fuer('wer@beispiel.de')->pluck('id')->map('intval')->all())->toBe([1]);
});

it('haelt ein Postfach ohne die Angabe `is_active` fuer in Betrieb', function () {
    // Eine aeltere Gegenstelle oder eine eigene Tabelle ohne diese Spalte soll
    // sich verhalten wie bisher — nicht stillschweigend alles ausblenden.
    $zeile = kontoZeile(1, 'alt@beispiel.de');
    unset($zeile['is_active']);

    expect((new SichtbareKonten(speicherMit([$zeile])))->fuer('wer@beispiel.de'))->toHaveCount(1);
});

it('gibt ein einzelnes Postfach nur heraus, wenn die Person es sehen darf', function () {
    /*
     * `AccountStore::find()` fragt nicht, WER fragt. Ein Produkt, das eine
     * Kennung aus der Anfrage nimmt und damit `find()` ruft, oeffnet jedes
     * zugeteilte Postfach fuer jeden angemeldeten Menschen.
     */
    $speicher = speicherMit([kontoZeile(7, 'fremd@beispiel.de', besitzer: 'fremd@beispiel.de')]);
    $sichtbar = new SichtbareKonten($speicher);

    expect($speicher->find(7))->not->toBeNull()
        ->and($sichtbar->eines(7, 'ich@beispiel.de'))->toBeNull()
        ->and($sichtbar->eines(7, 'fremd@beispiel.de'))->not->toBeNull();
});

it('findet ein Postfach auch, wenn die Kennung als Zeichenkette kommt', function () {
    // JMAP vergibt Zeichenketten, und eine Kennung aus einer Anfrage ist
    // ohnehin erst einmal Text.
    $sichtbar = new SichtbareKonten(speicherMit([kontoZeile(4, 'team@beispiel.de')]));

    expect($sichtbar->eines('4', 'wer@beispiel.de'))->not->toBeNull();
});

it('nummeriert die Liste neu durch, damit sie als JSON ein Feld bleibt', function () {
    // Ohne `values()` wird aus der Liste in JSON ein Objekt mit Luecken in den
    // Schluesseln, sobald eines herausfaellt — und die Oberflaeche erwartet
    // ein Feld.
    $sichtbar = new SichtbareKonten(speicherMit([
        kontoZeile(1, 'aus@beispiel.de', aktiv: false),
        kontoZeile(2, 'laeuft@beispiel.de'),
    ]));

    expect(array_keys($sichtbar->fuer('wer@beispiel.de')->all()))->toBe([0]);
});
