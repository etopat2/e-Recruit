<?php

namespace Tests\Feature;

use Tests\TestCase;

class HealthEndpointTest extends TestCase
{
    public function test_liveness_is_public_and_contains_no_sensitive_configuration(): void
    {
        $this->getJson('/api/v1/health/live')
            ->assertOk()
            ->assertJson(['status' => 'ok'])
            ->assertJsonMissing(['APP_KEY']);
    }
}
