<?php

use Peppermint\Mailbox\Models\MailAccount;

/**
 * Wer ein persoenliches Postfach sehen darf.
 *
 * Eine Freigabe gilt einem PRODUKT, nicht einer Person. Ohne diese zweite
 * Ebene zeigt ein Produkt jedes freigegebene Postfach jedem angemeldeten
 * Benutzer — und ein persoenlicher Posteingang faellt der ganzen Belegschaft
 * in die Haende.
 */
function sichtbarkeitsKonto(array $werte = []): MailAccount
{
    return MailAccount::fromRemote($werte + [
        'id' => 1,
        'email' => 'team@example.test',
        'user_id' => null,
        'owner_email' => null,
    ]);
}

it('zeigt ein Gruppenpostfach jedem', function () {
    expect(sichtbarkeitsKonto()->sichtbarFuer('irgendwer@example.test'))->toBeTrue()
        ->and(sichtbarkeitsKonto()->istPersoenlich())->toBeFalse();
});

it('zeigt ein persönliches Postfach nur seinem Besitzer', function () {
    $k = sichtbarkeitsKonto(['user_id' => 7, 'owner_email' => 'chris@example.test']);

    expect($k->istPersoenlich())->toBeTrue()
        ->and($k->sichtbarFuer('chris@example.test'))->toBeTrue()
        ->and($k->sichtbarFuer('jemand.anderes@example.test'))->toBeFalse();
});

it('achtet nicht auf Groß- und Kleinschreibung', function () {
    // Mailadressen kommen aus verschiedenen Quellen und Systemen. Wer hier
    // genau vergleicht, sperrt Leute aus ihrem eigenen Postfach aus.
    $k = sichtbarkeitsKonto(['user_id' => 7, 'owner_email' => 'Chris@Example.test']);

    expect($k->sichtbarFuer('chris@example.test'))->toBeTrue();
});

it('sperrt niemanden aus, wenn die Gegenstelle den Besitzer gar nicht nennt', function () {
    // Eine aeltere Gegenstelle oder eine eigene Tabelle liefert `owner_email`
    // nicht. Dann bleibt es beim bisherigen Verhalten — ein Produkt, das die
    // Angabe nicht bekommt, soll nicht stillschweigend alles ausblenden.
    $k = sichtbarkeitsKonto(['user_id' => 7]);

    expect($k->sichtbarFuer('irgendwer@example.test'))->toBeTrue();
});

it('zeigt einem Aufruf ohne Person kein persönliches Postfach', function () {
    // Ein Hintergrundlauf hat keinen Posteingang.
    $k = sichtbarkeitsKonto(['user_id' => 7, 'owner_email' => 'chris@example.test']);

    expect($k->sichtbarFuer(null))->toBeFalse();
});

it('zeigt ein Gruppenpostfach mit Leseliste nur den Eingetragenen', function () {
    // Die zweite Ebene fuer Gruppenpostfaecher: Ein Postfach gehoert niemandem
    // und trotzdem nicht jedem. Bis hierher war „kein Besitzer" gleichbedeutend
    // mit „fuer alle" — damit war ein Postfach, das drei Leute betreuen, im
    // Produkt nicht begrenzbar.
    $k = sichtbarkeitsKonto(['reader_emails' => ['chris@example.test', 'volker@example.test']]);

    expect($k->istPersoenlich())->toBeFalse()
        ->and($k->sichtbarFuer('chris@example.test'))->toBeTrue()
        ->and($k->sichtbarFuer('volker@example.test'))->toBeTrue()
        ->and($k->sichtbarFuer('jemand.anderes@example.test'))->toBeFalse();
});

it('nimmt die Leseliste ohne Ruecksicht auf die Schreibweise', function () {
    $k = sichtbarkeitsKonto(['reader_emails' => ['Chris@Example.test']]);

    expect($k->sichtbarFuer('chris@example.test'))->toBeTrue();
});

it('zeigt ein Gruppenpostfach ohne Leseliste weiter jedem', function () {
    // Die Rueckseite: leer heisst „fuer alle", nicht „fuer niemanden". Sonst
    // waere mit dem Einspielen jedes Gruppenpostfach verschwunden, ohne dass
    // jemand etwas entschieden hat.
    foreach ([[], null, 'nonsens'] as $wert) {
        expect(sichtbarkeitsKonto(['reader_emails' => $wert])->sichtbarFuer('irgendwer@example.test'))
            ->toBeTrue();
    }

    // Und eine aeltere Gegenstelle schickt das Feld gar nicht.
    expect(sichtbarkeitsKonto()->sichtbarFuer('irgendwer@example.test'))->toBeTrue();
});

