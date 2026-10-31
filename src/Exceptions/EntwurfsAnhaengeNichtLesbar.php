<?php

namespace Peppermint\Mailbox\Exceptions;

/**
 * Die Anhaenge eines wiederaufgenommenen Entwurfs liessen sich nicht holen.
 *
 * Geworfen VOR dem Versand und mit Absicht: Eine Mail, die eine Rechnung
 * ankuendigt und ohne sie hinausgeht, laesst sich nicht zurueckholen. Ein
 * Abbruch kostet einen zweiten Versuch.
 *
 * Das ist ausdruecklich NICHT der Fall „der Entwurf hat keine Anhaenge" — der
 * ist eine leere Liste und voellig in Ordnung.
 */
class EntwurfsAnhaengeNichtLesbar extends \RuntimeException {}
