<?php

namespace Peppermint\Mailbox\Stores;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Peppermint\Mailbox\Accounts\SichtbareKonten;
use Peppermint\Mailbox\Contracts\AccountStore;
use Peppermint\Mailbox\Models\MailAccount;

/**
 * Accounts live in the product's own table.
 *
 * The default, and the reason the package runs on its own: install it without
 * anything else and you get exactly this, with no trace of AI Brain.
 *
 * ## Wer sieht was
 *
 * {@see SichtbareKonten} fragt nach der ADRESSE —
 * die einzige Kennung, die ueber Systemgrenzen dieselbe Person meint. Die
 * eigene Tabelle kennt aber nur `user_id` und die Zuteilungstabelle. Bis
 * v0.213.0 kam diese Uebersetzung nicht zustande, und jedes persoenliche
 * Postfach galt als „Besitzer unbekannt" — sichtbar fuer alle. Deshalb haengt
 * dieser Speicher jedem Konto Besitzer- und Leser-Adressen an, in EINEM Zug
 * fuer alle Konten.
 */
class LocalAccountStore implements AccountStore
{
    public function all(?int $ownerId = null): Collection
    {
        // `accessibleBy`, not `where(user_id)`: a person's mailboxes include
        // the shared ones they may work in. Filtering on the owner alone hides
        // every team address — and hides it quietly, which is worse.
        $konten = MailAccount::query()
            ->when($ownerId !== null, fn ($query) => $query->accessibleBy($ownerId))
            ->get();

        return $this->mitSichtbarkeit($konten);
    }

    public function find(string|int $id): ?MailAccount
    {
        $konto = MailAccount::query()->find($id);

        return $konto === null ? null : $this->mitSichtbarkeit(collect([$konto]))->first();
    }

    public function isWritable(): bool
    {
        return true;
    }

    /**
     * Haengt jedem Konto an, wer es sehen darf.
     *
     * Ohne Personen-Modell bleibt es beim bisherigen Verhalten: Ein Paket, das
     * nicht weiss, wie eine Anwendung ihre Menschen nennt, soll nicht raten —
     * und nicht stillschweigend alles ausblenden.
     *
     * @param  Collection<int, MailAccount>  $konten
     * @return Collection<int, MailAccount>
     */
    private function mitSichtbarkeit(Collection $konten): Collection
    {
        $modell = (string) config('mailbox.user_model', 'App\\Models\\User');

        if ($konten->isEmpty() || ! class_exists($modell)) {
            return $konten;
        }

        $besitzerSpalte = MailAccount::column('user_id');
        $zuteilungen = $this->zuteilungen($konten);

        $personenIds = $konten->pluck($besitzerSpalte)
            ->merge($zuteilungen->flatten())
            ->filter(fn ($id): bool => $id !== null)
            ->unique()
            ->values();

        /** @var Model $person */
        $person = new $modell;
        $adressen = $personenIds->isEmpty()
            ? collect()
            : $person->newQuery()
                ->whereIn($person->getKeyName(), $personenIds->all())
                ->pluck('email', $person->getKeyName())
                ->map(fn ($adresse): string => (string) $adresse);

        return $konten->each(function (MailAccount $konto) use ($besitzerSpalte, $zuteilungen, $adressen): void {
            $besitzerId = $konto->getAttribute($besitzerSpalte);
            $zugeteilt = $zuteilungen->get((string) $konto->getKey(), []);

            $konto->mitOrtsSichtbarkeit(
                $besitzerId === null ? null : $adressen->get($besitzerId),
                collect($zugeteilt)->map(fn ($id) => $adressen->get($id))->filter()->values()->all(),
                $zugeteilt !== [],
            );
        });
    }

    /**
     * Welche Personen welchem Konto zugeteilt sind — Konto-ID => Personen-IDs.
     *
     * @param  Collection<int, MailAccount>  $konten
     * @return Collection<string, list<int|string>>
     */
    private function zuteilungen(Collection $konten): Collection
    {
        $liste = MailAccount::sharingTable();

        if ($liste === null) {
            return collect();
        }

        [$kontoSpalte, $nutzerSpalte] = MailAccount::sharingKeys();

        return DB::table($liste)
            ->whereIn($kontoSpalte, $konten->map(fn (MailAccount $k) => $k->getKey())->all())
            ->get([$kontoSpalte, $nutzerSpalte])
            ->groupBy(fn ($zeile): string => (string) $zeile->{$kontoSpalte})
            ->map(fn (Collection $zeilen): array => $zeilen->pluck($nutzerSpalte)->all());
    }
}
