<?php

use Carbon\Carbon;
use DirectoryTree\ImapEngine\Address;
use DirectoryTree\ImapEngine\Message;
use Peppermint\Mailbox\Imap\MessageFormatter;
use ZBateson\MailMimeParser\Header\IHeader;

/**
 * The one class in this package that knows what IMAP is (#5488).
 *
 * The message is mocked on purpose: the point of these tests is the mapping
 * and the inline-image handling, and neither needs a server to be wrong.
 */
function nachricht(array $werte = []): Message
{
    $standard = [
        'uid' => 42,
        'messageId' => '<a@x>',
        'subject' => 'Angebot',
        'date' => Carbon::parse('2026-09-01T10:00:00+00:00'),
        'hasAttachments' => false,
        'attachmentCount' => 0,
        'isSeen' => true,
        'isFlagged' => false,
        'text' => 'Guten Tag, anbei das Angebot.',
        'html' => null,
        'attachments' => [],
        'to' => [],
        'cc' => [],
    ];

    $werte = array_merge($standard, $werte);

    $message = Mockery::mock(Message::class);

    foreach ($werte as $methode => $wert) {
        if (in_array($methode, ['from', 'header'], true)) {
            continue;
        }

        $message->shouldReceive($methode)->andReturn($wert);
    }

    // array_key_exists und nicht `??`: Ein ausdrueckliches `null` ist hier eine
    // Aussage („Nachricht ohne Absender") und darf nicht in den Standardwert
    // zurueckfallen — sonst prueft der Test das Gegenteil von dem, was drandsteht.
    $message->shouldReceive('from')->andReturn(
        array_key_exists('from', $werte) ? $werte['from'] : adresse('kunde@example.test', 'Kunde')
    );
    $message->shouldReceive('header')->with('in-reply-to')->andReturn(kopfzeile($werte['inReplyTo'] ?? null));
    $message->shouldReceive('header')->with('references')->andReturn(kopfzeile($werte['references'] ?? null));

    return $message;
}

function adresse(?string $email, ?string $name): Address
{
    // Die echte Klasse, nicht eine nachgebaute: Der Mock erzwingt den
    // Rueckgabetyp, und genau das ist gut so — ein Test, der an einer
    // Typaenderung der Bibliothek vorbeilaeuft, prueft nichts.
    return new Address((string) $email, (string) $name);
}

function kopfzeile(?string $wert): ?IHeader
{
    if ($wert === null) {
        return null;
    }

    $header = Mockery::mock(IHeader::class);
    $header->shouldReceive('getValue')->andReturn($wert);

    return $header;
}

function anhang(array $werte): object
{
    return new class($werte)
    {
        public function __construct(private array $w) {}

        public function contentId(): ?string
        {
            return $this->w['contentId'] ?? null;
        }

        public function contentDisposition(): ?string
        {
            return $this->w['disposition'] ?? null;
        }

        public function contentType(): ?string
        {
            return $this->w['type'] ?? null;
        }

        public function filename(): ?string
        {
            return $this->w['filename'] ?? null;
        }

        public function extension(): ?string
        {
            return $this->w['extension'] ?? null;
        }

        public function contents(): string
        {
            $this->gelesen++;

            return $this->w['contents'] ?? '';
        }

        /** Wie oft der Inhalt abgefragt wurde — siehe Bug #557. */
        public int $gelesen = 0;
    };
}

it('maps the headers a list view needs', function () {
    $zeile = (new MessageFormatter)->summary(nachricht(['inReplyTo' => '<b@x>', 'references' => '<a@x> <b@x>']));

    expect($zeile['uid'])->toBe(42)
        ->and($zeile['from_address'])->toBe('kunde@example.test')
        ->and($zeile['from_name'])->toBe('Kunde')
        ->and($zeile['date'])->toBe('2026-09-01T10:00:00+00:00')
        // Without these two a list cannot be grouped into conversations
        // afterwards without a second round trip.
        ->and($zeile['in_reply_to'])->toBe('<b@x>')
        ->and($zeile['references'])->toBe('<a@x> <b@x>');
});

it('leaves a message without a subject unnamed', function () {
    expect((new MessageFormatter)->summary(nachricht(['subject' => null]))['subject'])->toBeNull();
});

it('shortens the preview and falls back to the html body', function () {
    $zeile = (new MessageFormatter)->summary(nachricht([
        'text' => null,
        'html' => '<p>'.str_repeat('sehr lang ', 40).'</p>',
    ]));

    // Str::limit haengt „..." an, die Grenze gilt fuer den Inhalt davor.
    expect(mb_strlen($zeile['preview']))->toBeLessThanOrEqual(103)
        ->and($zeile['preview'])->toEndWith('...')
        ->and($zeile['preview'])->not->toContain('<p>');
});

