<?php

namespace Peppermint\Mailbox\Http;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Peppermint\Mailbox\Accounts\OAuthErneuerung;
use Peppermint\Mailbox\Accounts\OAuthVerbindung;
use Peppermint\Mailbox\Contracts\AccountStore;
use Peppermint\Mailbox\Mailboxes;
use Peppermint\Mailbox\Mcp\HandelndePerson;
use Peppermint\Mailbox\Models\MailAccount;
use Peppermint\Mailbox\Sending\Mailer;
use RuntimeException;

/**
 * Ein Postfach anlegen oder (neu) verbinden — fuer ein Produkt, das seine
 * Postfaecher selbst fuehrt (`MAILBOX_STORE=local`).
 *
 * Die Route gehoert dem Produkt, die Regeln dem Paket, die Maske auch
 * (`PostfachAnlegen`). Vorschlag:
 *
 *     POST {basis}                     kontoAnlegen      Kennwort-Postfach
 *     POST {basis}/pruefen             kontoPruefen      Verbindungstest
 *     GET  {basis}/oauth/callback      kontoRueckweg     (vor der naechsten!)
 *     GET  {basis}/oauth/{anbieter}    kontoVerbinden    Microsoft, Google
 *
 * ## Wer darf was
 *
 * Jede angemeldete Person legt ihr EIGENES Postfach an. Ein Gruppenpostfach
 * (ohne Besitzer) und seine Leseliste nur, wer {@see kontoDarfTeilen()} —
 * das sagt das Produkt; ohne Angabe niemand.
 *
 * ## Nie eine zweite Zeile fuer dasselbe Postfach
 *
 * Am 30.10.2026 hat ein „Neu verbinden" im Projekt-Manager eine Zeile #27 fuer
 * ein Postfach angelegt, das schon die #9 trug: Der damalige Rueckweg machte
 * ausnahmslos `create()`. Das Postfach ging danach nirgends, und die Maske
 * meldete Erfolg. Deshalb hier:
 *
 * - Neu verbinden traegt die Kennung im `state` und aendert genau diese
 *   Zeile — und nur, wenn die Anmeldung zur Adresse des Postfachs passt.
 * - Ohne Kennung wird eine vorhandene Zeile mit derselben Adresse
 *   aktualisiert, nicht verdoppelt.
 * - Das Kennwort-Formular lehnt eine vorhandene Adresse ab.
 *
 * ## Nur, wo es etwas zu schreiben gibt
 *
 * Liest ein Produkt seine Postfaecher aus AI Brain (`store=brain`), entsteht
 * ein Postfach dort. Ein Anlegen hier gaebe eine Zeile ohne Gegenstueck —
 * abgelehnt mit 409.
 */
trait LegtKontenAn
{
    /**
     * Verbindung pruefen, ohne zu speichern.
     */
    public function kontoPruefen(Request $request): JsonResponse
    {
        $this->kontoPerson();
        $daten = $this->kontoDaten($request);

        return response()->json($this->kontoVerbindungPruefen($this->kontoEntwurf($daten)));
    }

    /**
     * Ein Kennwort-Postfach anlegen — erst nach bestandenem Verbindungstest.
     */
    public function kontoAnlegen(Request $request): JsonResponse
    {
        $person = $this->kontoPerson();
        $this->kontoSchreibbar();

        $daten = $this->kontoDaten($request);
        $geteilt = $this->kontoTeilenErlaubt($person, $daten);

        if ($this->kontoMitAdresse($daten['email']) !== null) {
            throw ValidationException::withMessages([
                'email' => 'Dieses Postfach gibt es hier schon. Bitte das vorhandene bearbeiten.',
            ]);
        }

        $entwurf = $this->kontoEntwurf($daten);
        $pruefung = $this->kontoVerbindungPruefen($entwurf);

        if (! $pruefung['ok']) {
            throw ValidationException::withMessages([
                'verbindung' => $pruefung['message'],
            ]);
        }

        $konto = DB::transaction(function () use ($entwurf, $geteilt, $person, $daten): MailAccount {
            $entwurf->setAttribute(MailAccount::column('user_id'), $geteilt ? null : $person->getKey());
            $entwurf->save();

            if ($geteilt) {
                $this->kontoLeserSetzen($entwurf, $daten['leser']);
            }

            return $entwurf;
        });

        $this->kontoAngelegt($konto, true, $request);

        return response()->json(['account' => [
            'id' => $konto->getKey(),
            'email' => (string) $konto->field('email'),
            'label' => (string) ($konto->field('label') ?: $konto->field('email')),
        ]], 201);
    }

