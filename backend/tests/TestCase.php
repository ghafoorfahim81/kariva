<?php

namespace Tests;

use Closure;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Facades\DB;

abstract class TestCase extends BaseTestCase
{
    /**
     * Asserts that the database refuses the statement(s). The work runs in a savepoint so the surrounding
     * test transaction survives the failure; $needle must appear in the database error (constraint, index or trigger text).
     */
    protected function assertRejected(Closure $statement, string $needle): void
    {
        try {
            DB::transaction($statement);
        } catch (QueryException $e) {
            $this->assertStringContainsString($needle, $e->getMessage());

            return;
        }

        $this->fail("The database accepted a statement that should violate [$needle].");
    }
}
