<?php

namespace Tests\Feature;

use Tests\TestCase;

class ApiPingTest extends TestCase
{
    public function test_the_versioned_api_answers_with_json(): void
    {
        $this->getJson('/api/v1/ping')->assertOk()->assertExactJson(['app' => 'kariva', 'database' => true]);
    }

    public function test_unknown_api_routes_return_json_not_html(): void
    {
        $this->getJson('/api/v1/nothing-here')->assertNotFound()->assertJsonStructure(['message']);
    }
}
