<?php

namespace Peppermint\Mailbox\Contracts;

/**
 * Wirft die gespeicherte Kontenliste weg, damit die nächste Frage die Mitte erreicht.
 *
 * ## Warum das nicht `TokenRefresher` ist
 *
 * Der Erneuerer handelt nach der **Uhr**: Er läuft vor dem Verbinden, wenn das
 * Token bald abläuft. Bei zentral gepflegten Konten gibt es diese Uhr nicht —
 * AI Brain prägt bei jedem Lesen ein frisches Token und nennt keinen Ablauf,
 * der Zeitstempel im Produkt ist deshalb `null`, und
 * {@see \Peppermint\Mailbox\Models\MailAccount::isTokenExpiringSoon()} meldet
 * dort immer `false`. Ein Erneuerer an diesem Steckplatz würde nie gerufen.
 *
 * Diese Schnittstelle handelt stattdessen nach dem **Fehlschlag**: Erst wenn
 * eine Anmeldung abgelehnt wurde, ist erwiesen, dass die gespeicherte Angabe
 * nichts mehr taugt.
 *
 * ## Was eine Umsetzung NICHT tun darf
 *
 * Sie darf **nicht selbst erneuern**. Microsoft rotiert Erneuerungs-Tokens:
 * Jede Erneuerung entwertet die vorige, und zwei Systeme, die unabhängig
 * voneinander erneuern, nehmen sich gegenseitig das Postfach weg. Erlaubt ist
 * genau eines — wegwerfen, was man gespeichert hat. Dass danach ein gültiges
 * Token kommt, ist die Leistung der Mitte, nicht die des Produkts.
 *
 * ## Warum optional
 *
 * Ein Produkt mit eigener Kontotabelle hat keinen Zwischenspeicher, den es
 * verwerfen könnte, und für das wäre ein Pflichtverfahren eine leere Methode.
 * Die Clients fragen deshalb `instanceof` und verhalten sich ohne die
 * Schnittstelle genau wie bisher.
 */
interface VerwirftKonten
{
    /**
     * Vergisst die gespeicherte Kontenliste.
     *
     * @param  int|null  $ownerId  Nur die Liste dieser Person — oder `null`
     *                             für alle gespeicherten Listen. Eine Liste
     *                             ohne Besitzer (`all`) wird in BEIDEN Fällen
     *                             mit vergessen: Das Konto, um das es geht,
     *                             steht auch dort drin.
     */
    public function verwerfen(?int $ownerId = null): void;
}
