<?php

use Illuminate\Support\Collection;
use Peppermint\Mailbox\Contracts\AccountStore;
use Peppermint\Mailbox\Models\MailAccount;
use Peppermint\Mailbox\Stores\MergedAccountStore;

/**
 * Kern zentral, Zusatzfelder lokal (#5845).
 *
 * Die Lücke, an der CRM und Verwaltung nie angeeckt sind, weil sie keine
 * eigenen Spalten am Postfach haben — und an der der Manager sofort aneckt:
 * 44 Spalten, davon ein Dutzend produkteigen, dazu Fremdschlüssel aus anderen
 * Tabellen auf die Kontozeile.
 */
function speicher(array $konten, bool $schreibbar = true): AccountStore
{
    return new class($konten, $schreibbar) implements AccountStore
    {
        public function __construct(private array $konten, private bool $schreibbar) {}

        public function all(?int $ownerId = null): Collection
        {
            return collect($this->konten);
        }

        public function find(string|int $id): ?MailAccount
        {
            return collect($this->konten)->first(fn (MailAccount $k) => (int) $k->getAttribute('id') === (int) $id);
        }

        public function isWritable(): bool
        {
            return $this->schreibbar;
        }
    };
}

/** Eine echte Tabellenzeile, wie ein gewachsenes Produkt sie hat. */
function lokalesKonto(array $werte = []): MailAccount
{
    $konto = new MailAccount;
    $konto->forceFill(array_merge([
        'id' => 7,
        'email' => 'department@example.test',
        'imap_host' => 'alt.example.test',
        'imap_port' => 143,
        'password' => 'altes-kennwort',
        'protocol' => 'imap',
        // produkteigen — hat zentral nichts zu suchen
        'notification_mode' => 'digest',
        'save_to_sent' => true,
        'send_health_status' => 'ok',
    ], $werte));
    $konto->exists = true;
    $konto->syncOriginal();

    return $konto;
}

it('nimmt die Verbindungsdaten aus der Mitte', function () {
    $speicher = new MergedAccountStore(
        speicher([lokalesKonto()]),
        speicher([MailAccount::fromRemote([
            'email' => 'department@example.test',
            'imap_host' => 'outlook.office365.com',
            'imap_port' => 993,
            'password' => 'zentrales-kennwort',
            'protocol' => 'jmap',
        ])]),
    );

    $konto = $speicher->all()->first();

    expect($konto->field('imap_host'))->toBe('outlook.office365.com')
        ->and($konto->field('imap_port'))->toBe(993)
        ->and($konto->field('password'))->toBe('zentrales-kennwort')
        ->and($konto->transport())->toBe('jmap');
});

it('laesst die produkteigenen Felder in Ruhe', function () {
    // Der Grund, warum es diesen Speicher gibt: Benachrichtigungen,
    // Sent-Kopie und Versand-Gesundheit gehen die Mitte nichts an.
    $speicher = new MergedAccountStore(
        speicher([lokalesKonto()]),
        speicher([MailAccount::fromRemote([
            'email' => 'department@example.test',
            'imap_host' => 'outlook.office365.com',
        ])]),
    );

    $konto = $speicher->all()->first();

    expect($konto->getAttribute('notification_mode'))->toBe('digest')
        ->and($konto->getAttribute('save_to_sent'))->toBeTrue()
        ->and($konto->getAttribute('send_health_status'))->toBe('ok');
});

it('bleibt eine Tabellenzeile, damit Fremdschluessel weiter zeigen', function () {
    // Zuweisungen, Ziele, Freigaben haengen an der id. Ein Konto ohne id
    // waere fuer ein gewachsenes Produkt wertlos.
    $speicher = new MergedAccountStore(
        speicher([lokalesKonto()]),
        speicher([MailAccount::fromRemote(['email' => 'department@example.test', 'imap_host' => 'neu.example.test'])]),
    );

    $konto = $speicher->all()->first();

    expect($konto->exists)->toBeTrue()
        ->and($konto->getAttribute('id'))->toBe(7)
        ->and($konto->isCentrallyManaged())->toBeTrue();
});

