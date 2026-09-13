<?php

namespace Peppermint\Mailbox\Tests;

use Orchestra\Testbench\TestCase as Base;
use Peppermint\Mailbox\MailboxServiceProvider;

abstract class TestCase extends Base
{
    protected function getPackageProviders($app): array
    {
        return [MailboxServiceProvider::class];
    }

    protected function defineEnvironment($app): void
    {
        // Without a key the `encrypted` casts fail — and they fail in the
        // tests, not while building the package.
        $app['config']->set('app.key', 'base64:'.base64_encode(random_bytes(32)));
        $app['config']->set('database.default', 'testing');
        $app['config']->set('database.connections.testing', [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
        ]);
    }
}
