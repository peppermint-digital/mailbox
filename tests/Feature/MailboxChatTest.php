<?php

use Peppermint\Mailbox\Brain\MailboxChat;

/**
 * Der Chat mit dem Postfach-Agenten (#5488).
 *
 * Geprüft wird hier vor allem, was passiert, wenn AI Brain NICHT wie erwartet
 * antwortet — denn das ist der Normalfall bei einem angebundenen System, und
 * jede dieser Antworten hat einen eigenen Fehler gekostet.
 */
function chat(?array $antwort, ?array $senden = null): MailboxChat
{
    return new MailboxChat(
        fn (string $email): ?array => $antwort,
        fn (int $chatId, string $content): ?array => $senden,
    );
}

it('antwortet immer in voller Form, auch wenn Brain gar nicht erreichbar ist', function () {
    // Wer hier null bekaeme, schriebe den Ersatz selbst — jedes Produkt etwas
    // anders, und eines vergisst einen Schluessel, den die Oberflaeche liest.
    expect(chat(null)->verlauf('buero@example.test'))
        ->toBe(['chat_id' => null, 'channel' => '', 'status' => null, 'messages' => [], 'activity' => []]);
});

it('behandelt eine abgelehnte Antwort wie keine', function () {
    expect(chat(['ok' => false, 'error' => 'mailbox_not_found'])->verlauf('buero@example.test')['chat_id'])
        ->toBeNull();
});

it('kommt ohne das Feld activity aus', function () {
    // Ein aelteres Brain schickt es nicht. Das darf den Assistenten nicht
    // kaputt machen, sondern nur so aussehen lassen wie vorher.
    $verlauf = chat(['ok' => true, 'chat_id' => 12, 'status' => 'done', 'messages' => [['id' => 1]]])
        ->verlauf('buero@example.test');

    expect($verlauf['activity'])->toBe([])
        ->and($verlauf['messages'])->toHaveCount(1);
});

it('macht aus chat_id 0 kein gueltiges Ziel', function () {
    // Sonst geht die naechste Nachricht an Chat 0 statt in eine klare Absage.
    expect(chat(['ok' => true, 'chat_id' => 0])->verlauf('buero@example.test')['chat_id'])->toBeNull();
});

it('unterscheidet kein Chat von Brain lehnt ab', function () {
    // Beides waere sonst 502, und im Netzwerk-Reiter saehe „dieses Postfach hat
    // keinen Agenten" aus wie ein Ausfall.
    $ohneChat = chat(['ok' => false])->senden('buero@example.test', 'Hallo');

    expect($ohneChat['status'])->toBe(404)
        ->and($ohneChat['ok'])->toBeFalse();
});

it('reicht den Grund einer Ablehnung durch', function () {
    $ergebnis = chat(['ok' => true, 'chat_id' => 12], ['ok' => false, 'error' => 'Kanal gesperrt'])
        ->senden('buero@example.test', 'Hallo');

    expect($ergebnis['status'])->toBe(502)
        ->and($ergebnis['error'])->toBe('Kanal gesperrt');
});

it('nennt einen Grund, auch wenn Brain keinen mitschickt', function () {
    $ergebnis = chat(['ok' => true, 'chat_id' => 12], ['ok' => false])
        ->senden('buero@example.test', 'Hallo');

    expect($ergebnis['error'])->toBe('Unbekannter Grund.');
});

it('haelt eine Antwort ohne ok fuer Erfolg', function () {
    // Es gibt Endpunkte, die nur im Fehlerfall etwas sagen. Die andersherum zu
    // lesen liesse jedes erfolgreiche Senden kaputt aussehen.
    expect(chat(['ok' => true, 'chat_id' => 12], ['accepted' => true])->senden('buero@example.test', 'Hallo')['ok'])
        ->toBeTrue();
});

it('laesst eine Ausnahme nicht bis zum Aufrufer durch', function () {
    // Ein nicht erreichbares Brain ist keine Ausnahme, die der Browser sehen
    // soll — sonst steht statt des Postfachs eine Fehlerseite.
    $chat = new MailboxChat(
        fn (string $email): ?array => throw new RuntimeException('Zeitueberschreitung'),
        fn (int $chatId, string $content): ?array => null,
    );

    expect($chat->verlauf('buero@example.test')['messages'])->toBe([])
        ->and($chat->senden('buero@example.test', 'Hallo')['status'])->toBe(404);
});

it('meldet einen Ausfall beim Senden als 502, nicht als Erfolg', function () {
    $chat = new MailboxChat(
        fn (string $email): ?array => ['ok' => true, 'chat_id' => 12],
        fn (int $chatId, string $content): ?array => throw new RuntimeException('weg'),
    );

    $ergebnis = $chat->senden('buero@example.test', 'Hallo');

    expect($ergebnis['ok'])->toBeFalse()
        ->and($ergebnis['status'])->toBe(502);
});
