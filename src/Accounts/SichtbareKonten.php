<?php

namespace Peppermint\Mailbox\Accounts;

use Illuminate\Support\Collection;
use Peppermint\Mailbox\Contracts\AccountStore;
use Peppermint\Mailbox\Models\MailAccount;

/**
 * Welche Postfächer darf diese Person sehen? — EINE Antwort (#8439).
 *
 * ## Warum das eine eigene Stelle ist
 *
 * Weil es vier gab. Gemessen am 21.10.2026 für `henneberg@peppermint-digital.de`:
 *
 *     Brain          8 Postfaecher
 *     CRM            6
 *     Verwaltung     6
 *     Manager        5   (das eigene Postfach der Person fehlte)
 *
 * Der Nutzer hat es so gemeldet: „Egal in welchem Programm ich im E-Mail-Browser
 * bin, müsste ich doch die gleichen E-Mail-Konten sehen? Das ist im Moment nicht
 * der Fall."
 *
 * Nicht die Regel war verschieden — `MailAccount::sichtbarFuer()` steht längst
 * im Paket und gilt überall. Verschieden war, WAS JEDES PRODUKT NOCH DAZU TAT:
 *
 *     Brain          nichts (sein Speicher filtert selbst nach Auth::id())
 *     CRM            sichtbarFuer
 *     Verwaltung     sichtbarFuer          (wortgleich mit dem CRM)
 *     Manager        lokale Tabelle -> is_active -> sichtbarFuer
 *
 * CRM und Verwaltung prüften `is_active` nicht, der Manager schon. Drei
 * Produkte, die dieselbe Frage dreimal beantworten, beantworten sie
 * unterschiedlich — nicht sofort, sondern beim nächsten Zusatz. Genau das ist
 * hier passiert.
 *
 * ## Warum `is_active` jetzt überall gilt
 *
 * Ein abgeschaltetes Postfach kann sich nicht verbinden. Es anzuzeigen heißt,
 * jemanden eines auswählen zu lassen, das nur einen Fehler liefert. Der Manager
 * hat das immer schon so gehalten; CRM und Verwaltung ziehen nach. Heute ändert
 * das nichts — alle sechzehn Postfächer sind aktiv —, und genau deshalb ist es
 * der richtige Moment dafür.
 *
 * ## Was hier NICHT hineingehört
 *
 * Die Frage „kann dieses Produkt dieses Postfach überhaupt öffnen". Der Manager
 * braucht für jedes Postfach eine Zeile in seiner eigenen Tabelle (dort hängen
 * acht Fremdschlüssel) und ruft an acht Stellen `findOrFail()`. Ein Postfach
 * ohne lokale Zeile anzuzeigen wäre schlimmer als es auszublenden: Es erscheint
 * und bricht beim Anklicken ab.
 *
 * Das ist eine Aussage über das PRODUKT, nicht über die Person — deshalb steht
 * sie dort und nicht hier. Sie gehört aber ausdrücklich hingeschrieben, nicht
 * als zweiter Sichtbarkeitsfilter getarnt.
 */
final class SichtbareKonten
{
    public function __construct(private readonly AccountStore $speicher) {}

    /**
     * Die Postfächer dieser Person, in der Reihenfolge des Speichers.
     *
     * `null` als Adresse heißt „niemand angemeldet". Dann bleiben persönliche
     * Postfächer draußen und Gruppenpostfächer ohne Leseliste sichtbar — das
     * ist, was `sichtbarFuer()` entscheidet, und es ist hier nicht die Stelle,
     * das zu überstimmen.
     *
     * @return Collection<int, MailAccount>
     */
    public function fuer(?string $adresse): Collection
    {
        return $this->speicher->all()
            ->filter(fn (MailAccount $konto): bool => $konto->istAktiv())
            ->filter(fn (MailAccount $konto): bool => $konto->sichtbarFuer($adresse))
            ->values();
    }

    /**
     * Ein einzelnes Postfach — oder nichts, wenn es diese Person nicht sehen darf.
     *
     * ## Warum nicht `AccountStore::find()`
     *
     * Weil `find()` nicht fragt, WER fragt. Ein Produkt, das eine Kennung aus
     * der Anfrage nimmt und damit `find()` ruft, öffnet jedes zugeteilte
     * Postfach für jeden angemeldeten Menschen — die Lücke vom 24.09.2026,
     * nur eine Ebene tiefer. Wer ein Postfach aus einer Anfrage auflöst,
     * nimmt diese Methode.
     */
    public function eines(int|string $kennung, ?string $adresse): ?MailAccount
    {
        return $this->fuer($adresse)->first(
            fn (MailAccount $konto): bool => (string) $konto->field('id') === (string) $kennung
        );
    }
}