    /**
     * Zur Anmeldung beim Anbieter schicken.
     *
     * Abfrage: `konto` (Neu verbinden), `name`, `geteilt`.
     */
    public function kontoVerbinden(Request $request, string $anbieter): RedirectResponse
    {
        $person = $this->kontoPerson();
        $this->kontoSchreibbar();

        // Die Produkte nennen den Anbieter verschieden (`office365`,
        // `outlook`, `gmail`) — der Knopf „Neu verbinden" reicht weiter, was
        // am Konto steht.
        $anbieter = match (mb_strtolower($anbieter)) {
            'office365', 'outlook', 'microsoft365', 'azure' => 'microsoft',
            'gmail', 'googlemail' => 'google',
            default => mb_strtolower($anbieter),
        };

        if (! OAuthVerbindung::istVerfuegbar($anbieter)) {
            return $this->kontoZurueck('error', 'Fuer diesen Anbieter ist keine Anmeldung eingerichtet.');
        }

        $kennung = $request->integer('konto') ?: null;
        $hinweis = null;

        if ($kennung !== null) {
            $konto = MailAccount::query()->find($kennung);

            if ($konto === null || ! $this->kontoDarfBearbeiten($person, $konto)) {
                abort(404);
            }

            $hinweis = (string) $konto->field('email');
        }

        $geteilt = $kennung === null && $request->boolean('geteilt');

        if ($geteilt && ! $this->kontoDarfTeilen($person)) {
            abort(403, 'Ein Gruppenpostfach darf hier nur die Verwaltung anlegen.');
        }

        $state = Str::random(40);

        $request->session()->put('mailbox.oauth', [
            'state' => $state,
            'anbieter' => $anbieter,
            'konto' => $kennung,
            'name' => Str::limit(trim((string) $request->input('name', '')), 250, ''),
            'geteilt' => $geteilt,
            'person' => $person->getKey(),
        ]);

        return redirect()->away(app(OAuthVerbindung::class)->adresse(
            $anbieter,
            $state,
            $this->kontoRueckwegAdresse(),
            $hinweis,
        ));
    }

