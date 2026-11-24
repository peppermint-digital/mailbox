<?php

namespace Peppermint\Mailbox\Http;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Peppermint\Mailbox\Accounts\SichtbareKonten;
use Peppermint\Mailbox\Mcp\HandelndePerson;
use Peppermint\Mailbox\Models\MailAccount;
use Peppermint\Mailbox\Models\MailSignature;

/**
 * Die eigenen Signaturen pflegen — die Gegenseite zu {@see LiefertSignaturen}.
 *
 * Fuer ein Produkt, das seine Signaturen selbst fuehrt (`LokalerSignaturvorrat`).
 * Die Route gehoert dem Produkt, die Regeln dem Paket. Vorschlag:
 *
 *     GET    {basis}/signaturen            signaturListe
 *     POST   {basis}/signaturen            signaturAnlegen
 *     PATCH  {basis}/signaturen/{id}       signaturAendern
 *     DELETE {basis}/signaturen/{id}       signaturLoeschen
 *
 * ## Jede Person pflegt nur ihre eigenen
 *
 * Eine fremde Signatur antwortet mit 404, nicht mit 403: Dass es sie gibt,
 * geht niemanden etwas an. Wer fuer ein Teampostfach eine Unterschrift
 * braucht, legt sie fuer sich an diesem Postfach an.
 *
 * ## Ein Postfach nur, wenn die Person es sehen darf
 *
 * Gefragt wird {@see SichtbareKonten} — dieselbe Antwort, die die Leiste
 * gibt. Eine Signatur, deren Postfach inzwischen unsichtbar ist, bleibt
 * bearbeitbar, solange das Postfach nicht geaendert wird; neu daran binden
 * kann man sie nicht.
 *
 * ## Eine Vorgabe je Bereich
 *
 * Wird eine Signatur zur Vorgabe, verliert jede andere derselben Person im
 * selben Bereich (dasselbe Postfach oder „alle") die Markierung. Zwei
 * Vorgaben waeren kein Fehler, aber die Verfassen-Seite naehme stumm die
 * erste — die Maske soll zeigen, was gilt.
 */
trait PflegtSignaturen
{
    public function signaturListe(): JsonResponse
    {
        $person = $this->signaturPerson();
        $konten = $this->signaturKonten($person);

        $zeilen = MailSignature::query()
            ->where('user_id', $person->getKey())
            ->orderByRaw('mail_account_id IS NULL DESC')
            ->orderBy('mail_account_id')
            ->orderByDesc('is_default')
            ->orderBy('name')
            ->get();

        return response()->json([
            'signatures' => $zeilen->map(fn (MailSignature $zeile): array => $this->signaturZeile($zeile))->values()->all(),
            'accounts' => $konten->map(fn (MailAccount $konto): array => [
                'id' => $konto->field('id'),
                'email' => (string) $konto->field('email'),
                'label' => (string) ($konto->field('label') ?: $konto->field('email')),
            ])->values()->all(),
        ]);
    }

    public function signaturAnlegen(Request $request): JsonResponse
    {
        $person = $this->signaturPerson();
        $daten = $this->signaturDaten($request, $person, null);

        $zeile = DB::transaction(function () use ($person, $daten): MailSignature {
            $zeile = MailSignature::create([...$daten, 'user_id' => $person->getKey()]);
            $this->signaturVorgabeAllein($zeile);

            return $zeile;
        });

        return response()->json(['signature' => $this->signaturZeile($zeile->refresh())], 201);
    }

    public function signaturAendern(Request $request, int $signatur): JsonResponse
    {
        $person = $this->signaturPerson();
        $zeile = $this->eigeneSignatur($person, $signatur);
        $daten = $this->signaturDaten($request, $person, $zeile);

        DB::transaction(function () use ($zeile, $daten): void {
            $zeile->update($daten);
            $this->signaturVorgabeAllein($zeile);
        });

        return response()->json(['signature' => $this->signaturZeile($zeile->refresh())]);
    }

    public function signaturLoeschen(int $signatur): JsonResponse
    {
        $this->eigeneSignatur($this->signaturPerson(), $signatur)->delete();

        return response()->json(['deleted' => true]);
    }

    /**
     * Wer pflegt. Ohne erkennbare Person gibt es nichts zu pflegen.
     */
    protected function signaturPerson(): Model
    {
        $person = HandelndePerson::finden();

        abort_if($person === null, 403);

        return $person;
    }

    /**
     * @return Collection<int, MailAccount>
     */
    private function signaturKonten(Model $person): Collection
    {
        return app(SichtbareKonten::class)->fuer($this->signaturAdresse($person));
    }

    private function signaturAdresse(Model $person): ?string
    {
        $adresse = $person->getAttribute('email');

        return is_string($adresse) && trim($adresse) !== '' ? trim($adresse) : null;
    }

    private function eigeneSignatur(Model $person, int $signatur): MailSignature
    {
        return MailSignature::query()
            ->where('user_id', $person->getKey())
            ->findOrFail($signatur);
    }

    /**
     * @return array{name: string, body_html: string, is_default: bool, mail_account_id: int|null}
     */
    private function signaturDaten(Request $request, Model $person, ?MailSignature $vorher): array
    {
        $daten = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'body_html' => ['required', 'string', 'max:65000'],
            'is_default' => ['sometimes', 'boolean'],
            'mail_account_id' => ['nullable', 'integer'],
        ], [
            'name.required' => 'Bitte einen Namen angeben.',
            'body_html.required' => 'Die Signatur ist leer.',
        ]);

        $konto = isset($daten['mail_account_id']) ? (int) $daten['mail_account_id'] : null;

        // Ein unveraendertes Postfach darf bleiben, auch wenn es inzwischen
        // unsichtbar ist — sonst liesse sich die Signatur nie mehr umbenennen.
        $unveraendert = $vorher !== null && $konto === ($vorher->mail_account_id === null ? null : (int) $vorher->mail_account_id);

        if ($konto !== null && ! $unveraendert
            && app(SichtbareKonten::class)->eines($konto, $this->signaturAdresse($person)) === null) {
            throw ValidationException::withMessages([
                'mail_account_id' => 'Dieses Postfach steht nicht zur Wahl.',
            ]);
        }

        if (trim(strip_tags($daten['body_html'], '<img>')) === '') {
            throw ValidationException::withMessages(['body_html' => 'Die Signatur ist leer.']);
        }

        return [
            'name' => trim($daten['name']),
            'body_html' => $daten['body_html'],
            'is_default' => (bool) ($daten['is_default'] ?? false),
            'mail_account_id' => $konto,
        ];
    }

    private function signaturVorgabeAllein(MailSignature $zeile): void
    {
        if (! $zeile->is_default) {
            return;
        }

        MailSignature::query()
            ->where('user_id', $zeile->user_id)
            ->whereKeyNot($zeile->getKey())
            ->when(
                $zeile->mail_account_id === null,
                fn ($q) => $q->whereNull('mail_account_id'),
                fn ($q) => $q->where('mail_account_id', $zeile->mail_account_id),
            )
            ->update(['is_default' => false]);
    }

    /**
     * @return array{id: int, name: string, body_html: string, is_default: bool, mail_account_id: int|null}
     */
    private function signaturZeile(MailSignature $zeile): array
    {
        return [
            'id' => (int) $zeile->id,
            'name' => (string) $zeile->name,
            'body_html' => (string) $zeile->body_html,
            'is_default' => (bool) $zeile->is_default,
            'mail_account_id' => $zeile->mail_account_id === null ? null : (int) $zeile->mail_account_id,
        ];
    }
}