it('macht aus den zentralen Werten keine speicherbare Aenderung', function () {
    // Sonst schriebe das naechste save() des Produkts die zentralen Werte in
    // die eigene Tabelle — genau die zweite Wahrheit, die hier verhindert
    // werden soll.
    $speicher = new MergedAccountStore(
        speicher([lokalesKonto()]),
        speicher([MailAccount::fromRemote(['email' => 'department@example.test', 'imap_host' => 'neu.example.test'])]),
    );

    $konto = $speicher->all()->first();

    expect($konto->isDirty())->toBeFalse();
});

it('laesst ein zentral unbekanntes Postfach unveraendert — und sagt es', function () {
    // Ein Produkt darf Postfaecher haben, die zentral niemand kennt. Still
    // auf lokalen Werten zu bleiben saehe aus wie ein zentral gepflegtes.
    $speicher = new MergedAccountStore(
        speicher([lokalesKonto(['email' => 'nur-hier@example.test'])]),
        speicher([MailAccount::fromRemote(['email' => 'jemand-anderes@example.test'])]),
    );

    $konto = $speicher->all()->first();

    expect($konto->field('imap_host'))->toBe('alt.example.test')
        ->and($konto->isCentrallyManaged())->toBeFalse();
});

it('trifft dieselbe Adresse in anderer Schreibweise', function () {
    // Postfachadressen sind nicht gross-/kleinschreibungsempfindlich, und
    // zwei Systeme schreiben sie garantiert irgendwann verschieden.
    $speicher = new MergedAccountStore(
        speicher([lokalesKonto(['email' => 'Department@Example.test'])]),
        speicher([MailAccount::fromRemote(['email' => 'department@example.test', 'imap_host' => 'neu.example.test'])]),
    );

    expect($speicher->all()->first()->field('imap_host'))->toBe('neu.example.test');
});

it('ueberschreibt einen funktionierenden Wert nicht mit einer zentralen Luecke', function () {
    // Ein zentral noch nicht gepflegtes SMTP-Feld ist keine Aussage. Es als
    // eine zu nehmen wuerde den Versand abschalten.
    $speicher = new MergedAccountStore(
        speicher([lokalesKonto(['smtp_host' => 'smtp.alt.example.test'])]),
        speicher([MailAccount::fromRemote([
            'email' => 'department@example.test',
            'imap_host' => 'neu.example.test',
            'smtp_host' => null,
        ])]),
    );

    $konto = $speicher->all()->first();

    expect($konto->field('imap_host'))->toBe('neu.example.test')
        ->and($konto->getAttribute('smtp_host'))->toBe('smtp.alt.example.test');
});

it('laesst alles ausserhalb des Kerns in Ruhe — auch is_active und label', function () {
    // „Alles, was die zentrale Zeile hat" waere die bequeme Regel und die
    // gefaehrliche: Ein zentrales is_active schaltete ein Produkt-Postfach ab.
    $lokal = lokalesKonto(['is_active' => true, 'label' => 'Team-Postfach']);

    $speicher = new MergedAccountStore(
        speicher([$lokal]),
        speicher([MailAccount::fromRemote([
            'email' => 'department@example.test',
            'is_active' => false,
            'label' => 'Zentral benannt',
            'imap_host' => 'neu.example.test',
        ])]),
    );

    $konto = $speicher->all()->first();

    expect($konto->getAttribute('is_active'))->toBeTrue()
        ->and($konto->getAttribute('label'))->toBe('Team-Postfach');
});

it('nimmt auch bei find() die Mitte dazu', function () {
    $speicher = new MergedAccountStore(
        speicher([lokalesKonto()]),
        speicher([MailAccount::fromRemote(['email' => 'department@example.test', 'imap_host' => 'neu.example.test'])]),
    );

    expect($speicher->find(7)?->field('imap_host'))->toBe('neu.example.test')
        ->and($speicher->find(999))->toBeNull();
});
