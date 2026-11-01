<?php

namespace Peppermint\Mailbox\Mcp;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Database\Eloquent\Model;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;
use Peppermint\Mailbox\Werkzeuge\BridgeWerkzeugquelle;
use Peppermint\Mailbox\Werkzeuge\GesamtWerkzeuge;
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
 * {@see GesamtWerkzeuge}: Letzteres wuerde
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
            // Was die fragende Seite ueber die offene Mail noch weiss —
            // Ordner und Kennung. Ohne das kommt ein Verb drueben nicht an
            // die Mail selbst heran; siehe {@see \Peppermint\Mailbox\Werkzeuge\VorgangsNachricht}.
            'extra' => $request->get('extra'),
        ]);

        // Eine Feldsuche, kein Auflisten: Beide laufen unter derselben
        // Faehigkeit (`mailbox.actions`), weil Suchen LIEST — siehe
        // {@see \Peppermint\Mailbox\Werkzeuge\LiefertAuswahl}. Unterschieden
        // werden sie am mitgeschickten Feldnamen.
        $feld = trim((string) ($request->get('field') ?? ''));

        if ($feld !== '') {
            return $this->treffer($request, $verzeichnis, $vorgang, $person, $feld);
        }

        if ($person === null || ! $vorgang->vollstaendig()) {
            // Leer und ohne Fehler. Ein Aufruf ohne Person koennte nur
            // „irgendwer" meinen; einer ohne Postfach und Nachricht meint
            // keine bestimmte Mail. Beides ist eine leere Leiste, kein
            // Serverfehler — die fragende Seite hat ihre eigenen Verben.
            return Response::json(['tools' => [], 'failed' => []]);
        }

        // `fuerFremdeSysteme: true` — hier fragt ein anderes System. Verben,
        // die JEDES System selbst mitbringt (Marker {@see \Peppermint\Mailbox\Werkzeuge\NurHier}),
        // bleiben deshalb hier: Sie kaemen sonst vierfach in jeder Leiste an,
        // einmal eigen und dreimal geholt.
        $gesammelt = $verzeichnis->sammeln($vorgang, $person, fuerFremdeSysteme: true);

        return Response::json([
            'tools' => array_map(fn (Werkzeug $w): array => $w->toArray(), $gesammelt['werkzeuge']),
            // Welches eigene Werkzeug gerade NICHT konnte. Ohne diese Angabe
            // sieht die fragende Seite nur eine kuerzere Leiste — und das
            // sieht aus wie „hier gibt es nichts zu tun", nicht wie ein
            // Ausfall.
            'failed' => $gesammelt['failed'],
        ]);
    }

    /**
     * Die Treffer eines suchbaren Feldes — die Gegenstelle zu
     * {@see BridgeWerkzeugquelle::auswaehlen()}.
     *
     * ## Warum das hier steht und nicht in einem eigenen Werkzeug
     *
     * Weil es dieselbe Faehigkeit ist. Ein drittes MCP-Werkzeug brauchte eine
     * dritte Faehigkeit im Register, und das ist eine dritte Stelle, an der
     * ein Produkt sie vergessen kann — das stille Ergebnis waere ein
     * Auswaehler ohne Treffer, und der sieht aus wie „den Kontakt gibt es
     * nicht". Dieselbe Begruendung wie bei der dritten HTTP-Route.
     *
     * ## Immer `options`, auch wenn nichts gefunden wird
     *
     * `Auspacken::auswahl()` packt nur aus, was nach Treffern AUSSIEHT, und
     * wirft eine Verb-Liste weg. Wer hier im Suchfall mit Verben antwortete,
     * waere fuer den Gateway ein Erfolg und fuer den Auswaehler Unsinn —
     * genau der Fall, gegen den `Auspacken` gebaut ist. Also ein Schluessel,
     * der die Art der Antwort nennt, und eine leere Liste als ehrliches
     * „nichts".
     *
     * Die leere Liste ist auch die Antwort auf „keine handelnde Person" und
     * auf einen unvollstaendigen Vorgang. Ein Fehler waere hier eine rote
     * Meldung an einem Feld, in das jemand gerade tippt.
     */
    private function treffer(
        Request $request,
        Werkzeugverzeichnis $verzeichnis,
        Vorgang $vorgang,
        ?Model $person,
        string $feld,
    ): Response {
        if ($person === null || ! $vorgang->vollstaendig()) {
            return Response::json(['options' => []]);
        }

        $treffer = $verzeichnis->auswahl(
            (string) ($request->get('action') ?? ''),
            $feld,
            // Die leere Frage ist der Normalfall: Der Auswaehler fragt beim
            // Aufklappen einmal ohne Text.
            (string) ($request->get('query') ?? ''),
            $vorgang,
            $person,
        );

        return Response::json([
            // Dieselbe Drahtform wie die dritte HTTP-Route: `value`, `label`,
            // `hint`. Die inneren Namen gehen NICHT hinaus — sonst schickt
            // dieses System `wort` und das naechste liest `label`.
            'options' => array_map(static fn (array $eintrag): array => array_filter([
                'value' => $eintrag['wert'],
                'label' => $eintrag['wort'],
                'hint' => $eintrag['hinweis'] ?? null,
            ], static fn ($wert) => $wert !== null), $treffer),
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
            'action' => $schema->string()
                ->description('Only for a field search: the key of the verb whose field is being searched.'),
            'field' => $schema->string()
                ->description('Set this to search one field of one verb instead of listing the verbs. The answer is then {options: [{value, label, hint?}]}.'),
            'query' => $schema->string()
                ->description('What the person typed. Empty is the normal case — the picker asks once on open.'),
            'extra' => $schema->object([
                'folder' => $schema->string()
                    ->description('The folder the open mail is in.'),
                'uid' => $schema->string()
                    ->description('The handle of the open mail in that folder. A claim, not a fact: the answering system re-reads the mail and checks its Message-ID before using it.'),
                'from_name' => $schema->string()
                    ->description('Display name of the sender, descriptive only.'),
            ])->description('What the asking system knows about the open mail beyond its identity. Lets a verb reach the mail itself — the attachment bytes, the body.'),
        ];
    }
}
