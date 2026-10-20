<?php

namespace Peppermint\Mailbox\Mcp;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;
use Peppermint\Mailbox\Werkzeuge\Vorgang;
use Peppermint\Mailbox\Werkzeuge\Werkzeugverzeichnis;

/**
 * Ein eigenes Verb an einer Mail ausloesen — beauftragt von einem anderen
 * System.
 *
 * Die Anbieter-Seite der Faehigkeit `mailbox.actions.run`.
 *
 * ## Getrennt von der Liste, weil es ein anderes Recht ist
 *
 * Lesen, welche Verben es gibt, ist harmlos; eines ausloesen legt etwas an.
 * Ein System, das nur `mailbox.actions` freigibt, zeigt seine Verben, laesst
 * sie aber nicht von aussen ausloesen — eine zulaessige Haltung, keine
 * Fehlkonfiguration.
 *
 * ## Die Erlaubnis wird HIER geprueft, nicht drueben
 *
 * Das Werkzeug selbst prueft die Zulaessigkeit in `ausfuehren()`, jedes Mal.
 * Die fragende Seite hat das Verb aus einer Liste, die durch den Verbund
 * gereist ist — eine Behauptung, keine Erlaubnis.
 *
 * Zweimal hat dieses Projekt fremde Posteingaenge geoeffnet, weil eine
 * Pruefung uebernommen statt gestellt wurde (Memories #4003, #4009). Hier
 * waere derselbe Fehler teurer: Er legte etwas in der Buchhaltung an.
 *
 * ## Kein `system` in der Nutzlast
 *
 * Der Schluessel kommt nackt an. Drueben ist er im eigenen Haus eindeutig,
 * und das Kuerzel ist eine Sicht von aussen — muesste jedes System wissen,
 * wie es anderswo heisst, waere die Zustellung an die Benennung gekoppelt.
 */
class RunMailboxActionTool extends Tool
{
    protected string $description = 'Run one of this system\'s own verbs on a given email — create the ticket, book the receipt, attach the task. Identify the mail by mailbox address, thread id and message id. Answers ok/message/state; a refused action is ok:false and not an error.';

    public function handle(Request $request, Werkzeugverzeichnis $verzeichnis): Response
    {
        $person = HandelndePerson::finden();

        if ($person === null) {
            // Fail-closed, und hier strenger als beim Auflisten: Ein Vorgang
            // ohne handelnde Person waere einer, den niemand verantwortet.
            return Response::json([
                'ok' => false,
                'message' => 'Ohne handelnde Person wird nichts ausgeloest.',
            ]);
        }

        $vorgang = Vorgang::aus([
            'mailbox' => (string) ($request->get('mailbox') ?? ''),
            'thread_id' => $request->get('thread_id'),
            'message_id' => (string) ($request->get('message_id') ?? ''),
            'subject' => $request->get('subject'),
            'from' => $request->get('from'),
        ]);

        if (! $vorgang->vollstaendig()) {
            return Response::json([
                'ok' => false,
                'message' => 'Zu dieser Nachricht fehlen die Angaben, mit denen sie zu finden ist.',
            ]);
        }

        $eingabe = $request->get('input');

        $ergebnis = $verzeichnis->ausfuehren(
            (string) ($request->get('action') ?? ''),
            $vorgang,
            is_array($eingabe) ? $eingabe : [],
            $person,
        );

        // Immer eine Antwort, nie eine Ausnahme: Ein Werkzeug, das nicht
        // konnte, ist ein normaler Ausgang. Als Fehler zurueckgegeben waere
        // es drueben „System nicht erreichbar" — und dann sucht man den
        // Grund im Netz statt im fehlenden Belegdatum.
        return Response::json($ergebnis->toArray());
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'action' => $schema->string()
                ->description('Key of the verb, as this system reported it in list-mailbox-actions-tool. Without a system prefix — it is unique within this system.')
                ->required(),
            'mailbox' => $schema->string()
                ->description('The mailbox address the mail is in — the address, never an id.')
                ->required(),
            'message_id' => $schema->string()
                ->description('RFC 5322 Message-ID of the mail.')
                ->required(),
            'thread_id' => $schema->string()
                ->description('The conversation key. A verb attaches to the CONVERSATION where one is known.'),
            'subject' => $schema->string()
                ->description('Subject, so what gets created has a name.'),
            'from' => $schema->string()
                ->description('Sender, same purpose as the subject.'),
            'input' => $schema->object()
                ->description('What the verb\'s own fields collected — whatever this system asked for.'),
        ];
    }
}
