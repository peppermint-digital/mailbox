<?php

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
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

describe('zwei Leseverträge in einer Tabelle (#8440)', function () {
    it('gibt die Lesemarken eines Durchlaufs NICHT an die Liste heraus', function () {
        /*
         * Der Kern der Zusammenlegung. Die Suche darf Lesemarken aus dem
         * Verzeichnis lesen (als Stand des letzten Durchlaufs, Entscheidung des
         * Nutzers vom 21.10.2026) — die LISTE nicht, nie.
         *
         * Weggelassen und nicht ueberschrieben: Die Spalte `is_flagged` heisst
         * genauso wie das Feld der Drahtform. Ein Ueberschreiben ist eine
         * Reihenfolge, kein Riegel.
         */
        dasVerzeichnis()->merkenDurchlauf(1, 'INBOX', 14, [array_merge(verzeichneteZeile(1), [
            'thread_id' => 'kette-1',
            'is_seen' => true,
            'is_flagged' => true,
        ])]);

        $zeile = dasVerzeichnis()->bekannt(1, 'INBOX', 14, [1])['1'];

        expect($zeile)->not->toHaveKey('is_seen')
            ->and($zeile)->not->toHaveKey('is_flagged')
            ->and($zeile)->not->toHaveKey('indexed_at')
            // Die Kette dagegen ist unveraenderlich und darf mit.
            ->and($zeile['thread_id'])->toBe('kette-1');
    });

    it('schreibt die Lesemarken trotzdem in die Tabelle — fuer die Suche', function () {
        dasVerzeichnis()->merkenDurchlauf(1, 'INBOX', 14, [array_merge(verzeichneteZeile(1), [
            'is_seen' => false,
            'is_flagged' => true,
        ])]);

        $roh = DB::table('mailbox_headers')->where('uid', '1')->first();

        expect((bool) $roh->is_flagged)->toBeTrue()
            ->and((bool) $roh->is_seen)->toBeFalse()
            // Ohne Datum waere „Stand des letzten Durchlaufs" nicht pruefbar.
            ->and($roh->indexed_at)->not->toBeNull();
    });

    it('zieht die Kette bei einem ZWEITEN Durchlauf nach', function () {
        /*
         * Ein Kettenschluessel kann sich aendern: Er wird aus Message-ID,
         * In-Reply-To und References gebildet, und erst wenn mehr von der Kette
         * bekannt ist, loest er sich besser auf — der Manager hat dafuer einen
         * eigenen Nachtrags-Befehl.
         *
         * Eine Mutationsprobe hat gezeigt, dass das ungeprueft war: Beim ERSTEN
         * Schreiben kommt die Kette ueber den Insert mit, egal ob sie in der
         * Aktualisierungsliste steht. Nur ein zweiter Durchlauf sieht den
         * Unterschied.
         */
        dasVerzeichnis()->merkenDurchlauf(1, 'INBOX', 14, [array_merge(verzeichneteZeile(1), [
            'thread_id' => 'erst-allein',
        ])]);

        dasVerzeichnis()->merkenDurchlauf(1, 'INBOX', 14, [array_merge(verzeichneteZeile(1), [
            'thread_id' => 'jetzt-in-der-kette',
            'is_seen' => true,
        ])]);

        $zeile = dasVerzeichnis()->bekannt(1, 'INBOX', 14, [1])['1'];

        expect($zeile['thread_id'])->toBe('jetzt-in-der-kette');
    });

    it('laesst die Anhangzahl von einem Durchlauf unangetastet', function () {
        // Ein Durchlauf holt keine BODYSTRUCTURE. Sie mitzuschreiben hiesse,
        // den teuersten Wert bei jedem Lauf auf NULL zurueckzusetzen.
        dasVerzeichnis()->merkenKopf(1, 'INBOX', 14, [verzeichneteZeile(1)]);
        dasVerzeichnis()->merkenAnhangzahl(1, 'INBOX', 14, [1 => 4]);

        dasVerzeichnis()->merkenDurchlauf(1, 'INBOX', 14, [array_merge(verzeichneteZeile(1), [
            'is_seen' => true,
        ])]);

        expect(dasVerzeichnis()->bekannt(1, 'INBOX', 14, [1])['1']['attachment_count'])->toBe(4);
    });

    it('merkt sich die Kette eines Durchlaufs ohne die Kopfzeilen zu verlieren', function () {
        dasVerzeichnis()->merkenDurchlauf(1, 'INBOX', 14, [array_merge(verzeichneteZeile(1, 'Angebot Meier'), [
            'thread_id' => 'kette-7',
            'references' => '<a@x> <b@x>',
        ])]);

        $zeile = dasVerzeichnis()->bekannt(1, 'INBOX', 14, [1])['1'];

        expect($zeile['subject'])->toBe('Angebot Meier')
            ->and($zeile['thread_id'])->toBe('kette-7')
            ->and($zeile['references'])->toBe('<a@x> <b@x>');
    });
});

