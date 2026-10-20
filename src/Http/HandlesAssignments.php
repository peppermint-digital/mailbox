<?php

namespace Peppermint\Mailbox\Http;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Peppermint\Mailbox\Contracts\Zuweisungsspeicher;
use Peppermint\Mailbox\Zustand\Person;
use Peppermint\Mailbox\Zustand\Zuweisung;

/**
 * Wer sich um welche Unterhaltung kuemmert.
 *
 * ## Warum das ins Paket gehoert
 *
 * Ein Gruppenpostfach ohne Zuordnung ist ein Raum, in dem alle gleichzeitig
 * anfangen oder alle warten. Bis zum 22.09.2026 konnte das nur der
 * Projekt-Manager — dabei hat jedes Produkt Nutzer, und jedes Produkt hat
 * Gruppenpostfaecher.
 *
 * ## Seit 19.10.2026 ueber einen Speicher-Vertrag
 *
 * Vorher schrieb dieses Merkmal direkt in die Tabelle des Produkts. Das war
 * genau eine Zustaendigkeit JE SYSTEM: Gemessen am 19.10.2026 lagen dieselben
 * Zuweisungen in vier Datenbanken, und wer im CRM zuwies, hatte im Manager
 * niemandem etwas zugewiesen.
 *
 * Jetzt laeuft alles ueber {@see Zuweisungsspeicher} — lokal oder zentral,
 * dasselbe Verhalten. Die Wahl trifft der Dienstanbieter, nicht dieser Weg.
 *
 * ## Was das Produkt beisteuert
 *
 * `assignableUsers()` — WER ueberhaupt in Frage kommt. Das kann das Paket
 * nicht wissen: Es kennt weder die Nutzertabelle noch die Regeln, nach denen
 * jemand ein Postfach sehen darf. Die Liste ist zugleich die Pruefung: Wer
 * nicht darin steht, bekommt nichts zugewiesen.
 *
 * Dazu `mailboxFor()` und `mailboxFailure()` wie bei den uebrigen Aktionen —
 * die stehen in {@see HandlesMailboxActions}, und ein Controller, der beide
 * Merkmale benutzt, deklariert sie einmal.
 *
 * ## Zugewiesen wird die KETTE
 *
 * Sonst ist die naechste Antwort in derselben Sache wieder niemandem
 * zugeordnet, und dieselbe Unterhaltung wird zweimal sortiert. Die
 * Kettenkennung kommt aus den Kopfzeilen, die der Browser mitschickt — so
 * braucht es dafuer keinen zweiten Zugriff aufs Postfach.
 */
trait HandlesAssignments
{
    use BestimmtDieKette;

    /**
     * Die Personen, denen in diesem Postfach etwas zugewiesen werden darf.
     *
     * ## `email` ist seit 19.10.2026 Pflicht
     *
     * Vorher reichten Nummer und Name. Das ging, solange die Zuweisung lokal
     * blieb: Eine Nummer ist in ihrer eigenen Datenbank eindeutig.
     *
     * Ueber Systemgrenzen ist sie es nicht. Jedes Produkt fuehrt seine eigene
     * Nutzertabelle — Nutzer 5 im CRM und Nutzer 5 im Manager sind zwei
     * verschiedene Menschen. Eine Zustaendigkeit, die als Nummer in die Mitte
     * geschrieben wird, steht im naechsten System fuer jemand anders, und das
     * faellt nur am falschen Namen im Abzeichen auf.
     *
     * Diese Liste ist zugleich die Pruefung: Wer nicht darin steht, bekommt
     * nichts zugewiesen. Und wer darin steht, aber keine Adresse hat, kann
     * nicht zugewiesen werden — mit einer Absage, nicht mit einer stillen
     * Nichtzuweisung.
     *
     * @return list<array{id: int, name: string, email: string}>
     */
    abstract protected function assignableUsers(int $account): array;

    /**
     * Wer gerade fragt. Ueblicherweise `auth()->id()`.
     */
    abstract protected function currentUserId(): ?int;

