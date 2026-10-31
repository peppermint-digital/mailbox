<?php

use Peppermint\Mailbox\Contracts\Signaturvorrat;
use Peppermint\Mailbox\Http\LiefertSignaturen;
use Peppermint\Mailbox\Models\MailAccount;

/**
 * Die Signaturen eines Postfachs (31.10.2026).
 *
 * Gefunden von `mail-2b`: Eine `signatureSource` gab nur der Projekt-Manager
 * mit, die anderen drei uebergaben die leere Quelle — und folglich zeichnete
 * das Formular die Zeile gar nicht. Niemandem fiel auf, dass sie fehlte.
 *
 * Geprueft wird hier vor allem die Unterscheidung, an der es sonst still
 * schiefgeht: „dieses Produkt fuehrt keine Signaturen" gegen „gefragt, und es
 * ging schief". Beides ist eine leere Liste; nur eines davon ist in Ordnung.
 */
class TestControllerFuerSignaturen
{
    use LiefertSignaturen;

    public function __construct(private MailAccount $konto) {}

    protected function accountFor(int $account): MailAccount
    {
        return $this->konto;
    }
}

function signaturKonto(): MailAccount
{
    return MailAccount::fromRemote([
        'id' => 1,
        'email' => 'info@example.test',
        'label' => 'Info',
        'auth_type' => 'password',
        'username' => 'info',
        'password' => 'geheim',
    ]);
}

afterEach(function () {
    app()->forgetInstance(Signaturvorrat::class);
    app()->offsetUnset(Signaturvorrat::class);
});

it('gibt eine leere Liste mit `available: false`, wenn das Produkt keinen Vorrat bindet', function () {
    /*
     * Der Normalfall in drei von vier Produkten, und kein Fehler. Eine
     * Fehlerseite dafuer waere falsch — aber eine leere Liste ALLEIN ist
     * zweideutig, und beim naechsten Mal heisst sie „die Bindung ist vergessen
     * worden". Darum beides.
     */
    $antwort = (new TestControllerFuerSignaturen(signaturKonto()))->signaturen(1);

    expect($antwort->getStatusCode())->toBe(200)
        ->and($antwort->getData(true))->toBe(['signatures' => [], 'available' => false]);
});

it('fragt den Vorrat des Produkts und gibt seine Liste heraus', function () {
    app()->bind(Signaturvorrat::class, fn () => new class implements Signaturvorrat
    {
        public function signaturen(MailAccount $konto): array
        {
            return [
                ['id' => 3, 'name' => 'Kurz', 'body_html' => '<p>Gruss</p>'],
                ['id' => 4, 'name' => 'Lang', 'body_html' => '<p>Mit freundlichen Grüßen</p>', 'is_default' => true],
            ];
        }
    });

    $inhalt = (new TestControllerFuerSignaturen(signaturKonto()))->signaturen(1)->getData(true);

    expect($inhalt['available'])->toBeTrue()
        ->and($inhalt['signatures'])->toHaveCount(2)
        ->and($inhalt['signatures'][1]['name'])->toBe('Lang')
        ->and($inhalt['signatures'][1]['is_default'])->toBeTrue()
        /*
         * `is_default` steht IMMER da, auch wenn falsch. Sonst muesste die
         * Oberflaeche raten, ob ein Produkt die Vorgabe nicht fuehrt oder sie
         * bei diesem Eintrag nur nicht gesetzt hat.
         */
        ->and($inhalt['signatures'][0])->toHaveKey('is_default')
        ->and($inhalt['signatures'][0]['is_default'])->toBeFalse();
});

it('bekommt das POSTFACH mit — wer an info@ schreibt, soll nicht privat unterschreiben', function () {
    $gesehen = new stdClass;
    $gesehen->postfach = null;

    app()->bind(Signaturvorrat::class, fn () => new class($gesehen) implements Signaturvorrat
    {
        public function __construct(private stdClass $gesehen) {}

        public function signaturen(MailAccount $konto): array
        {
            $this->gesehen->postfach = $konto->email;

            return [];
        }
    });

    (new TestControllerFuerSignaturen(signaturKonto()))->signaturen(1);

    expect($gesehen->postfach)->toBe('info@example.test');
});

it('nimmt die Verfassen-Seite nicht mit, wenn der Vorrat des Produkts stuerzt', function () {
    /*
     * Der Vorrat ist Produktcode. Ohne Signaturen kann man eine Mail
     * schreiben, ohne Formular nicht. `available: true` bleibt dabei richtig:
     * Gefragt wurde, es ging nur schief — und das steht im Satz.
     */
    app()->bind(Signaturvorrat::class, fn () => new class implements Signaturvorrat
    {
        public function signaturen(MailAccount $konto): array
        {
            throw new RuntimeException('Tabelle weg');
        }
    });

    $antwort = (new TestControllerFuerSignaturen(signaturKonto()))->signaturen(1);

    expect($antwort->getStatusCode())->toBe(502)
        ->and($antwort->getData(true)['signatures'])->toBe([])
        ->and($antwort->getData(true)['available'])->toBeTrue()
        ->and($antwort->getData(true)['message'])->toContain('nicht geladen');
});

it('fuellt fehlende Felder auf, statt an einem halben Eintrag zu zerbrechen', function () {
    // Ein Vorrat, der nur die Kennung kennt, ist kein Absturzgrund.
    app()->bind(Signaturvorrat::class, fn () => new class implements Signaturvorrat
    {
        public function signaturen(MailAccount $konto): array
        {
            return [['id' => 9]];
        }
    });

    $inhalt = (new TestControllerFuerSignaturen(signaturKonto()))->signaturen(1)->getData(true);

    expect($inhalt['signatures'][0])->toBe(['id' => 9, 'name' => '', 'body_html' => '', 'is_default' => false]);
});
