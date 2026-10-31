<?php

namespace Peppermint\Mailbox\Http;

use Illuminate\Http\JsonResponse;
use Peppermint\Mailbox\Contracts\Signaturvorrat;
use Peppermint\Mailbox\Models\MailAccount;

/**
 * Die Signaturen eines Postfachs — eine Route, vier Produkte.
 *
 * ## Warum das hierhergehoert
 *
 * Gefunden von `mail-2b` am 31.10.2026: Eine `signatureSource` gab nur der
 * Projekt-Manager mit (`useEmailSignatures`, `GET /emails/signatures/list`).
 * Die anderen drei uebergaben `leereSignaturQuelle` — das Formular zeichnete
 * die Zeile gar nicht, und niemandem fiel auf, dass sie fehlte.
 *
 * Dieselbe Naht wie beim Adressbuch: Die Route gehoert dem Paket, der VORRAT
 * dem Produkt ({@see Signaturvorrat}). Vorschlag fuer die Adresse, damit die
 * Vorgabe-Quelle im Browser ohne Angabe passt:
 *
 *     GET {basis}/{account}/signatures
 *
 * ## Was NICHT hier entschieden wird
 *
 * Wer die Person ist. Siehe {@see Signaturvorrat} — das Produkt liest sie
 * selbst, das Paket kennt kein Benutzermodell.
 *
 * ## Warum eine leere Liste mit `available: false` und nicht ein Fehler
 *
 * Ein Produkt ohne Signaturen ist der Normalfall (drei von vier haben keine),
 * und eine Fehlerseite dafuer waere falsch. Eine leere Liste allein ist aber
 * zweideutig: Sie heisst entweder „dieses Produkt fuehrt keine" oder „fuer
 * dieses Postfach keine" — und beim naechsten Mal „die Bindung ist vergessen
 * worden".
 *
 * Darum beides: `signatures` ist die Liste, `available` sagt, ob ueberhaupt
 * jemand gefragt wurde. Dieselbe Unterscheidung wie in der Werkzeugleiste
 * zwischen „nichts zu tun" und „niemand hat geantwortet", und sie ist hier zum
 * dritten Mal bezahlt.
 */
trait LiefertSignaturen
{
    abstract protected function accountFor(int $account): MailAccount;

    public function signaturen(int $account): JsonResponse
    {
        $konto = $this->accountFor($account);

        if (! app()->bound(Signaturvorrat::class)) {
            return response()->json([
                'signatures' => [],
                // Niemand gefragt — dieses Produkt fuehrt keine Signaturen.
                'available' => false,
            ]);
        }

        try {
            $vorrat = app(Signaturvorrat::class)->signaturen($konto);
        } catch (\Throwable $e) {
            report($e);

            /*
             * Der Vorrat ist Produktcode und darf die Verfassen-Seite nicht
             * mitnehmen: Ohne Signaturen kann man eine Mail schreiben, ohne
             * Formular nicht. `available: true` bleibt dabei richtig — gefragt
             * wurde, es ging nur schief, und das steht im Satz.
             */
            return response()->json([
                'signatures' => [],
                'available' => true,
                'message' => 'Die Signaturen konnten nicht geladen werden.',
            ], 502);
        }

        return response()->json([
            'signatures' => array_values(array_map(static fn (array $eintrag): array => [
                'id' => $eintrag['id'] ?? 0,
                'name' => (string) ($eintrag['name'] ?? ''),
                'body_html' => (string) ($eintrag['body_html'] ?? ''),
                /*
                 * Immer gesetzt, auch wenn falsch. Ein fehlender Schluessel
                 * und `false` sind in JavaScript dasselbe — hier aber nicht in
                 * der Absicht: Die Oberflaeche soll nicht raten muessen, ob
                 * ein Produkt die Vorgabe NICHT fuehrt oder sie nur bei diesem
                 * Eintrag nicht gesetzt hat.
                 */
                'is_default' => (bool) ($eintrag['is_default'] ?? false),
            ], $vorrat)),
            'available' => true,
        ]);
    }
}
