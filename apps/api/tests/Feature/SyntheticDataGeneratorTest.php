<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\CreatesRecruitmentFixtures;
use Tests\TestCase;

class SyntheticDataGeneratorTest extends TestCase
{
    use CreatesRecruitmentFixtures;
    use RefreshDatabase;

    public function test_generator_creates_clearly_synthetic_non_pii_load_records(): void
    {
        $this->recruitmentFixture();

        $this->artisan('erecruit:generate-synthetic', ['--count' => 3, '--seed' => 42])->assertSuccessful();

        $this->assertDatabaseCount('applications', 4);
        $this->assertDatabaseHas('users', ['email' => 'synthetic+1@example.invalid', 'user_type' => 'applicant']);
        $this->assertDatabaseHas('applications', ['reference' => 'SYN-WRD-000000001', 'status' => 'submitted_online']);
    }
}
