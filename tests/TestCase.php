<?php

namespace Xve\LaravelPaynl\Tests;

use Orchestra\Testbench\TestCase as Orchestra;
use Xve\LaravelPaynl\LaravelPaynlServiceProvider;

class TestCase extends Orchestra
{
    protected function getPackageProviders($app): array
    {
        return [LaravelPaynlServiceProvider::class];
    }

    protected function defineEnvironment($app): void
    {
        // Testbench 8 (Laravel 10) defaults to mysql; book() runs in a transaction
        $app['config']->set('database.default', 'testing');
        $app['config']->set('database.connections.testing', ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '']);

        $app['config']->set('paynl.token_code', 'AT-0000-0000');
        $app['config']->set('paynl.api_token', 'secret');
        $app['config']->set('paynl.service_id', 'SL-0000-0000');
        $app['config']->set('paynl.service_secret', 'sl-secret');
    }
}