    /**
     * Der Riegel vor JEDEM Weg dieses Merkmals.
     *
     * Standardmaessig offen — die Zuteilung des Postfachs regelt schon, wer es
     * ueberhaupt sieht. Produkte mit strengeren Regeln setzen ihn hier, an
     * EINER Stelle.
     *
     * Dass es ihn gibt, hat einen Anlass: Die Verwaltung verlangt `canEdit()`
     * fuers Lesen des Postfachs, und ihre Pruefung sitzt in `mailboxFor()`.
     * Die Zuweisungs-Wege rufen `mailboxFor()` aber gar nicht auf — sie
     * brauchen kein Postfach, nur den Speicher. Ohne diesen Haken waeren sie
     * an der Schranke vorbei erreichbar gewesen, und zwar lautlos.
     */
    protected function guardAssignments(int $account): void {}

    /**
     * Nach einer Zuweisung. Standardmaessig passiert nichts.
     *
     * `$vorherZustaendig` ist die Person, die es VORHER war — oder `null`.
     * Damit laesst sich unterscheiden, ob jemand neu zustaendig wurde oder ob
     * nur dieselbe Zuweisung bestaetigt wurde. Der Projekt-Manager
     * benachrichtigt nur im ersten Fall; eine Nachricht bei jedem Klick
     * waere nach einer Woche stummgeschaltet.
     *
     * Ohne diesen Haken haette die Umstellung des Managers auf dieses Merkmal
     * seine Benachrichtigungen lautlos abgeschaltet — jemand bekommt Arbeit
     * zugewiesen und erfaehrt nie davon. Genau deshalb gibt es ihn.
     *
     * ## Der Haken bekommt seit 19.10.2026 ein Wertobjekt
     *
     * Vorher war es das Eloquent-Model. Das ging, solange die Zuweisung eine
     * lokale Zeile war; liegt sie in der Mitte, gibt es hier keine Zeile und
     * keinen Schluessel. Ein Haken, der einen braucht — der Manager suchte mit
     * `find($zuweisung->getKey())` seine eigene Zeile —, steigt dann still
     * aus. Also traegt {@see Zuweisung} alles, was zum Benachrichtigen
     * gebraucht wird, und keine Datenbank-Kennung.
     *
     * @param  array<string, mixed>  $daten
     */
    protected function afterAssign(Zuweisung $zuweisung, ?int $vorherZustaendig, array $daten): void {}

    /**
     * Wie eine Zuweisung nach aussen aussieht.
     *
     * Die Oberflaeche aktualisiert damit die Zeile, ohne die ganze Liste neu
     * zu holen. Produkte mit mehr Feldern ueberschreiben das.
     *
     * @return array<string, mixed>
     */
    protected function formatAssignment(Zuweisung $zuweisung): array
    {
        $an = $this->hiesigePerson($zuweisung->konto, $zuweisung->an);

        return [
            'message_id' => $zuweisung->nachricht,
            'thread_id' => $zuweisung->kette,
            'user_id' => $an->nummer,
            // Die Adresse geht mit hinaus. Die Oberflaeche braucht sie, um
            // eine Zustaendigkeit zu zeigen, deren Person es HIER nicht gibt —
            // sonst saehe die Mail frei aus, obwohl jemand an ihr arbeitet.
            'email' => $an->adresse,
            'name' => $an->anzeige(),
            'initials' => $an->initialen(),
        ];
    }

    /**
     * Dieselbe Person, hier benannt.
     *
     * Die beiden Speicher liefern verschiedene Haelften: Der lokale kennt die
     * Nummer, die Mitte die Adresse. Zusammengefuehrt wird hier, weil dies die
     * einzige Stelle ist, die beides kennt — die Personenliste des Produkts.
     *
     * Findet sich niemand, bleibt die Person wie sie war. Sie zu verwerfen
     * hiesse: eine Mail sieht frei aus, obwohl jemand an ihr arbeitet. Das
     * passiert echt — eine Zustaendigkeit in der Mitte kann fuer jemanden
     * stehen, der dieses Postfach hier nicht sehen darf.
     */
    protected function hiesigePerson(int $account, Person $person): Person
    {
        foreach ($this->assignableUsers($account) as $nutzer) {
            $nummer = isset($nutzer['id']) ? (int) $nutzer['id'] : null;
            $adresse = mb_strtolower(trim((string) ($nutzer['email'] ?? '')));

            $trifft = $person->nummer !== null
                ? $nummer === $person->nummer
                : ($adresse !== '' && $adresse === $person->adresse);

            if ($trifft) {
                return new Person(
                    adresse: $adresse !== '' ? $adresse : $person->adresse,
                    nummer: $nummer,
                    name: (string) ($nutzer['name'] ?? ''),
                );
            }
        }

        return $person;
    }

