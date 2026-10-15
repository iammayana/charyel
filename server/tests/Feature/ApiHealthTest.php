<?php

namespace Tests\Feature;

use Tests\TestCase;

class ApiHealthTest extends TestCase
{
    /**
     * Test that the versioned API health check returns healthy status.
     */
    public function test_api_v1_health_returns_ok(): void
    {
        $response = $this->getJson('/api/v1/health');

        $response->assertStatus(200)
                 ->assertJson([
                     'service' => 'Charyel API',
                     'version' => 'v1',
                     'status' => 'healthy',
                 ]);
    }
}
