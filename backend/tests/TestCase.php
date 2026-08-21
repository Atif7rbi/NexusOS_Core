<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use RuntimeException;

abstract class TestCase extends BaseTestCase
{
    protected function setUp(): void
    {
        $expectedDatabase = $this->expectedTestDatabase();
        $configuredDatabase = getenv('DB_DATABASE');

        if ($configuredDatabase !== $expectedDatabase) {
            throw new RuntimeException(
                sprintf(
                    'Unsafe test database configuration. Expected [%s], got [%s]. Aborting before Laravel test setup.',
                    $expectedDatabase,
                    $configuredDatabase ?: 'undefined',
                )
            );
        }

        $configCache = getenv('APP_CONFIG_CACHE');
        $defaultConfigCache = dirname(__DIR__).'/bootstrap/cache/config.php';

        if (($configCache === false || $configCache === '') && is_file($defaultConfigCache)) {
            throw new RuntimeException(
                'Unsafe test configuration: a Laravel config cache is present. Set APP_CONFIG_CACHE to an isolated non-existent path before running tests.',
            );
        }

        parent::setUp();

        $runtimeDatabase = config('database.connections.'.config('database.default').'.database');

        if ($runtimeDatabase !== $expectedDatabase) {
            throw new RuntimeException(
                sprintf(
                    'Unsafe Laravel test database resolution. Expected [%s], got [%s].',
                    $expectedDatabase,
                    $runtimeDatabase ?: 'undefined',
                )
            );
        }
    }

    protected function expectedTestDatabase(): string
    {
        $database = getenv('NEXUSOS_TEST_DATABASE');

        if (! is_string($database) || $database === '') {
            throw new RuntimeException(
                'Unsafe test database configuration. Set NEXUSOS_TEST_DATABASE and DB_DATABASE to the same isolated database before running tests.',
            );
        }

        if (! preg_match('/^[A-Za-z0-9_]+_testing$/', $database)) {
            throw new RuntimeException(
                sprintf(
                    'Unsafe test database name [%s]. NEXUSOS_TEST_DATABASE must end with [_testing].',
                    $database,
                ),
            );
        }

        return $database;
    }
}
