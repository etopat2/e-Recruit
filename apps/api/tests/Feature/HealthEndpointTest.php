<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Storage;
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

    public function test_readiness_checks_dependencies_and_removes_its_storage_probe(): void
    {
        Storage::fake('local');

        $this->getJson('/api/v1/health/ready')
            ->assertOk()
            ->assertJsonPath('status', 'ready')
            ->assertJsonPath('checks.database.ok', true)
            ->assertJsonPath('checks.cache.ok', true)
            ->assertJsonPath('checks.storage.ok', true);

        $this->assertSame([], Storage::disk('local')->allFiles('.health'));
    }
}
