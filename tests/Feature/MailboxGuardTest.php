<?php

use Peppermint\Mailbox\Guard\MailboxGuard;

/**
 * Der Riegel, der die Regel dieses Pakets durchsetzt (#5848).
 *
 * Geprüft wird der Wächter selbst — gegen einen kleinen, echten Dateibaum.
 * Ein Wächter, der nie ausgelöst hat, ist eine Behauptung; die Hälfte der
 * Tests hier ist deshalb der Nachweis, dass er überhaupt rot werden kann.
 */
function baum(array $dateien): string
{
    $wurzel = sys_get_temp_dir().'/waechter-'.bin2hex(random_bytes(6));

    foreach ($dateien as $pfad => $inhalt) {
        $ziel = $wurzel.'/'.$pfad;
        @mkdir(dirname($ziel), 0777, true);
        file_put_contents($ziel, "<?php\n\n".$inhalt);
    }

    return $wurzel;
}

it('schweigt, wenn nur über den Vertrag geredet wird', function () {
    $wurzel = baum([
        'Services/MailService.php' => 'use Peppermint\Mailbox\Contracts\Mailbox; // alles gut',
        'Models/Account.php' => 'class Account {}',
    ]);

    expect(MailboxGuard::pruefen($wurzel))->toBe([]);
});

it('findet ein Produkt, das die Bibliothek direkt anfasst', function () {
    $wurzel = baum([
        'Services/EigenesPostfach.php' => 'use DirectoryTree\ImapEngine\Mailbox;',
    ]);

    $probleme = MailboxGuard::pruefen($wurzel);

    expect($probleme)->toHaveCount(1)
        ->and($probleme[0])->toContain('Services/EigenesPostfach.php')
        ->and($probleme[0])->toContain('JMAP');
});

it('findet eine eigene Token-Rotation', function () {
    $wurzel = baum([
        'Services/Versand.php' => '$t = $dienst->refreshAccessToken($konto->refresh_token);',
    ]);

    $probleme = MailboxGuard::pruefen($wurzel);

    expect($probleme)->toHaveCount(1)
        ->and($probleme[0])->toContain('entwerten');
});

it('steigt in Unterverzeichnisse ab', function () {
    // Der Fehler, der beim Bau dieses Riegels wirklich passiert ist: PHPs
    // glob('**') steigt nicht ab. Der Waechter war gruen und wertlos.
    $wurzel = baum([
        'Services/Mail/Tief/Versteckt.php' => 'use DirectoryTree\ImapEngine\Mailbox;',
    ]);

    expect(MailboxGuard::pruefen($wurzel))->toHaveCount(1);
});

it('nennt in der Meldung den Weg heraus, nicht nur den Verstoß', function () {
    // „Die Fehlermeldung muss die Lösung enthalten" — sonst steht jemand vor
    // einem roten Test und baut sich eine Ausnahme, weil das der einzige
    // sichtbare Ausweg ist.
    $wurzel = baum(['Services/X.php' => 'use DirectoryTree\ImapEngine\Mailbox;']);

    expect(MailboxGuard::pruefen($wurzel)[0])
        ->toContain('Peppermint\Mailbox\Contracts\Mailbox')
        ->toContain('gehört es in den Vertrag');
});

describe('Ausnahmen', function () {
    it('lässt eine begründete Stelle durch', function () {
        // Genau EINE begründete Stelle, nicht „nirgends": Am 21.09.2026 hat
        // ein Riegel, der NULL Erneuerungen verlangte, festgeschrieben, dass
        // drei zentral unbekannte Postfaecher stillschweigend ausfallen.
        $wurzel = baum([
            'Services/Versand.php' => '$t = $dienst->refreshAccessToken($x);',
        ]);

        $probleme = MailboxGuard::pruefen($wurzel, [
            'Services/Versand.php' => 'Erneuert Postfächer, die AI Brain nicht kennt — für die gibt es niemanden sonst.',
        ]);

        expect($probleme)->toBe([]);
    });

    it('deckt ein ganzes Verzeichnis, wenn der Anfang passt', function () {
        $wurzel = baum([
            'Services/OAuth/Microsoft.php' => 'function refreshAccessToken($r) {}',
            'Services/OAuth/Google.php' => 'function refreshAccessToken($r) {}',
        ]);

        expect(MailboxGuard::pruefen($wurzel, ['Services/OAuth/' => 'Die Bibliothek selbst.']))->toBe([]);
    });

    it('meldet eine Ausnahme, die nichts mehr trifft', function () {
        // Der Teil, den man am liebsten weglässt — und der die Liste am Leben
        // hält. Eine tote Ausnahme befreit stillschweigend die nächste Datei,
        // die zufällig so heißt.
        $wurzel = baum(['Services/Sauber.php' => 'use Peppermint\Mailbox\Contracts\Mailbox;']);

        $probleme = MailboxGuard::pruefen($wurzel, [
            'Services/LaengstWeg.php' => 'stand hier mal',
        ]);

        expect($probleme)->toHaveCount(1)
            ->and($probleme[0])->toContain('trifft nichts mehr')
            ->and($probleme[0])->toContain('Services/LaengstWeg.php');
    });

    it('trifft nicht auf einen Namen, der nur ähnlich anfängt', function () {
        // Pfad-Anfang heisst Pfad-ANFANG. Waere hier ein Namensmuster,
        // befreite „Services/Versand" auch „Services/VersandNeu.php".
        $wurzel = baum([
            'Services/Versand.php' => '$t = $x->refreshAccessToken($r);',
            'Services/VersandZwei.php' => '$t = $x->refreshAccessToken($r);',
        ]);

        $probleme = MailboxGuard::pruefen($wurzel, ['Services/Versand.php' => 'begründet']);

        expect($probleme)->toHaveCount(1)
            ->and($probleme[0])->toContain('Services/VersandZwei.php');
    });
});