it('zeigt einem Aufruf ohne Person kein Gruppenpostfach mit Leseliste', function () {
    // Eine Leseliste beantwortet „welcher MENSCH darf hineinsehen". Wo keine
    // Person gefragt ist, gibt es auch keine Antwort — anders als bei einem
    // Postfach ohne Liste, das weiterhin jedem offensteht.
    $k = sichtbarkeitsKonto(['reader_emails' => ['chris@example.test']]);

    expect($k->sichtbarFuer(null))->toBeFalse();
});

it('behandelt eine Liste aus leeren Einträgen wie keine Liste', function () {
    // Festgehalten, weil es eine Entscheidung ist und keine Nebenwirkung:
    // Leere Einträge werden verworfen, und bleibt dann nichts übrig, gilt
    // „keine Liste" — also für alle. Dieselbe Richtung wie überall hier, und
    // Brain erzeugt eine solche Liste gar nicht; sie käme von einer
    // Gegenstelle, die Unsinn liefert, und darauf ist die richtige Antwort
    // „wie vorher" und nicht „alle ausgesperrt".
    //
    // Was NICHT passieren darf: dass ein leerer Eintrag zum Schlüssel wird und
    // jemand ohne Adresse hineinkommt.
    $k = sichtbarkeitsKonto(['reader_emails' => ['', '   ']]);

    expect($k->sichtbarFuer('irgendwer@example.test'))->toBeTrue();

    $mitEchter = sichtbarkeitsKonto(['reader_emails' => ['', 'chris@example.test']]);

    expect($mitEchter->sichtbarFuer('chris@example.test'))->toBeTrue()
        ->and($mitEchter->sichtbarFuer(''))->toBeFalse()
        ->and($mitEchter->sichtbarFuer('   '))->toBeFalse();
});

/*
 * Erklaert statt erraten (#8941).
 *
 * Der Test oben — „sperrt niemanden aus, wenn die Gegenstelle den Besitzer gar
 * nicht nennt" — bleibt unveraendert gueltig und steht bewusst daneben: Ohne
 * Zusage aendert sich nichts. Die folgenden drei pruefen, was MIT Zusage gilt.
 */

it('sperrt bei ZUGESAGTER Vollstaendigkeit aus, wenn der Besitzer fehlt', function () {
    /*
     * Die eigentliche Lücke: Eine Gegenstelle, die sagt „ich kenne Besitzer und
     * Leseliste", nennt ein persönliches Postfach ohne Besitzer. Vorher war das
     * ein Freibrief für jeden Angemeldeten — und genau so fällt ein
     * persönlicher Posteingang der Belegschaft in die Hände.
     */
    $k = sichtbarkeitsKonto(['user_id' => 7, 'sichtbarkeit_verbindlich' => true]);

    expect($k->sichtbarFuer('irgendwer@example.test'))->toBeFalse()
        ->and($k->sichtbarkeitVerbindlich())->toBeTrue();
});

it('zeigt bei zugesagter Vollstaendigkeit weiterhin dem Besitzer', function () {
    // Die Gegenprobe. Eine Regel, die nur noch aussperrt, bestuende den Test
    // oben auch — und waere kaputt.
    $k = sichtbarkeitsKonto([
        'user_id' => 7,
        'owner_email' => 'chris@example.test',
        'sichtbarkeit_verbindlich' => true,
    ]);

    expect($k->sichtbarFuer('chris@example.test'))->toBeTrue()
        ->and($k->sichtbarFuer('jemand.anderes@example.test'))->toBeFalse();
});

it('laesst ein Gruppenpostfach ohne Leseliste auch mit Zusage fuer alle offen', function () {
    /*
     * Die Zusage verschaerft NUR die Lücke beim Besitzer. „Keine Leseliste
     * heisst fuer alle" ist kein Loch, sondern eine Aussage — die Mitte
     * schickt die leere Liste ausdruecklich mit.
     */
    $k = sichtbarkeitsKonto(['sichtbarkeit_verbindlich' => true]);

    expect($k->sichtbarFuer('irgendwer@example.test'))->toBeTrue();
});

it('behandelt eine fehlende Zusage wie ein ausdrueckliches Nein', function () {
    // Kein Dritter Zustand: Was nicht zugesagt ist, gilt als nicht zugesagt.
    expect(sichtbarkeitsKonto(['user_id' => 7])->sichtbarkeitVerbindlich())->toBeFalse()
        ->and(sichtbarkeitsKonto(['user_id' => 7, 'sichtbarkeit_verbindlich' => false])->sichtbarFuer('x@example.test'))->toBeTrue();
});