    /**
     * Zurueck vom Anbieter: Tokens holen und genau EINE Zeile schreiben.
     */
    public function kontoRueckweg(Request $request): RedirectResponse
    {
        $person = $this->kontoPerson();
        $vorgang = (array) $request->session()->pull('mailbox.oauth', []);

        if (($vorgang['state'] ?? null) === null
            || ! hash_equals((string) $vorgang['state'], (string) $request->query('state', ''))
            || (string) ($vorgang['person'] ?? '') !== (string) $person->getKey()) {
            return $this->kontoZurueck('error', 'Die Anmeldung passt nicht zu dieser Sitzung. Bitte noch einmal verbinden.');
        }

        if ($request->filled('error')) {
            return $this->kontoZurueck('error', 'Die Anmeldung wurde abgebrochen.');
        }

        $code = (string) $request->query('code', '');

        if ($code === '') {
            return $this->kontoZurueck('error', 'Der Anbieter hat keinen Anmeldecode geschickt.');
        }

        $anbieter = (string) $vorgang['anbieter'];

        try {
            $tokens = app(OAuthVerbindung::class)->tauschen($anbieter, $code, $this->kontoRueckwegAdresse());
        } catch (RuntimeException $e) {
            Log::warning('Postfach-Anmeldung gescheitert', ['anbieter' => $anbieter, 'grund' => $e->getMessage()]);

            return $this->kontoZurueck('error', $e->getMessage());
        }

        $kennung = $vorgang['konto'] ?? null;
        $konto = $kennung !== null
            ? MailAccount::query()->find($kennung)
            : $this->kontoMitAdresse($tokens['email']);

        if ($kennung !== null && $konto === null) {
            return $this->kontoZurueck('error', 'Dieses Postfach gibt es nicht mehr.');
        }

        if ($konto !== null && ! $this->kontoDarfBearbeiten($person, $konto)) {
            return $this->kontoZurueck('error', 'Dieses Postfach ist hier schon verbunden, aber nicht bei Ihnen. Bitte an die Verwaltung wenden.');
        }

        if ($konto !== null && mb_strtolower((string) $konto->field('email')) !== mb_strtolower($tokens['email'])) {
            // Wer beim Anbieter ein ANDERES Konto waehlt, haette sonst die
            // Anmeldung eines fremden Postfachs unter dieser Adresse liegen.
            return $this->kontoZurueck('error', sprintf(
                'Angemeldet wurde %s, das Postfach ist aber %s. Bitte mit dem richtigen Konto anmelden.',
                $tokens['email'],
                (string) $konto->field('email'),
            ));
        }

        $neu = $konto === null;
        $konto ??= new MailAccount;

        $werte = [
            MailAccount::column('auth_type') => 'oauth',
            MailAccount::column('oauth_access_token') => $tokens['access_token'],
            MailAccount::column('oauth_refresh_token') => $tokens['refresh_token'],
            MailAccount::column('oauth_token_expires_at') => now()->addSeconds($tokens['expires_in']),
            // Die App, die dieses Token geholt hat, ist die der Anwendung —
            // eine am Konto stehende fremde App (z. B. aus AI Brain
            // uebernommen) wuerde es bei der Erneuerung entwerten.
            MailAccount::column('oauth_client_id') => null,
            MailAccount::column('oauth_client_secret') => null,
            MailAccount::column('oauth_tenant_id') => null,
            $this->kontoAnbieterSpalte() => $anbieter,
            MailAccount::column('is_active') => true,
        ];

        if ($neu) {
            $standard = $anbieter === 'google'
                ? ['imap.gmail.com', 993, 'smtp.gmail.com', 587]
                : ['outlook.office365.com', 993, 'smtp.office365.com', 587];

            $werte += [
                MailAccount::column('email') => $tokens['email'],
                MailAccount::column('label') => ($vorgang['name'] ?? '') !== '' ? $vorgang['name'] : ($tokens['name'] ?: $tokens['email']),
                MailAccount::column('imap_host') => $standard[0],
                MailAccount::column('imap_port') => $standard[1],
                MailAccount::column('imap_encryption') => 'ssl',
                MailAccount::column('smtp_host') => $standard[2],
                MailAccount::column('smtp_port') => $standard[3],
                MailAccount::column('smtp_encryption') => 'tls',
                MailAccount::column('username') => $tokens['email'],
                MailAccount::column('user_id') => ! empty($vorgang['geteilt']) ? null : $person->getKey(),
            ];
        }

        $konto->forceFill($this->kontoNurVorhandeneSpalten($werte))->save();

        $this->kontoAngelegt($konto, $neu, $request);

        return $this->kontoZurueck('success', $neu
            ? 'Postfach '.$tokens['email'].' verbunden.'
            : 'Postfach '.$tokens['email'].' neu verbunden.');
    }

    /*
    |--------------------------------------------------------------------------
    | Was das Produkt sagt
    |--------------------------------------------------------------------------
    */

    /**
     * Wer handelt. Ohne erkennbare Person gibt es nichts anzulegen.
     */
    protected function kontoPerson(): Model
    {
        $person = HandelndePerson::finden();

        abort_if($person === null, 403);

        return $person;
    }

    /**
     * Darf diese Person Gruppenpostfaecher anlegen und Leselisten setzen?
     */
    protected function kontoDarfTeilen(Model $person): bool
    {
        return false;
    }

    /**
     * Darf diese Person dieses Postfach neu verbinden? Das eigene immer,
     * sonst nur, wer teilen darf.
     */
    protected function kontoDarfBearbeiten(Model $person, MailAccount $konto): bool
    {
        $besitzer = $konto->getAttribute(MailAccount::column('user_id'));

        if ($besitzer !== null && (string) $besitzer === (string) $person->getKey()) {
            return true;
        }

        return $this->kontoDarfTeilen($person);
    }

    /**
     * Die beim Anbieter eingetragene Rueckweg-Adresse.
     */
    protected function kontoRueckwegAdresse(): string
    {
        $adresse = (string) config('mailbox.oauth.redirect_uri', '');

        if ($adresse === '') {
            throw new RuntimeException('mailbox.oauth.redirect_uri ist nicht gesetzt — ohne sie kann der Anbieter nicht zurueckschicken.');
        }

        return $adresse;
    }

    /**
     * Wohin es nach dem Anbieter zurueckgeht.
     */
    protected function kontoZiel(): string
    {
        return url('/');
    }