describe('der Sortierschlüssel für SQL (#8440)', function () {
    it('leitet date_at aus dem Datum ab, in UTC', function () {
        /*
         * `date` ist die Drahtform mit dem Versatz des Absenders, `date_at` der
         * Sortierschlüssel. Auf der Zeichenkette wäre `orderByDesc('date')`
         * lexikalisch — und damit falsch, sobald Versätze gemischt sind.
         */
        dasVerzeichnis()->merkenKopf(1, 'INBOX', 14, [array_merge(verzeichneteZeile(1), [
            'date' => '2026-01-01T23:00:00+02:00',
        ])]);

        $roh = DB::table('mailbox_headers')->where('uid', '1')->first();

        expect($roh->date)->toBe('2026-01-01T23:00:00+02:00')
            ->and(Carbon::parse($roh->date_at)->toIso8601String())->toBe('2026-01-01T21:00:00+00:00');
    });

    it('sortiert ueber date_at richtig, wo die Zeichenkette es falsch machen wuerde', function () {
        /*
         * Die Probe, die den ganzen Zweck zeigt: Dieselben zwei Augenblicke,
         * einmal als Zeichenkette und einmal als Zeitpunkt sortiert, kommen
         * in VERSCHIEDENER Reihenfolge heraus.
         */
        dasVerzeichnis()->merkenKopf(1, 'INBOX', 14, [
            array_merge(verzeichneteZeile(1, 'frueher'), ['date' => '2026-01-01T23:00:00+02:00']),
            array_merge(verzeichneteZeile(2, 'spaeter'), ['date' => '2026-01-01T22:00:00+00:00']),
        ]);

        // Nach der Zeichenkette: die 23-Uhr-Zeile zuerst — falsch.
        expect(DB::table('mailbox_headers')->orderByDesc('date')->pluck('subject')->all())
            ->toBe(['frueher', 'spaeter']);

        // Nach dem Zeitpunkt: die 22-Uhr-UTC-Zeile ist wirklich die spaetere.
        expect(DB::table('mailbox_headers')->orderByDesc('date_at')->pluck('subject')->all())
            ->toBe(['spaeter', 'frueher']);
    });

    it('verliert eine Zeile nicht, wenn ihr Datum unlesbar ist', function () {
        // Ein unlesbares Datum ist kein Grund, die Kopfzeile zu verlieren. Sie
        // wird dann nur nicht einsortiert.
        dasVerzeichnis()->merkenKopf(1, 'INBOX', 14, [array_merge(verzeichneteZeile(1), [
            'date' => 'kein Datum',
        ])]);

        $roh = DB::table('mailbox_headers')->where('uid', '1')->first();

        expect($roh)->not->toBeNull()
            ->and($roh->date_at)->toBeNull()
            ->and($roh->subject)->toBe('Angebot');
    });

    it('zieht den Sortierschluessel bei einem ZWEITEN Schreiben nach', function () {
        /*
         * Eine Mutationsprobe hat das erzwungen: Beim ERSTEN Schreiben kommt
         * `date_at` ueber den Insert mit, egal ob es in der
         * Aktualisierungsliste steht. Nur ein zweites Schreiben derselben Zeile
         * sieht den Unterschied.
         *
         * Die Listenseite ruft `merkenKopf()` nur fuer unbekannte Kennungen,
         * trifft den Fall also nicht — aber die Methode ist oeffentlich, und
         * ein Datum, das sich aendert, ohne dass der Sortierschluessel
         * mitgeht, waere eine Liste, die nach einem alten Augenblick sortiert.
         */
        dasVerzeichnis()->merkenKopf(1, 'INBOX', 14, [array_merge(verzeichneteZeile(1), [
            'date' => '2026-01-01T10:00:00+00:00',
        ])]);

        dasVerzeichnis()->merkenKopf(1, 'INBOX', 14, [array_merge(verzeichneteZeile(1), [
            'date' => '2026-06-01T10:00:00+02:00',
        ])]);

        $roh = DB::table('mailbox_headers')->where('uid', '1')->first();

        expect($roh->date)->toBe('2026-06-01T10:00:00+02:00')
            ->and(Carbon::parse($roh->date_at)->toIso8601String())->toBe('2026-06-01T08:00:00+00:00');
    });

    it('gibt den Sortierschluessel nicht an die Liste heraus', function () {
        // Ein zweites Datum in der Zeile waere eine Einladung, mal das eine und
        // mal das andere zu nehmen. Die Liste sortiert in PHP ueber `date`.
        dasVerzeichnis()->merkenKopf(1, 'INBOX', 14, [verzeichneteZeile(1)]);

        expect(dasVerzeichnis()->bekannt(1, 'INBOX', 14, [1])['1'])->not->toHaveKey('date_at');
    });
});

