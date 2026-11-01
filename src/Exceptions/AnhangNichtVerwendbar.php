<?php

namespace Peppermint\Mailbox\Exceptions;

use Peppermint\Mailbox\Werkzeuge\LiefertFelder;
use Peppermint\Mailbox\Werkzeuge\Nachrichtenfund;

/**
 * Der gewaehlte Anhang laesst sich nicht verwenden — und zwar genau einer.
 *
 * ## Warum eine Ausnahme und kein `null`
 *
 * Die Feldart `anhang` liefert einen DATEINAMEN (siehe
 * {@see LiefertFelder}). Ihn in eine Stelle in
 * der Mail umzurechnen kann auf drei Weisen ausgehen, und nur eine davon ist
 * „nicht da":
 *
 *   gefunden     → der Anhang, mit seinen Bytes
 *   fehlt        → den Namen traegt diese Mail nicht
 *   mehrdeutig   → ZWEI Anhaenge dieser Mail heissen gleich
 *
 * Der dritte Fall ist der Grund fuer diese Klasse. `null` fuer „fehlt" und
 * „mehrdeutig" zugleich zwingt den Aufrufer zu einem Satz, der fuer einen der
 * beiden Faelle falsch ist — und „dieser Anhang ist nicht mehr da" ist bei
 * zwei gleichnamigen Anhaengen nicht nur falsch, sondern schickt jemanden
 * ins Postfach, um etwas zu suchen, was dort liegt.
 *
 * Geraten wird dabei NICHT. Zwei gleichnamige Anhaenge sind der Fall, an dem
 * der falsche Beleg verbucht wird und niemand es merkt — und genau dagegen
 * liefert das Paket den Namen statt der Stelle. Den einen zu nehmen, der
 * zuerst kommt, waere dieselbe Wette, nur eine Ebene tiefer.
 *
 * Der Satz ist fuer den Menschen davor gedacht: {@see Nachrichtenfund::anhang()}
 * nennt den Dateinamen, damit die Meldung in der Leiste etwas aussagt.
 */
class AnhangNichtVerwendbar extends \RuntimeException {}
