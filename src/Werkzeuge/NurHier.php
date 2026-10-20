<?php

namespace Peppermint\Mailbox\Werkzeuge;

/**
 * Ein Verb, das NICHT an andere Systeme herausgeht.
 *
 * ## Der Fall, fuer den es das gibt
 *
 * Fast alle Verben gehoeren einem Produkt: „Als Eingangsrechnung" kann nur die
 * Verwaltung, „Aufgabe anhaengen" nur der Manager. Sie sollen gerade
 * herausgehen — das ist der ganze Zweck der zusammengelegten Leiste.
 *
 * „Person zuordnen" ist anders: Es bedeutet in allen vier Systemen dasselbe
 * und gehoert deshalb ins Paket. Damit meldet es aber JEDES System an — und
 * ohne diesen Marker holt jedes System es dreimal von den anderen zurueck.
 * Vier gleich aussehende Knoepfe, nur mit verschiedenen Abzeichen, und drei
 * davon fuehren ueber zwei Netzwerkspruenge zu demselben Ergebnis wie der
 * erste.
 *
 * Das ist genau das Problem, das die Leiste aufloesen sollte — vier Kopien,
 * eine Ebene hoeher.
 *
 * ## Es ist keine Rechtefrage
 *
 * Ein Werkzeug mit diesem Marker ist nicht geheim und nicht geschuetzt. Es ist
 * nur ueberall selbst vorhanden, und deshalb waere es doppelt. Wer eine
 * ZUGRIFFSgrenze braucht, setzt sie in `ausfuehren()` — dort, wo sie auch bei
 * jedem anderen Verb hingehoert.
 */
interface NurHier {}
