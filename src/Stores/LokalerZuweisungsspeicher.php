<?php

namespace Peppermint\Mailbox\Stores;

use Illuminate\Database\Eloquent\Builder;
use Peppermint\Mailbox\Contracts\Zuweisungsspeicher;
use Peppermint\Mailbox\Http\HandlesAssignments;
use Peppermint\Mailbox\Models\MailAssignment;
use Peppermint\Mailbox\Zustand\Person;
use Peppermint\Mailbox\Zustand\Zuweisung;
use Peppermint\Mailbox\Zustand\Zuweisungsliste;

/**
 * Zuweisungen in der eigenen Tabelle.
 *
 * Das ist genau das Verhalten, das bis zum 19.10.2026 direkt in
 * {@see HandlesAssignments} stand — unveraendert
 * hierher gezogen, damit die Umstellung auf den Vertrag an keiner Stelle
 * etwas anderes TUT. Wer den Speicher nicht umschaltet, merkt von diesem
 * Umbau nichts.
 *
 * Gebraucht wird er weiter: fuer eine eigenstaendige Installation ohne AI
 * Brain, und als Rueckfall, wenn `store: brain` ohne Bridge konfiguriert ist.
 *
 * ## Die Adresse kommt von draussen
 *
 * Fuer die Tabelle reicht die oertliche Nummer. Das Wertobjekt traegt aber
 * beides, weil ein Produkt-Haken nicht wissen soll, aus welchem Speicher die
 * Aussage kam — ein `afterAssign()`, das je nach Betriebsart andere Felder
 * vorfindet, waere zwei Haken mit einem Namen.
 */
class LokalerZuweisungsspeicher implements Zuweisungsspeicher
{
    /**
     * @param  (callable(int): ?string)|null  $adresse  Die Mailadresse zur oertlichen Kennung.
     */
    public function __construct(private $adresse = null) {}

    public function schreibbar(): bool
    {
        return true;
    }

    public function offene(int $konto): Zuweisungsliste
    {
        $postfach = $this->postfach($konto);

        $zeilen = MailAssignment::query()
            ->where('email_account_id', $konto)
            ->where('status', MailAssignment::STATUS_OPEN)
            ->get()
            ->map(fn (MailAssignment $z): Zuweisung => $this->alsWert($z, $konto, $postfach))
            ->values()
            ->all();

        return Zuweisungsliste::mit($zeilen);
    }

    public function fuer(int $konto, string $nachricht, ?string $kette): ?Zuweisung
    {
        $zeile = $this->frage($konto, $nachricht, $kette)->first();

        return $zeile === null ? null : $this->alsWert($zeile, $konto, $this->postfach($konto));
    }

    public function zuweisen(int $konto, string $nachricht, ?string $kette, Person $an, ?Person $von): ?Zuweisung
    {
        if ($an->nummer === null) {
            /*
             * Die eigene Tabelle fuehrt Nummern. Eine Person, die hier keine
             * hat, ist eine, die dieses System nicht kennt — und die kann in
             * seiner Tabelle nicht stehen.
             *
             * Das ist kein Grenzfall ohne Anlass: Genau so kommt eine
             * Zustaendigkeit herein, die in der Mitte fuer jemanden steht, den
             * es hier nicht gibt. Sie zu verwerfen ist richtig; sie mit einer
             * erfundenen Nummer zu speichern waere die Art Fehler, die man
             * erst am falschen Namen im Abzeichen bemerkt.
             */
            return null;
        }

        $zeile = MailAssignment::updateOrCreate(
            [
                'email_account_id' => $konto,
                // Ohne Kettenkennung bleibt die Nachricht der Schluessel.
                ...($kette !== null ? ['thread_id' => $kette] : ['message_id' => $nachricht]),
            ],
            [
                'message_id' => $nachricht,
                'thread_id' => $kette,
                'assigned_to_user_id' => $an->nummer,
                'assigned_by_user_id' => $von?->nummer,
                'status' => MailAssignment::STATUS_OPEN,
            ],
        );

        // Mit den Personen, wie sie hereinkamen: Der Speicher kennt nur
        // Nummern, und ein Wertobjekt mit halber Person waere fuer den
        // Produkt-Haken schlechter als gar keins.
        return $this->alsWert($zeile, $konto, $this->postfach($konto))->mitPersonen($an, $von);
    }

    public function aufheben(int $konto, string $nachricht, ?string $kette): bool
    {
        $this->frage($konto, $nachricht, $kette)->delete();

        // Auch wenn nichts getroffen wurde: Danach ist niemand zustaendig, und
        // genau das war der Auftrag. Ein `false` bei null betroffenen Zeilen
        // waere eine Fehlermeldung fuer einen erreichten Zustand.
        return true;
    }

    /**
     * Die Suche nach der Nachricht bleibt neben der Kette stehen, damit auch
     * Zuweisungen ohne Kettenkennung gefunden werden.
     *
     * @return Builder<MailAssignment>
     */
    private function frage(int $konto, string $nachricht, ?string $kette)
    {
        return MailAssignment::query()
            ->where('email_account_id', $konto)
            ->where(function ($frage) use ($kette, $nachricht): void {
                $frage->where('message_id', $nachricht);

                if ($kette !== null) {
                    $frage->orWhere('thread_id', $kette);
                }
            });
    }

    private function alsWert(MailAssignment $zeile, int $konto, string $postfach): Zuweisung
    {
        return new Zuweisung(
            konto: $konto,
            postfach: $postfach,
            nachricht: (string) $zeile->message_id,
            kette: $zeile->thread_id === null ? null : (string) $zeile->thread_id,
            // Ohne Adresse: Die eigene Tabelle fuehrt keine. Das Merkmal
            // loest sie aus seiner Personenliste auf — es ist die einzige
            // Stelle, die Nummer UND Adresse kennt.
            an: new Person(adresse: '', nummer: (int) $zeile->assigned_to_user_id),
            von: $zeile->assigned_by_user_id === null
                ? null
                : new Person(adresse: '', nummer: (int) $zeile->assigned_by_user_id),
            status: (string) $zeile->status,
        );
    }

    private function postfach(int $konto): string
    {
        if ($this->adresse === null) {
            return '';
        }

        return (string) (($this->adresse)($konto) ?? '');
    }
}