    /**
     * Nach dem Anlegen oder Neu-Verbinden — fuer das, was nur das Produkt
     * kennt (Standardordner, Gesendet-Kopie, Benachrichtigungen).
     */
    protected function kontoAngelegt(MailAccount $konto, bool $neu, Request $request): void {}

    /**
     * Liest und schreibt. Austauschbar fuer einen Test ohne Server —
     * {@see Mailboxes::fake()} und {@see Mailer::fake()} greifen auch hier.
     *
     * @return array{ok: bool, message: string, lesen: array{ok: bool, message: string}, senden: array{ok: bool, message: string}|null}
     */
    protected function kontoVerbindungPruefen(MailAccount $konto): array
    {
        $lesen = Mailboxes::for($konto)->probe();
        $senden = null;

        if ((string) $konto->field('smtp_host') !== '') {
            try {
                $dauer = (new Mailer)->probeLogin($konto);
                $senden = ['ok' => true, 'message' => "Anmeldung am Postausgang steht ({$dauer} ms)."];
            } catch (\Throwable $e) {
                $senden = ['ok' => false, 'message' => $e->getMessage()];
            }
        }

        $ok = $lesen['ok'] && ($senden === null || $senden['ok']);

        return [
            'ok' => $ok,
            'message' => $ok
                ? $lesen['message']
                : ($lesen['ok'] ? 'Postausgang: '.$senden['message'] : 'Posteingang: '.$lesen['message']),
            'lesen' => $lesen,
            'senden' => $senden,
        ];
    }

    /*
    |--------------------------------------------------------------------------
    | Innen
    |--------------------------------------------------------------------------
    */

    private function kontoSchreibbar(): void
    {
        if (config('mailbox.store') === 'brain' || ! app(AccountStore::class)->isWritable()) {
            abort(409, 'Postfaecher werden in AI Brain verbunden, nicht hier.');
        }
    }

