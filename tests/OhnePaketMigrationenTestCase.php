<?php

namespace Peppermint\Mailbox\Tests;

/**
 * Eine Anwendung, die die Kontotabellen des Pakets NICHT will.
 *
 * So stehen alle vier Produkte: `run_migrations => false`, weil die
 * Kontotabelle des Pakets neben der gewachsenen eigenen eine zweite, leere
 * anlegen würde. Das Kopfzeilen-Verzeichnis muss trotzdem entstehen — sonst
 * wäre es in keinem einzigen Produkt je angelegt worden (#6119).
 */
abstract class OhnePaketMigrationenTestCase extends TestCase
{
    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        $app['config']->set('mailbox.run_migrations', false);
    }
}
