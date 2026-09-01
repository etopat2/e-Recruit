<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\CreatesRecruitmentFixtures;
use Tests\TestCase;

class ScopeAuthorizationTest extends TestCase
{
    use CreatesRecruitmentFixtures;
    use RefreshDatabase;

    public function test_applicant_cannot_view_another_applicants_record(): void
    {
        $first = $this->recruitmentFixture();
        $secondUser = User::factory()->create(['user_type' => 'applicant']);
        Sanctum::actingAs($secondUser);

        $this->getJson("/api/v1/applications/{$first['application']->id}")->assertForbidden();
    }
}
