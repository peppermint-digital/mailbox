<?php

use Peppermint\Mailbox\Models\MailAccount;
use Peppermint\Mailbox\Sending\Mailer;
use Peppermint\Mailbox\Sending\Outgoing;
use Symfony\Component\Mailer\Transport\Smtp\EsmtpTransport;

/**
 * Der Weg nach draussen (22.09.2026).
 *
 * Geprueft wird der Zusammenbau und der Transport — nicht das Netz. Das ist
 * Absicht: Ein Test, der wirklich sendet, prueft den Anbieter und nicht
 * diesen Code, und er faellt aus, sobald jemand offline arbeitet.
 *
 * Die zwei Einzelheiten, die hier wirklich zaehlen, sind teuer erkauft und
 * waeren beim Neuschreiben verlorengegangen:
 *
 * - Die Einschraenkung auf XOAUTH2 (10,3 s auf 0,3 s je Versand).
 * - Die Anmeldung mit der Hauptadresse statt der eingetragenen.
 */
function sendeKonto(array $felder = []): MailAccount
{
    return MailAccount::fromRemote([
        'id' => 1,
        'email' => 'buero@example.test',
        'label' => 'Büro',
        ...$felder,
    ]);
}

/** Ein JWT-Nutzdatenteil, wie ihn Microsoft ausstellt — ohne gueltige Signatur. */
function sendeToken(array $ansprueche): string
{
    $teil = static fn (array $d): string => rtrim(strtr(base64_encode(json_encode($d)), '+/', '-_'), '=');

    return $teil(['alg' => 'none']).'.'.$teil($ansprueche).'.x';
}

it('setzt Absender, Empfaenger und beide Textzweige', function () {
    // Eine Mail ohne Klartext ist bei Spamfiltern ein schwaches Signal, und
    // Vorschau-Erzeuger lesen ihn.
    $mime = (new Mailer)->build(sendeKonto(), new Outgoing(
        subject: 'Angebot',
        html: '<p>Guten Tag</p>',
        to: [['email' => 'kunde@example.test', 'name' => 'Anna Meier']],
        cc: [['email' => 'kollege@example.test']],
        text: 'Guten Tag',
    ));

    expect($mime->getFrom()[0]->getAddress())->toBe('buero@example.test')
        ->and($mime->getFrom()[0]->getName())->toBe('Büro')
        ->and($mime->getTo()[0]->getAddress())->toBe('kunde@example.test')
        ->and($mime->getCc()[0]->getAddress())->toBe('kollege@example.test')
        ->and($mime->getHtmlBody())->toBe('<p>Guten Tag</p>')
        ->and($mime->getTextBody())->toBe('Guten Tag');
});

it('vergibt eine eigene Message-ID aus der Absenderdomain', function () {
    // Das Produkt braucht sie, um die gesendete Nachricht wiederzufinden und
    // Antworten derselben Unterhaltung zuzuordnen. Wer sie erst aus der
    // Antwort des Servers liest, hat sie bei der Haelfte der Anbieter nicht.
    $mime = (new Mailer)->build(sendeKonto(), new Outgoing('Betreff', '<p>x</p>', [['email' => 'a@b.test']]));

    $id = $mime->getHeaders()->get('Message-ID')->getBodyAsString();

    expect($id)->toContain('@example.test');
});

it('reicht In-Reply-To und References durch', function () {
    // Ohne sie beginnt jede Antwort beim Empfaenger einen neuen Strang.
    $nachricht = (new Outgoing('Re: Angebot', '<p>x</p>', [['email' => 'a@b.test']]))
        ->withHeaders(['In-Reply-To' => '<erste@example.test>', 'References' => '<erste@example.test>']);

    $mime = (new Mailer)->build(sendeKonto(), $nachricht);

    expect($mime->getHeaders()->get('In-Reply-To')->getBodyAsString())->toContain('erste@example.test')
        ->and($mime->getHeaders()->get('References')->getBodyAsString())->toContain('erste@example.test');
});

it('haengt Dateien und Bytes an', function () {
    $mime = (new Mailer)->build(sendeKonto(), new Outgoing(
        subject: 'Mit Anhang',
        html: '<p>x</p>',
        to: [['email' => 'a@b.test']],
        rawAttachments: [['contents' => 'INHALT', 'filename' => 'notiz.txt', 'mime' => 'text/plain']],
    ));

    $namen = array_map(fn ($a) => $a->getFilename(), $mime->getAttachments());

    expect($namen)->toContain('notiz.txt');
});

