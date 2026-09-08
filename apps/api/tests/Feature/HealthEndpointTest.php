<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Crypt;
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
            ->assertJsonPath('checks.encryption.ok', true)
            ->assertJsonPath('checks.database.ok', true)
            ->assertJsonPath('checks.cache.ok', true)
            ->assertJsonPath('checks.storage.ok', true);

        $this->assertSame([], Storage::disk('local')->allFiles('.health'));
    }

    public function test_readiness_fails_before_traffic_when_the_encryption_key_is_missing(): void
    {
        Storage::fake('local');
        $configuredKey = config('app.key');

        try {
            config(['app.key' => '']);
            Crypt::clearResolvedInstance('encrypter');

            $this->getJson('/api/v1/health/ready')
                ->assertServiceUnavailable()
                ->assertJsonPath('status', 'not_ready')
                ->assertJsonPath('checks.encryption.ok', false)
                ->assertJsonPath('checks.encryption.error', 'MissingAppKeyException');
        } finally {
            config(['app.key' => $configuredKey]);
            Crypt::clearResolvedInstance('encrypter');
        }
    }
}
