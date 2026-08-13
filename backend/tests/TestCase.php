<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use RuntimeException;

abstract class TestCase extends BaseTestCase
{
    protected function setUp(): void
    {
        $expectedDatabase = 'sewaellf_ufq_pilot_testing';
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

        parent::setUp();
    }
}