    /**
     * Die Liste fuer die Auswahl.
     */
    public function assignmentUsers(int $account): JsonResponse
    {
        $this->guardAssignments($account);

        return response()->json([
            'users' => array_map(
                static fn (array $nutzer): array => $nutzer + ['initials' => static::initialen($nutzer['name'])],
                $this->assignableUsers($account),
            ),
            // Ohne Adresse ist eine Person nicht systemweit zuweisbar. Die
            // Zahl steht hier, damit eine fehlende Pflege auffaellt, bevor
            // jemand sie als „Zuweisen geht nicht" meldet.
            'without_email' => count(array_filter(
                $this->assignableUsers($account),
                static fn (array $nutzer): bool => trim((string) ($nutzer['email'] ?? '')) === '',
            )),
            // Ein Knopf, der sicher scheitert, ist schlechter als keiner.
            'writable' => $this->zuweisungen()->schreibbar(),
        ]);
    }

    /**
     * Die offenen Zuweisungen dieses Postfachs, nach Kette und Nachricht.
     *
     * Beides, damit die Liste auch Zuweisungen zeigt, die vor der
     * Kettenkennung entstanden sind.
     */
    public function assignments(int $account): JsonResponse
    {
        $this->guardAssignments($account);

        $liste = $this->zuweisungen()->offene($account);

        return response()->json([
            'assignments' => array_map(
                fn (Zuweisung $z): array => $this->formatAssignment($z),
                $liste->zuweisungen,
            ),
            /*
             * „Niemand zustaendig" und „ich konnte nicht nachsehen" sehen in
             * einer leeren Liste gleich aus — und das ist die teuerste
             * Verwechslung dieses Merkmals: Jede Mail saehe frei aus, zwei
             * Leute fingen dieselbe Sache an, und niemand bekaeme eine
             * Meldung. Deshalb steht es in der Antwort.
             */
            'reachable' => $liste->erreichbar,
        ]);
    }

    /**
     * Eine Unterhaltung jemandem zuweisen.
     */
    public function assign(Request $request, int $account): JsonResponse
    {
        $this->guardAssignments($account);

        $daten = $request->validate([
            'message_id' => ['required', 'string', 'max:255'],
            'assigned_to_user_id' => ['required', 'integer'],
            // Die Kopfzeilen der Nachricht: Damit laesst sich die Kette ohne
            // zweiten Postfach-Zugriff bestimmen.
            'in_reply_to' => ['nullable', 'string', 'max:998'],
            'references' => ['nullable', 'string'],
        ]);

        // Die Liste der zulaessigen Personen IST die Pruefung. Eine zweite
        // daneben liefe irgendwann auseinander.
        $an = $this->hiesigePerson($account, new Person(adresse: '', nummer: (int) $daten['assigned_to_user_id']));

        if ($an->nummer !== (int) $daten['assigned_to_user_id']) {
            return response()->json(['message' => 'Diese Person hat keinen Zugriff auf das Postfach.'], 422);
        }

        if ($an->adresse === '') {
            /*
             * Eine Absage, keine stille Nichtzuweisung. Ohne Adresse ist die
             * Person systemweit nicht benennbar — und sie nur hier zu
             * speichern hiesse, dass die Zustaendigkeit im naechsten System
             * nicht existiert, ohne dass jemand es erfaehrt.
             */
            return response()->json([
                'message' => 'Für diese Person ist keine E-Mail-Adresse hinterlegt — ohne sie lässt sich die Zuständigkeit nicht systemweit festhalten.',
            ], 422);
        }

        $kette = $this->threadKeyFor($account, $daten);
        $speicher = $this->zuweisungen();

        // Wer es VORHER war — vor dem Schreiben gelesen, sonst steht dort
        // schon die neue Person und der Vergleich unten geht immer aus.
        $vorher = $speicher->fuer($account, $daten['message_id'], $kette)?->an;
        $vorherNummer = $vorher === null ? null : $this->hiesigePerson($account, $vorher)->nummer;

        $zuweisung = $speicher->zuweisen(
            $account,
            (string) $daten['message_id'],
            $kette,
            $an,
            $this->zuweisendePerson($account),
        );

        if ($zuweisung === null) {
            /*
             * Nicht geschrieben, also nicht gemeldet. Eine Zuweisung, die als
             * gelungen erscheint und nirgends steht, ist schlimmer als ein
             * Fehler: Der Zuweisende glaubt, die Sache ist vergeben, und
             * niemand sieht sie je wieder an.
             */
            return response()->json([
                'message' => 'Die Zuständigkeit konnte nicht gespeichert werden. Bitte später erneut versuchen.',
            ], 503);
        }

        $this->afterAssign($zuweisung, $vorherNummer, $daten);

        return response()->json([
            'success' => true,
            'assignment' => $this->formatAssignment($zuweisung),
        ]);
    }

