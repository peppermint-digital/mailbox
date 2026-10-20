<?php

namespace Peppermint\Mailbox\Mcp;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;
use Peppermint\Mailbox\Werkzeuge\Vorgang;
use Peppermint\Mailbox\Werkzeuge\Werkzeug;
use Peppermint\Mailbox\Werkzeuge\Werkzeugverzeichnis;

/**
 * Die EIGENEN Verben an einer Mail herausgeben.
 *
 * Die Anbieter-Seite der Faehigkeit `mailbox.actions`. Ein anderes System
 * fragt, dieses antwortet mit dem, was es an dieser Mail fuer diese Person
 * tun kann — und ob es die Sache schon gibt.
 *
 * ## Nur die eigenen, nie die geholten
 *
 * Bewusst das {@see Werkzeugverzeichnis} und nicht
 * {@see \Peppermint\Mailbox\Werkzeuge\GesamtWerkzeuge}: Letzteres wuerde
 * seinerseits die anderen Systeme befragen. Vier Systeme, die sich gegenseitig
 * nach ihrer Gesamtleiste fragen, ergeben keine Leiste, sondern eine Schleife.
 *
 * ## Und nie die Herkunft
 *
 * Die Werkzeuge gehen OHNE `system` hinaus. Das Kuerzel vergibt der fragende
 * aus seinem Register — dieses System weiss nicht, unter welchem Namen es
 * drueben gefuehrt wird. Setzte es eines, stuenden in vier Oberflaechen vier
 * verschiedene Woerter, und das Verb liesse sich nicht zustellen.
 *
 * ## Read-only
 *
 * Auflisten legt nichts an. Das Ausloesen ist eine eigene Faehigkeit
 * (`mailbox.actions.run`) — denn es ist ein anderes Recht, und ein System
 * darf seine Verben zeigen, ohne sie von aussen ausloesen zu lassen.
 */
class ListMailboxActionsTool extends Tool
{
    protected string $description = 'List what this system can do with a given email — its own verbs only, read-only. Identify the mail by mailbox address, thread id and message id; the caller merges the answer with its own toolbar.';

    public function handle(Request $request, Werkzeugverzeichnis $verzeichnis): Response
    {
        $person = HandelndePerson::finden();

        $vorgang = Vorgang::aus([
            'mailbox' => (string) ($request->get('mailbox') ?? ''),
            'thread_id' => $request->get('thread_id'),
            'message_id' => (string) ($request->get('message_id') ?? ''),
            'subject' => $request->get('subject'),
            'from' => $request->get('from'),
        ]);

        if ($person === null || ! $vorgang->vollstaendig()) {
            // Leer und ohne Fehler. Ein Aufruf ohne Person koennte nur
            // „irgendwer" meinen; einer ohne Postfach und Nachricht meint
            // keine bestimmte Mail. Beides ist eine leere Leiste, kein
            // Serverfehler — die fragende Seite hat ihre eigenen Verben.
            return Response::json(['tools' => [], 'failed' => []]);
        }

        $gesammelt = $verzeichnis->sammeln($vorgang, $person);

        return Response::json([
            'tools' => array_map(fn (Werkzeug $w): array => $w->toArray(), $gesammelt['werkzeuge']),
            // Welches eigene Werkzeug gerade NICHT konnte. Ohne diese Angabe
            // sieht die fragende Seite nur eine kuerzere Leiste — und das
            // sieht aus wie „hier gibt es nichts zu tun", nicht wie ein
            // Ausfall.
            'failed' => $gesammelt['failed'],
        ]);
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'mailbox' => $schema->string()
                ->description('The mailbox address the mail is in — the address, never an id: the same mailbox has a different number in every system.')
                ->required(),
            'message_id' => $schema->string()
                ->description('RFC 5322 Message-ID of the mail.')
                ->required(),
            'thread_id' => $schema->string()
                ->description('The conversation key, if the caller knows it. A verb attaches to the CONVERSATION — otherwise the reply in the same matter has no ticket again.'),
            'subject' => $schema->string()
                ->description('Subject, so what gets created has a name instead of an id. Descriptive only — never build a link on it.'),
            'from' => $schema->string()
                ->description('Sender, same purpose as the subject.'),
        ];
    }
}
