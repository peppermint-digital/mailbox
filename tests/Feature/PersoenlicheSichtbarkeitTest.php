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
