<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    public function createApplication()
    {
        // Windows exposes inherited environment values through both adapters.
        // Force the test database before Laravel boots, regardless of the runner.
        foreach (['APP_ENV' => 'testing', 'DB_CONNECTION' => 'sqlite', 'DB_DATABASE' => ':memory:', 'DB_URL' => '', 'CACHE_STORE' => 'array', 'SESSION_DRIVER' => 'array'] as $key => $value) {
            putenv($key.'='.$value);
            $_ENV[$key] = $value;
            $_SERVER[$key] = $value;
        }

        $app = parent::createApplication();
        if ($app['config']['database.connections.sqlite.database'] !== ':memory:') {
            throw new \RuntimeException('Tests may only run against the isolated in-memory database.');
        }

        return $app;
    }
}
