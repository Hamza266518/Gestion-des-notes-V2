<?php

namespace Tests\Unit;

use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class TestDatabaseIsolationTest extends TestCase
{
    public function test_tests_never_run_against_the_live_database(): void
    {
        $name = DB::getDatabaseName();

        $this->assertNotSame(
            'Gestion_des_notes_V2',
            $name,
            'The test suite is pointed at the live database. Set DB_DATABASE in phpunit.xml to an isolated database.'
        );

        $this->assertStringEndsWith(
            '_test',
            $name,
            "The test suite database '{$name}' does not look like an isolated test database."
        );
    }
}
