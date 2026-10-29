<?php

namespace Tests\Feature\Database;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\Fixtures;
use Tests\TestCase;

abstract class DatabaseTestCase extends TestCase
{
    use Fixtures, RefreshDatabase;
}