describe('die Gueltigkeit wird nachgezogen (#8440)', function () {
    it('fuellt eine Zeile ohne Gueltigkeit beim naechsten Schreiben nach', function () {
        /*
         * Der Fehler, den erst der Blick in Produktion gezeigt hat: Nach dem
         * Umzug von 88.307 Zeilen aus einer Tabelle OHNE diese Spalte stand
         * nach einem vollstaendigen Durchlauf noch bei KEINER eine Gueltigkeit.
         *
         * Der Grund: Beim Einfuegen kommt sie mit, in der Aktualisierungsliste
         * des Upserts stand sie nicht — und nach einem Umzug sind alle
         * Schreibvorgaenge Aktualisierungen.
         *
         * Eine Zeile ohne Gueltigkeit gilt der Listenseite unter JEDER als
         * lesbar. Sie nie zu fuellen heisst also, den Riegel dauerhaft offen zu
         * lassen.
         */
        DB::table('mailbox_headers')->insert([
            'account_id' => 1, 'folder' => 'INBOX', 'uid' => '1', 'uidvalidity' => null,
            'subject' => 'umgezogen', 'created_at' => now(), 'updated_at' => now(),
        ]);

        dasVerzeichnis()->merkenDurchlauf(1, 'INBOX', 14, [verzeichneteZeile(1)]);

        expect((int) DB::table('mailbox_headers')->where('uid', '1')->value('uidvalidity'))->toBe(14);
    });

    it('zieht auch eine GEAENDERTE Gueltigkeit nach — zusammen mit frischen Daten', function () {
        /*
         * Das sieht gefaehrlich aus und ist richtig: Gefaehrlich waere, die
         * Gueltigkeit zu erneuern OHNE die Daten. Beide Schreibwege holen die
         * Daten in demselben Zug vom Server, die Zeile gehoert also wirklich
         * zur neuen Gueltigkeit.
         */
        dasVerzeichnis()->merkenKopf(1, 'INBOX', 14, [verzeichneteZeile(1, 'alter Betreff')]);
        dasVerzeichnis()->merkenKopf(1, 'INBOX', 20, [verzeichneteZeile(1, 'frisch geholt')]);

        $roh = DB::table('mailbox_headers')->where('uid', '1')->first();

        expect((int) $roh->uidvalidity)->toBe(20)
            ->and($roh->subject)->toBe('frisch geholt')
            // Und unter der alten ist sie nicht mehr lesbar.
            ->and(dasVerzeichnis()->bekannt(1, 'INBOX', 14, [1]))->toBe([]);
    });
});
