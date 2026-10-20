<?php

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Peppermint\Mailbox\Index\Kopfzeilenverzeichnis;

uses(RefreshDatabase::class);

/**
 * Das Kopfzeilen-Verzeichnis für sich (#6119).
 *
 * ## Warum es diese Datei gibt, obwohl die Listenseite schon geprüft ist
 *
 * Eine Mutationsprobe hat gezeigt, dass die Invariante dieses Verzeichnisses
 * DOPPELT gesichert ist: einmal hier durch das `whereIn` auf die gefragten
 * Kennungen, und einmal in der Listenseite durch die Schleife über die
 * Kennungen des Servers. Jede Hälfte allein genügt — und genau deshalb fiel
 * kein Test um, als jeweils eine entfernt wurde.
 *
 * Zwei Sicherungen für einen Fehler, der still ist (eine Liste, die falsch
 * aussieht wie eine richtige), sind richtig. Ungeprüfte Sicherungen sind es
 * nicht: Eine Sicherung, deren Wegfall niemand merkt, ist beim nächsten Umbau
 * weg. Also prüft die Listenseite ihre Hälfte, und diese Datei die andere.
 */
function dasVerzeichnis(): Kopfzeilenverzeichnis
{
    return new Kopfzeilenverzeichnis;
}

function verzeichneteZeile(int|string $uid, string $betreff = 'Angebot'): array
{
    return [
        'uid' => $uid,
        'message_id' => "<{$uid}@beispiel.de>",
        'subject' => $betreff,
        'from_address' => 'kunde@beispiel.de',
        'from_name' => 'Kunde',
        'date' => '2026-09-03T10:00:00+00:00',
        'in_reply_to' => null,
        'references' => null,
    ];
}

it('gibt NUR heraus, wonach gefragt wurde — es beantwortet nicht die Belegung', function () {
    /*
     * Die Invariante. Wer dieses Verzeichnis fragt „was liegt in dem Ordner",
     * bekommt hier keine Antwort — und das ist der Grund, warum eine gelöschte
     * Mail nicht erscheinen kann.
     */
    dasVerzeichnis()->merkenKopf(1, 'INBOX', 14, [verzeichneteZeile(1), verzeichneteZeile(2), verzeichneteZeile(3)]);

    $bekannt = dasVerzeichnis()->bekannt(1, 'INBOX', 14, [1, 3]);

    expect(array_keys($bekannt))->toEqualCanonicalizing(['1', '3']);
});

it('gibt nichts heraus, wenn die Gueltigkeit des Ordners gewechselt hat', function () {
    dasVerzeichnis()->merkenKopf(1, 'INBOX', 14, [verzeichneteZeile(1)]);

    expect(dasVerzeichnis()->bekannt(1, 'INBOX', 14, [1]))->toHaveCount(1)
        ->and(dasVerzeichnis()->bekannt(1, 'INBOX', 15, [1]))->toBe([]);
});

it('liest nichts, solange die Gueltigkeit unbekannt ist', function () {
    // Ohne STATUS kennt die Liste die Gueltigkeit nicht. Dann lieber den
    // Server fragen als eine Kennung glauben, die aus einem anderen Ordner
    // stammen koennte.
    dasVerzeichnis()->merkenKopf(1, 'INBOX', 14, [verzeichneteZeile(1)]);

    expect(dasVerzeichnis()->bekannt(1, 'INBOX', null, [1]))->toBe([]);
});

it('loescht beim Aufraeumen nur die ANDEREN Gueltigkeiten', function () {
    dasVerzeichnis()->merkenKopf(1, 'INBOX', 13, [verzeichneteZeile(1)]);
    dasVerzeichnis()->merkenKopf(1, 'INBOX', 14, [verzeichneteZeile(2)]);

    expect(dasVerzeichnis()->aufraeumen(1, 'INBOX', 14))->toBe(1)
        ->and(DB::table('mailbox_headers')->pluck('uid')->all())->toBe(['2']);
});

it('raeumt NICHTS auf, solange die Gueltigkeit unbekannt ist', function () {
    // Sonst leert ein Server ohne STATUS beim ersten Aufruf das ganze
    // Verzeichnis — der teuerste denkbare Irrtum.
    dasVerzeichnis()->merkenKopf(1, 'INBOX', 14, [verzeichneteZeile(1)]);

    expect(dasVerzeichnis()->aufraeumen(1, 'INBOX', null))->toBe(0)
        ->and(DB::table('mailbox_headers')->count())->toBe(1);
});

