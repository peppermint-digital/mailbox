<?php

namespace Peppermint\Mailbox\Events;

use Illuminate\Support\Facades\Cache;
use Peppermint\Mailbox\Contracts\AccountStore;
use Peppermint\Mailbox\Models\MailAccount;
use Peppermint\Mailbox\Stores\BrainVerlaufsspeicher;

/**
 * Ein Verlauf ist in der Mitte verschwunden — hier sofort vergessen.
 *
 * ## Warum es das braucht
 *
 * Der Zwischenspeicher haelt die Antwort „gibt es einen Verlauf?" fuenf
 * Minuten und den Verlauf selbst eine Minute. Das ist richtig so: Die Frage
 * stellt die Oberflaeche bei JEDER geoeffneten Nachricht, und ein Rundruf pro
 * Klick waere teuer.
 *
 * Genau diese Frist ist aber falsch, wenn jemand etwas ENTFERNT hat. Dann
 * steht der Verlauf hier noch minutenlang, obwohl er drueben schon weg ist —
 * und wer loescht, will nicht hoeren „in fuenf Minuten ist es wirklich weg".
 *
 * ## Warum nach der ADRESSE gesucht wird
 *
 * Die Mitte kennt ihre eigene Postfach-Nummer, dieses System seine. Gemeinsam
 * ist nur die Adresse — und der Zwischenspeicher haengt an der oertlichen
 * Nummer. Ohne diese Uebersetzung wuerde ein Schluessel vergessen, den es
 * hier nie gab, waehrend der echte stehenbleibt.
 */
class VerlaufVergessen
{
    /** Der Ereignistyp, den die Mitte schickt. */
    public const TYP = 'mail.archive.purged';

    /**
     * @param  array<string, mixed>  $payload
     * @return int Wie viele oertliche Postfaecher betroffen waren
     */
    public static function ausEreignis(array $payload): int
    {
        $adresse = mb_strtolower(trim((string) ($payload['mailbox'] ?? '')));
        $thread = (string) ($payload['thread_key'] ?? '');

        if ($adresse === '' || $thread === '') {
            return 0;
        }

        $vergessen = 0;

        foreach (self::konten($adresse) as $konto) {
            Cache::forget(BrainVerlaufsspeicher::schluessel('verfuegbar', $konto, $thread));
            Cache::forget(BrainVerlaufsspeicher::schluessel('verlauf', $konto, $thread));
            $vergessen++;
        }

        return $vergessen;
    }

    /**
     * Die oertlichen Kennungen zu dieser Adresse.
     *
     * Mehrzahl mit Absicht: Dieselbe Adresse zweimal angelegt zu haben ist
     * ein Fehler, aber ein vorkommender — und dann sollen beide vergessen.
     *
     * @return list<int>
     */
    private static function konten(string $adresse): array
    {
        if (! app()->bound(AccountStore::class)) {
            return [];
        }

        return app(AccountStore::class)->all()
            ->filter(fn (MailAccount $konto): bool => mb_strtolower((string) $konto->field('email')) === $adresse)
            ->map(fn (MailAccount $konto): int => (int) $konto->field('id'))
            ->values()
            ->all();
    }
}
