<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use RuntimeException;

abstract class TestCase extends BaseTestCase
{
    protected function setUp(): void
    {
        $expectedDatabase = 'sewaellf_nexusos_core_testing';
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
}