it('laesst eine teuer erkaufte Anhangzahl von einem Kopf-Abruf NICHT ueberschreiben', function () {
    /*
     * Der Grund für zwei Schreibwege. Mit einem würde der häufige Abruf (Kopf)
     * die Zahl des seltenen und teuren (Struktur) auf NULL zurücksetzen. Der
     * Zwischenspeicher leerte sich selbst, und zwar unauffällig: Es wäre nur
     * langsam, nicht falsch.
     */
    dasVerzeichnis()->merkenKopf(1, 'INBOX', 14, [verzeichneteZeile(1)]);
    dasVerzeichnis()->merkenAnhangzahl(1, 'INBOX', 14, [1 => 3]);

    dasVerzeichnis()->merkenKopf(1, 'INBOX', 14, [verzeichneteZeile(1, 'Betreff geaendert')]);

    $zeile = dasVerzeichnis()->bekannt(1, 'INBOX', 14, [1])['1'];

    expect($zeile['attachment_count'])->toBe(3)
        ->and($zeile['subject'])->toBe('Betreff geaendert');
});

it('laesst die Kopfzeilen von einem Struktur-Abruf NICHT ueberschreiben', function () {
    dasVerzeichnis()->merkenKopf(1, 'INBOX', 14, [verzeichneteZeile(1)]);
    dasVerzeichnis()->merkenAnhangzahl(1, 'INBOX', 14, [1 => 2]);

    $zeile = dasVerzeichnis()->bekannt(1, 'INBOX', 14, [1])['1'];

    expect($zeile['subject'])->toBe('Angebot')
        ->and($zeile['message_id'])->toBe('<1@beispiel.de>')
        ->and($zeile['attachment_count'])->toBe(2);
});

it('merkt sich die Bezuege, obwohl die Spalte anders heisst als das Feld', function () {
    // `references` ist in MySQL ein reserviertes Wort; die Spalte heisst
    // `mail_references`. Der Aufrufer soll davon nichts wissen.
    dasVerzeichnis()->merkenKopf(1, 'INBOX', 14, [array_merge(verzeichneteZeile(1), [
        'in_reply_to' => '<a@x>',
        'references' => '<a@x> <b@x>',
    ])]);

    $zeile = dasVerzeichnis()->bekannt(1, 'INBOX', 14, [1])['1'];

    expect($zeile['references'])->toBe('<a@x> <b@x>')
        ->and($zeile['in_reply_to'])->toBe('<a@x>')
        ->and($zeile)->not->toHaveKey('mail_references');
});

it('haelt Konten und Ordner auseinander', function () {
    dasVerzeichnis()->merkenKopf(1, 'INBOX', 14, [verzeichneteZeile(1)]);

    expect(dasVerzeichnis()->bekannt(2, 'INBOX', 14, [1]))->toBe([])
        ->and(dasVerzeichnis()->bekannt(1, 'Archiv', 14, [1]))->toBe([]);
});

it('merkt sich auch eine Kennung, die keine Zahl ist', function () {
    // IMAP zaehlt, JMAP vergibt Zeichenketten wie `cqiaaaaus`. Das Verzeichnis
    // bedient heute nur IMAP — die Spalte ist breit, weil der Manager dieselbe
    // am 21.09.2026 nachtraeglich verbreitern musste.
    dasVerzeichnis()->merkenKopf(1, 'INBOX', 14, [verzeichneteZeile('cqiaaaaus')]);

    expect(dasVerzeichnis()->bekannt(1, 'INBOX', 14, ['cqiaaaaus']))->toHaveCount(1);
});

it('tut nichts, wenn es abgeschaltet ist', function () {
    config()->set('mailbox.header_index', false);

    dasVerzeichnis()->merkenKopf(1, 'INBOX', 14, [verzeichneteZeile(1)]);

    expect(DB::table('mailbox_headers')->count())->toBe(0)
        ->and(dasVerzeichnis()->bekannt(1, 'INBOX', 14, [1]))->toBe([]);
});

it('legt einen Riegel um, wenn die Tabelle fehlt — und wirft nicht', function () {
    /*
     * Ein Paket-Release liegt immer VOR der Migration der vier Produkte. In dem
     * Fenster dazwischen muss die Mailliste laufen.
     */
    DB::statement('DROP TABLE mailbox_headers');

    $v = dasVerzeichnis();

    expect($v->bekannt(1, 'INBOX', 14, [1]))->toBe([]);

    $v->merkenKopf(1, 'INBOX', 14, [verzeichneteZeile(1)]);
    $v->merkenAnhangzahl(1, 'INBOX', 14, [1 => 2]);

    expect($v->aufraeumen(1, 'INBOX', 14))->toBe(0)
        ->and($v->vergessen(1, 'INBOX'))->toBe(0);
});

it('schreibt in Bloecken, damit ein Fenster nicht als eine Anweisung geht', function () {
    // Hundert Zeilen mal dreizehn Platzhalter sind mehr, als manche Treiber in
    // einer Anweisung annehmen.
    $zeilen = [];

    for ($i = 1; $i <= 250; $i++) {
        $zeilen[] = verzeichneteZeile($i);
    }

    dasVerzeichnis()->merkenKopf(1, 'INBOX', 14, $zeilen);

    expect(DB::table('mailbox_headers')->count())->toBe(250);
});