    /**
     * @return array{name: string, email: string, protocol: string, host: string, port: int, encryption: string, username: ?string, password: string, jmap_url: ?string, smtp_host: ?string, smtp_port: ?int, smtp_encryption: ?string, geteilt: bool, leser: list<int>}
     */
    private function kontoDaten(Request $request): array
    {
        $verschluesselung = ['ssl', 'tls', 'starttls', 'none'];

        $daten = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255'],
            'protocol' => ['nullable', Rule::in(['imap', 'jmap'])],
            'host' => ['required', 'string', 'max:255'],
            'port' => ['required', 'integer', 'min:1', 'max:65535'],
            'encryption' => ['required', Rule::in($verschluesselung)],
            'username' => ['nullable', 'string', 'max:255'],
            'password' => ['required', 'string'],
            'jmap_url' => ['nullable', 'url', 'max:255'],
            'smtp_host' => ['nullable', 'string', 'max:255'],
            'smtp_port' => ['nullable', 'required_with:smtp_host', 'integer', 'min:1', 'max:65535'],
            'smtp_encryption' => ['nullable', Rule::in($verschluesselung)],
            'geteilt' => ['sometimes', 'boolean'],
            'leser' => ['sometimes', 'array'],
            'leser.*' => ['integer'],
        ], [
            'name.required' => 'Bitte einen Namen angeben.',
            'email.required' => 'Bitte die Adresse des Postfachs angeben.',
            'email.email' => 'Das ist keine E-Mail-Adresse.',
            'host.required' => 'Bitte den Server angeben.',
            'port.required' => 'Bitte den Port angeben.',
            'password.required' => 'Bitte das Kennwort angeben.',
            'smtp_port.required_with' => 'Zum Postausgang gehoert ein Port.',
        ]);

        return [
            'name' => trim($daten['name']),
            'email' => trim($daten['email']),
            'protocol' => $daten['protocol'] ?? 'imap',
            'host' => trim($daten['host']),
            'port' => (int) $daten['port'],
            'encryption' => $daten['encryption'],
            'username' => isset($daten['username']) && trim($daten['username']) !== '' ? trim($daten['username']) : null,
            'password' => $daten['password'],
            'jmap_url' => $daten['jmap_url'] ?? null,
            'smtp_host' => isset($daten['smtp_host']) && trim($daten['smtp_host']) !== '' ? trim($daten['smtp_host']) : null,
            'smtp_port' => isset($daten['smtp_port']) ? (int) $daten['smtp_port'] : null,
            'smtp_encryption' => $daten['smtp_encryption'] ?? null,
            'geteilt' => (bool) ($daten['geteilt'] ?? false),
            'leser' => array_values(array_unique(array_map('intval', $daten['leser'] ?? []))),
        ];
    }

    /**
     * Gruppenpostfach und Leseliste nur mit Recht — ein stilles Ueberhoeren
     * legte sonst ein persoenliches Postfach an, wo ein geteiltes gemeint war.
     */
    private function kontoTeilenErlaubt(Model $person, array $daten): bool
    {
        if (($daten['geteilt'] || $daten['leser'] !== []) && ! $this->kontoDarfTeilen($person)) {
            abort(403, 'Ein Gruppenpostfach darf hier nur die Verwaltung anlegen.');
        }

        if (! $daten['geteilt'] && $daten['leser'] !== []) {
            throw ValidationException::withMessages([
                'leser' => 'Eine Leseliste gibt es nur fuer ein Gruppenpostfach.',
            ]);
        }

        return $daten['geteilt'];
    }

    /**
     * Ein ungespeichertes Konto aus den Formularwerten.
     */
    private function kontoEntwurf(array $daten): MailAccount
    {
        $konto = new MailAccount;

        $konto->forceFill($this->kontoNurVorhandeneSpalten([
            MailAccount::column('label') => $daten['name'],
            MailAccount::column('email') => $daten['email'],
            MailAccount::column('protocol') => $daten['protocol'],
            MailAccount::column('jmap_url') => $daten['jmap_url'],
            MailAccount::column('imap_host') => $daten['host'],
            MailAccount::column('imap_port') => $daten['port'],
            MailAccount::column('imap_encryption') => $daten['encryption'],
            MailAccount::column('auth_type') => 'password',
            MailAccount::column('username') => $daten['username'],
            MailAccount::column('password') => $daten['password'],
            MailAccount::column('smtp_host') => $daten['smtp_host'],
            MailAccount::column('smtp_port') => $daten['smtp_port'],
            MailAccount::column('smtp_encryption') => $daten['smtp_encryption'],
            MailAccount::column('is_active') => true,
        ]));

        return $konto;
    }

    private function kontoMitAdresse(string $adresse): ?MailAccount
    {
        $spalte = MailAccount::column('email');

        return MailAccount::query()
            ->whereRaw('LOWER('.$spalte.') = ?', [mb_strtolower(trim($adresse))])
            ->orderBy((new MailAccount)->getKeyName())
            ->first();
    }

    /**
     * @param  list<int>  $leser
     */
    private function kontoLeserSetzen(MailAccount $konto, array $leser): void
    {
        $liste = MailAccount::sharingTable();

        if ($liste === null || $leser === []) {
            return;
        }

        [$kontoSpalte, $nutzerSpalte] = MailAccount::sharingKeys();

        DB::table($liste)->insert(array_map(fn (int $id): array => [
            $kontoSpalte => $konto->getKey(),
            $nutzerSpalte => $id,
        ], $leser));
    }

    /**
     * Wo der Anbieter steht. Die Produkte nennen es verschieden: Das Paket
     * kennt `provider`, der Projekt-Manager `oauth_provider` (`provider`
     * heisst dort „welche Art Postfach"). Gelesen wird beides
     * ({@see OAuthErneuerung}), geschrieben die
     * Spalte, die es gibt.
     */
    private function kontoAnbieterSpalte(): string
    {
        return $this->kontoHatSpalte('oauth_provider') ? 'oauth_provider' : MailAccount::column('provider');
    }

    /**
     * Laesst weg, was die Tabelle nicht hat — eine gewachsene Tabelle kennt
     * nicht jede Paketspalte (`protocol`, `jmap_url`).
     *
     * @param  array<string, mixed>  $werte
     * @return array<string, mixed>
     */
    private function kontoNurVorhandeneSpalten(array $werte): array
    {
        $vorhanden = Schema::getColumnListing((new MailAccount)->getTable());

        return array_filter(
            $werte,
            fn (string $spalte): bool => in_array($spalte, $vorhanden, true),
            ARRAY_FILTER_USE_KEY,
        );
    }

    private function kontoHatSpalte(string $spalte): bool
    {
        return Schema::hasColumn((new MailAccount)->getTable(), $spalte);
    }

    private function kontoZurueck(string $art, string $text): RedirectResponse
    {
        return redirect()->to($this->kontoZiel())->with($art, $text);
    }
}
