<?php

use Peppermint\Mailbox\Tests\OhnePaketMigrationenTestCase;
use Peppermint\Mailbox\Tests\TestCase;

uses(TestCase::class)->in('Feature');

/*
 * Eine Anwendung, die die Kontotabellen des Pakets NICHT will — so stehen alle
 * vier Produkte. Eigener Ordner, weil Pest je Ordner genau einen Testfall
 * bindet (#6119).
 */
uses(OhnePaketMigrationenTestCase::class)->in('Verpackung');
