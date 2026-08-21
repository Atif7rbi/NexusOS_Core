<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class PostgreSqlConnectionTest extends TestCase
{
    public function test_testing_environment_uses_the_expected_postgresql_database(): void
    {
        $this->assertSame('pgsql', DB::connection()->getDriverName());

        $databaseName = DB::selectOne(
            'select current_database() as database_name'
        )->database_name;

        $this->assertSame(
            'sewaellf_nexusos_core_testing',
            $databaseName
        );
    }
}
