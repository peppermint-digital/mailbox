<?php

namespace Peppermint\Mailbox\Mcp;

use Illuminate\Database\Eloquent\Model;
use Peppermint\AiBrainBridge\Facades\AiBrain;
use Peppermint\AiBrainBridge\Verbund\Bruecke;

/**
 * Wer gerade fragt — bei einem Aufruf von aussen.
 *
 * Die Bruecke setzt die handelnde Person aus der Kopfzeile des Aufrufs; ohne
 * Bruecke ist es die angemeldete. Beides abzufragen kostet nichts und macht
 * die Werkzeuge auch ohne Hub benutzbar.
 *
 * ## Aufgeloest wird ueber die ADRESSE
 *
 * Nicht ueber eine Nummer. Derselbe Mensch hat in jedem System eine andere
 * Benutzer-ID; eine davon mitzuschicken funktioniert so lange zufaellig, wie
 * sie uebereinstimmen.
 *
 * ## Keine Person heisst keine Antwort
 *
 * Fail-closed, und das ist hier wichtiger als bei einer Liste: Ein Werkzeug
 * ohne handelnde Person koennte nur „irgendwer" meinen — und dieses Werkzeug
 * legt etwas an. Ein Vorgang, den niemand verantwortet, darf nicht entstehen.
 *
 * Eigene Klasse, weil beide Werkzeuge sie brauchen und eine zweite Kopie
 * genau in der Richtung veraltet, die niemand bemerkt.
 */
final class HandelndePerson
{
    public static function finden(): ?Model
    {
        if (class_exists(Bruecke::class) && Bruecke::eingerichtet()) {
            $adresse = AiBrain::actingUserEmail();

            if (is_string($adresse) && trim($adresse) !== '') {
                $modell = (string) config('mailbox.user_model', 'App\\Models\\User');

                if (! class_exists($modell)) {
                    return null;
                }

                return $modell::query()->whereRaw('LOWER(email) = ?', [mb_strtolower(trim($adresse))])->first();
            }
        }

        $person = auth()->user();

        return $person instanceof Model ? $person : null;
    }
}