it('resolves an inline image into the body', function () {
    // A reader that gets body and attachments separately has to reinvent this,
    // and reinventing it is where the broken-image bugs come from.
    $voll = (new MessageFormatter)->full(nachricht([
        'html' => '<img src="cid:logo@x">',
        'attachments' => [anhang(['contentId' => 'logo@x', 'disposition' => 'inline', 'type' => 'image/png', 'contents' => 'PNG'])],
    ]));

    expect($voll['body_html'])->toBe('<img src="data:image/png;base64,'.base64_encode('PNG').'">')
        // An inline image is not a file to download.
        ->and($voll['attachments'])->toBe([]);
});

it('treats an image without a disposition as inline', function () {
    // Some clients set neither. Filing those as attachments leaves a hole in
    // the text where the picture should be.
    $voll = (new MessageFormatter)->full(nachricht([
        'html' => '<img src="cid:logo@x">',
        'attachments' => [anhang(['contentId' => 'logo@x', 'type' => 'image/png', 'contents' => 'PNG'])],
    ]));

    expect($voll['attachments'])->toBe([])
        ->and($voll['body_html'])->toContain('data:image/png;base64');
});

it('keeps a real attachment as a file', function () {
    $voll = (new MessageFormatter)->full(nachricht([
        'attachments' => [anhang(['filename' => 'angebot.pdf', 'disposition' => 'attachment', 'type' => 'application/pdf', 'contents' => '%PDF-1.7'])],
    ]));

    expect($voll['attachments'])->toHaveCount(1)
        ->and($voll['attachments'][0]['filename'])->toBe('angebot.pdf')
        ->and($voll['attachments'][0]['size'])->toBe(8);
});

it('names an attachment that brought no filename', function () {
    $voll = (new MessageFormatter)->full(nachricht([
        'attachments' => [anhang(['disposition' => 'attachment', 'type' => 'application/pdf'])],
    ]));

    expect($voll['attachments'][0]['filename'])->toBe('attachment');
});

it('maps recipients and copies', function () {
    $voll = (new MessageFormatter)->full(nachricht([
        'to' => [adresse('a@example.test', 'A')],
        'cc' => [adresse('b@example.test', '')],
    ]));

    expect($voll['to'])->toBe([['email' => 'a@example.test', 'name' => 'A']])
        ->and($voll['cc'])->toBe([['email' => 'b@example.test', 'name' => '']]);
});

it('survives a message without a sender', function () {
    $zeile = (new MessageFormatter)->summary(nachricht(['from' => null]));

    expect($zeile['from_address'])->toBe('')
        ->and($zeile['from_name'])->toBe('');
});

describe('attachmentAt (#5670)', function () {
    it('gibt den Anhang mit seinen Bytes heraus', function () {
        // Die Ansicht listet Name, Typ und Groesse — genug zum Zeigen, nicht
        // genug zum Ablegen.
        $m = nachricht(['attachments' => [
            anhang(['filename' => 'rechnung.pdf', 'type' => 'application/pdf', 'contents' => '%PDF-1.7']),
        ]]);

        expect((new MessageFormatter)->attachmentAt($m, 0))->toBe([
            'filename' => 'rechnung.pdf',
            'mime_type' => 'application/pdf',
            'contents' => '%PDF-1.7',
        ]);
    });

    it('zaehlt wie die Ansicht, auch wenn ein Logo dazwischen liegt', function () {
        // Der Fallstrick: Inline-Bilder erscheinen NICHT in der Liste, behalten
        // aber ihre Nummer. Wer die sichtbaren durchzaehlt, legt bei jeder Mail
        // mit Logo die falsche Datei ab.
        $m = nachricht(['attachments' => [
            anhang(['contentId' => 'logo@x', 'disposition' => 'inline', 'type' => 'image/png', 'contents' => 'PNG']),
            anhang(['filename' => 'rechnung.pdf', 'type' => 'application/pdf', 'contents' => '%PDF']),
        ]]);

        $formatter = new MessageFormatter;

        // Die Ansicht nennt fuer die PDF den Index 1 …
        expect($formatter->full($m)['attachments'][0]['index'])->toBe(1)
            // … und genau dieser Index muss die PDF liefern, nicht das Logo.
            ->and($formatter->attachmentAt($m, 1)['filename'])->toBe('rechnung.pdf');
    });

    it('gibt ein Inline-Bild nicht als Anhang heraus', function () {
        // Die Stelle gibt es, sie gehoert aber in den Rumpf. Wer sie anfordert,
        // meint etwas anderes.
        $m = nachricht(['attachments' => [
            anhang(['contentId' => 'logo@x', 'disposition' => 'inline', 'type' => 'image/png', 'contents' => 'PNG']),
        ]]);

        expect((new MessageFormatter)->attachmentAt($m, 0))->toBeNull();
    });

    it('antwortet null auf eine Stelle, die es nicht gibt', function () {
        // Ein Postfach ist geteilt, und Dinge bewegen sich.
        $m = nachricht(['attachments' => [anhang(['filename' => 'a.pdf', 'contents' => 'x'])]]);

        expect((new MessageFormatter)->attachmentAt($m, 7))->toBeNull();
    });

    it('nennt einen namenlosen Anhang beim Namen', function () {
        // Manche Anhaenge tragen keinen. Eine Datei ohne Namen laesst sich
        // weder ablegen noch wiederfinden.
        $m = nachricht(['attachments' => [anhang(['type' => 'application/octet-stream', 'contents' => 'x'])]]);

        expect((new MessageFormatter)->attachmentAt($m, 0)['filename'])->toBe('attachment');
    });
});

