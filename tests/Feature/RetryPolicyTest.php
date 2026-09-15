<?php

use Peppermint\Mailbox\Imap\RetryPolicy;

/** Wartet nicht wirklich, merkt sich nur, wie lange gewartet worden waere. */
function wiederholung(array &$gewartet, int $maxRetries = 5): RetryPolicy
{
    return new RetryPolicy(
        maxRetries: $maxRetries,
        sleeper: function (float $sekunden) use (&$gewartet) { $gewartet[] = $sekunden; },
        jitter: fn () => 0.0,
    );
}

describe('welcher Fehler einen zweiten Versuch wert ist', function () {
    it('haelt Leitungsfehler fuer wiederholbar', function (string $text) {
        $gewartet = [];

        expect(wiederholung($gewartet)->isRetryable(new RuntimeException($text)))->toBeTrue();
    })->with([
        'Unexpected EOF while reading',
        'Connection refused by peer',
        'Socket closed unexpectedly',
        'Connection reset by peer',
        'Broken pipe',
        'Operation timed out',
        'stream_select(): timeout',
        'failed to open stream',
    ]);

    it('haelt eine Antwort des Servers NICHT fuer wiederholbar', function (string $text) {
        // Eine abgelehnte Anmeldung wird beim fuenften Versuch nicht richtiger —
        // sie kostet nur eine halbe Minute Schweigen und meldet dann dasselbe.
        $gewartet = [];

        expect(wiederholung($gewartet)->isRetryable(new RuntimeException($text)))->toBeFalse();
    })->with([
        'AUTHENTICATIONFAILED Invalid credentials',
        'Mailbox does not exist',
        'Permission denied',
        'BAD Command Argument Error',
    ]);

    it('liest den Text ohne Ruecksicht auf Gross- und Kleinschreibung', function () {
        $gewartet = [];

        expect(wiederholung($gewartet)->isRetryable(new RuntimeException('CONNECTION RESET')))->toBeTrue();
    });
});

describe('wie lange gewartet wird', function () {
    it('verdoppelt mit jedem Versuch', function () {
        $gewartet = [];
        $p = wiederholung($gewartet);

        expect([$p->delayFor(1), $p->delayFor(2), $p->delayFor(3)])->toBe([2.0, 4.0, 8.0]);
    });

    it('schlaegt den Zufall drauf, damit nicht alle gleichzeitig zurueckkommen', function () {
        $p = new RetryPolicy(jitter: fn () => 0.75);

        expect($p->delayFor(1))->toBe(2.75);
    });
});

describe('der Ablauf', function () {
    it('gibt das Ergebnis zurueck, wenn es beim ersten Mal klappt', function () {
        $gewartet = [];

        expect(wiederholung($gewartet)->run(fn () => 'da'))->toBe('da');
        expect($gewartet)->toBe([]);
    });

    it('versucht es nach einem Leitungsfehler erneut', function () {
        $gewartet = [];
        $versuche = 0;

        $ergebnis = wiederholung($gewartet)->run(function () use (&$versuche) {
            $versuche++;

            if ($versuche < 3) {
                throw new RuntimeException('Connection reset');
            }

            return 'endlich';
        });

        expect($ergebnis)->toBe('endlich');
        expect($versuche)->toBe(3);
        expect($gewartet)->toBe([2.0, 4.0]);
    });

    it('wirft einen nicht wiederholbaren Fehler SOFORT weiter', function () {
        $gewartet = [];
        $versuche = 0;

        // Pfeilfunktion waere hier falsch: sie faengt $gewartet per WERT, und
        // die Referenz im Helfer zeigte dann auf eine Kopie.
        expect(function () use (&$gewartet, &$versuche) {
            wiederholung($gewartet)->run(function () use (&$versuche) {
                $versuche++;
                throw new RuntimeException('AUTHENTICATIONFAILED');
            });
        })->toThrow(RuntimeException::class);

        expect($versuche)->toBe(1);
        expect($gewartet)->toBe([]);
    });

    it('gibt nach der letzten Wiederholung den ECHTEN Fehler heraus, nicht einen eigenen', function () {
        $gewartet = [];

        expect(function () use (&$gewartet) {
            wiederholung($gewartet, maxRetries: 2)->run(function () {
                throw new RuntimeException('Connection reset by peer');
            });
        })->toThrow(RuntimeException::class, 'Connection reset by peer');

        expect($gewartet)->toBe([2.0, 4.0]);
    });

    it('sagt jedem Wiederholungsversuch Bescheid, damit das Produkt es protokollieren kann', function () {
        $gewartet = [];
        $gemeldet = [];

        try {
            wiederholung($gewartet, maxRetries: 2)->run(
                fn () => throw new RuntimeException('timeout'),
                function (Throwable $e, int $versuch, int $von) use (&$gemeldet) {
                    $gemeldet[] = "{$versuch}/{$von}";
                },
            );
        } catch (RuntimeException) {
            // erwartet
        }

        expect($gemeldet)->toBe(['1/2', '2/2']);
    });
});