    /**
     * Die Zuweisung aufheben.
     */
    public function unassign(Request $request, int $account): JsonResponse
    {
        $this->guardAssignments($account);

        $daten = $request->validate([
            'message_id' => ['required', 'string', 'max:255'],
            'in_reply_to' => ['nullable', 'string', 'max:998'],
            'references' => ['nullable', 'string'],
        ]);

        $gelungen = $this->zuweisungen()->aufheben(
            $account,
            (string) $daten['message_id'],
            $this->threadKeyFor($account, $daten),
        );

        if (! $gelungen) {
            // Dieselbe Begruendung wie beim Zuweisen, nur in die andere
            // Richtung: Wer sie abgegeben glaubt, sieht nicht mehr hin.
            return response()->json([
                'message' => 'Die Zuständigkeit konnte nicht aufgehoben werden. Bitte später erneut versuchen.',
            ], 503);
        }

        return response()->json(['success' => true]);
    }

    /**
     * Wer zuweist — als Person, nicht als Nummer.
     *
     * `null`, wenn die handelnde Person keine Adresse hat. Das ist zulaessig:
     * `assigned_by` ist eine Angabe fuer Menschen, kein Schluessel. Wer sie
     * nicht liefern kann, soll trotzdem zuweisen koennen — anders als beim
     * Empfaenger, wo die Adresse die Aussage selbst traegt.
     */
    protected function zuweisendePerson(int $account): ?Person
    {
        $nummer = $this->currentUserId();

        if ($nummer === null) {
            return null;
        }

        $person = $this->hiesigePerson($account, new Person(adresse: '', nummer: $nummer));

        return $person->adresse === '' ? null : $person;
    }

    /**
     * Der Speicher.
     *
     * Ueber den Container, nicht als Konstruktor-Abhaengigkeit: Vier Produkte
     * fuehren Controller mit diesem Merkmal, und drei davon haben ihren
     * Konstruktor aus anderen Gruenden. Ein Merkmal, das einen erzwingt, ist
     * eines, das man nicht dazunehmen kann.
     */
    protected function zuweisungen(): Zuweisungsspeicher
    {
        return app(Zuweisungsspeicher::class);
    }

    /**
     * „Anna Meier" wird zu „AM".
     */
    protected static function initialen(string $name): string
    {
        $teile = preg_split('/\s+/', trim($name)) ?: [];
        $teile = array_values(array_filter($teile));

        if ($teile === []) {
            return '?';
        }

        $erste = mb_substr($teile[0], 0, 1);
        $letzte = count($teile) > 1 ? mb_substr($teile[count($teile) - 1], 0, 1) : '';

        return mb_strtoupper($erste.$letzte);
    }
}