describe('verbatim — die Form fuers Aufbewahren', function () {
    it('laesst den Rumpf roh, mit seinen cid-Verweisen', function () {
        // Der ganze Grund fuer das Verb. full() loest ein eingebettetes Bild in
        // eine data:-URI auf, damit eine Ansicht nichts nachladen muss. Ein
        // Archiv braucht das Gegenteil: Waere die Anzeigeform gespeichert,
        // laege jedes Signaturlogo einmal pro Mail in der Datenbank.
        $bild = anhang(['contentId' => 'logo@x', 'disposition' => 'inline', 'type' => 'image/png', 'filename' => 'logo.png', 'contents' => 'PNG']);

        $nachricht = nachricht([
            'html' => '<p>Hallo</p><img src="cid:logo@x">',
            'attachments' => [$bild],
        ]);

        $anzeige = (new MessageFormatter)->full($nachricht);
        $archiv = (new MessageFormatter)->verbatim(nachricht([
            'html' => '<p>Hallo</p><img src="cid:logo@x">',
            'attachments' => [anhang(['contentId' => 'logo@x', 'disposition' => 'inline', 'type' => 'image/png', 'filename' => 'logo.png', 'contents' => 'PNG'])],
        ]));

        expect($anzeige['body_html'])->toContain('data:image/png;base64,')
            ->and($archiv['body_html'])->toBe('<p>Hallo</p><img src="cid:logo@x">')
            ->and($archiv['body_html'])->not->toContain('data:');
    });

    it('gibt eingebettete Bilder als eigene Dateien heraus — mit ihrer Kennung', function () {
        // full() zaehlt sie gar nicht erst zu den Anhaengen; wer sie ablegen
        // will, braucht Bytes UND content_id, sonst laesst sich der cid-Verweis
        // im Rumpf hinterher nicht umschreiben.
        $archiv = (new MessageFormatter)->verbatim(nachricht([
            'html' => '<img src="cid:logo@x">',
            'attachments' => [
                anhang(['contentId' => 'logo@x', 'disposition' => 'inline', 'type' => 'image/png', 'filename' => 'logo.png', 'extension' => 'png', 'contents' => 'PNG']),
                anhang(['disposition' => 'attachment', 'type' => 'application/pdf', 'filename' => 'Angebot.pdf', 'extension' => 'pdf', 'contents' => '%PDF']),
            ],
        ]));

        expect($archiv['files'])->toHaveCount(2)
            ->and($archiv['files'][0]['content_id'])->toBe('logo@x')
            ->and($archiv['files'][0]['inline'])->toBeTrue()
            ->and($archiv['files'][0]['contents'])->toBe('PNG')
            ->and($archiv['files'][0]['size'])->toBe(3)
            ->and($archiv['files'][0]['extension'])->toBe('png')
            ->and($archiv['files'][1]['inline'])->toBeFalse()
            ->and($archiv['files'][1]['content_id'])->toBeNull()
            // Die Position ist die im Nachrichtenteil, nicht die in einer
            // gefilterten Liste — dieselbe Nummer, die attachment() annimmt.
            ->and($archiv['files'][1]['index'])->toBe(1);
    });

    it('liest den Inhalt eines Anhangs genau EINMAL', function () {
        // Bug #557: Der Inhalt kommt aus einem Strom, der nach dem ersten
        // Zugriff leer ist. Zweimal zu fragen schrieb die Datei richtig und die
        // Groesse daneben als 0 — 697 Anhaenge im Bestand waren betroffen.
        $datei = anhang(['filename' => 'Angebot.pdf', 'type' => 'application/pdf', 'contents' => '%PDF-1.7']);

        $archiv = (new MessageFormatter)->verbatim(nachricht(['attachments' => [$datei]]));

        expect($datei->gelesen)->toBe(1)
            ->and($archiv['files'][0]['size'])->toBe(8)
            ->and($archiv['files'][0]['contents'])->toBe('%PDF-1.7');
    });
});