it('beschraenkt OAuth-Anmeldungen auf XOAUTH2', function () {
    // 10,3 Sekunden je Versand haengen daran. Symfony probiert sonst CRAM-MD5,
    // LOGIN und PLAIN zuerst durch; Office 365 laesst die Fehlversuche ins
    // Leere laufen, bevor das Verfahren drankommt, das funktioniert.
    $transport = (new Mailer)->transportFor(sendeKonto([
        'auth_type' => 'oauth',
        'oauth_provider' => 'microsoft',
        'oauth_access_token' => sendeToken(['upn' => 'buero@example.test']),
    ]));

    expect($transport)->toBeInstanceOf(EsmtpTransport::class);

    // An die Liste kommt man nur ueber die Reflexion — sie ist privat, und
    // genau deshalb ist dieser Test noetig: Faellt die Einschraenkung weg,
    // merkt es sonst niemand, es dauert nur alles zehnmal so lange.
    $eigenschaft = (new ReflectionClass(EsmtpTransport::class))->getProperty('authenticators');
    $eigenschaft->setAccessible(true);

    $verfahren = array_map(fn ($a) => $a->getAuthKeyword(), $eigenschaft->getValue($transport));

    expect($verfahren)->toBe(['XOAUTH2']);
});

it('meldet sich mit der Hauptadresse aus dem Token an, nicht mit der eingetragenen', function () {
    // Ist die eingetragene Adresse ein Zweitname, weist der Postausgang sie ab.
    // Als Absender steht sie trotzdem in der Nachricht — das regelt der Aufbau
    // der Mail, nicht die Anmeldung.
    $mailer = new Mailer;
    $k = sendeKonto([
        'email' => 'zweitname@example.test',
        'auth_type' => 'oauth',
        'oauth_provider' => 'microsoft',
        'oauth_access_token' => sendeToken(['upn' => 'haupt@example.test']),
    ]);

    $transport = $mailer->transportFor($k);

    $eigenschaft = (new ReflectionClass(EsmtpTransport::class))->getProperty('username');
    $eigenschaft->setAccessible(true);

    expect($eigenschaft->getValue($transport))->toBe('haupt@example.test')
        ->and($mailer->build($k, new Outgoing('x', '<p>x</p>', [['email' => 'a@b.test']]))->getFrom()[0]->getAddress())
        ->toBe('zweitname@example.test');
});

it('faellt auf die eingetragene Adresse zurueck, wenn das Token keine nennt', function () {
    $transport = (new Mailer)->transportFor(sendeKonto([
        'auth_type' => 'oauth',
        'oauth_provider' => 'microsoft',
        'oauth_access_token' => sendeToken(['sub' => 'ohne-adresse']),
    ]));

    $eigenschaft = (new ReflectionClass(EsmtpTransport::class))->getProperty('username');
    $eigenschaft->setAccessible(true);

    expect($eigenschaft->getValue($transport))->toBe('buero@example.test');
});

it('weigert sich ohne Zugriffstoken, statt eine leere Anmeldung zu versuchen', function () {
    // Sonst kommt die Absage vom Anbieter — und die liest sich wie eine
    // Stoerung, nicht wie eine offene Einrichtung.
    expect(fn () => (new Mailer)->transportFor(sendeKonto(['auth_type' => 'oauth', 'oauth_access_token' => ''])))
        ->toThrow(RuntimeException::class);
});

it('kennt die Ziele der Anbieter, wenn niemand sie eingetragen hat', function () {
    // Bei OAuth traegt niemand Hostnamen ein, die seit Jahren gleich sind.
    $mailer = new Mailer;

    foreach ([['google', 'smtp.gmail.com'], ['microsoft', 'smtp.office365.com']] as [$anbieter, $erwartet]) {
        $transport = $mailer->transportFor(sendeKonto([
            'auth_type' => 'oauth',
            'oauth_provider' => $anbieter,
            'oauth_access_token' => sendeToken(['upn' => 'a@b.test']),
        ]));

        expect($transport->getStream()->getHost())->toBe($erwartet, "Ziel fuer {$anbieter}");
    }
});

it('nimmt eingetragene SMTP-Angaben, wo es welche gibt', function () {
    $transport = (new Mailer)->transportFor(sendeKonto([
        'auth_type' => 'password',
        'username' => 'buero',
        'password' => 'geheim',
        'smtp_host' => 'mail.example.test',
        'smtp_port' => 2525,
    ]));

    expect($transport->getStream()->getHost())->toBe('mail.example.test');
});
