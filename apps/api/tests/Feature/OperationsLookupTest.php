<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\CreatesRecruitmentFixtures;
use Tests\TestCase;

class OperationsLookupTest extends TestCase
{
    use CreatesRecruitmentFixtures;
    use RefreshDatabase;

    public function test_authorised_operations_lookups_return_human_labels_and_document_checklist(): void
    {
        $fixture = $this->recruitmentFixture([
            'reference' => 'UPS/2026/WRD/000321', 'status' => 'awaiting_hard_copies',
            'draft_data' => ['skills' => [['name' => 'Carpentry']]],
        ]);
        $officer = User::factory()->create(['user_type' => 'verification_officer']);
        $officer->scopes()->create(['scope_type' => 'national', 'scope_id' => null, 'allowed_tasks' => ['*']]);
        Sanctum::actingAs($officer);
        $this->getJson('/api/v1/operations/lookups')->assertOk()
            ->assertJsonPath('data.posts.0.label', $fixture['campaign']->name.' — '.$fixture['post']->name)
            ->assertJsonPath('data.hard_copy.can_receive', true)
            ->assertJsonPath('data.hard_copy.receiving_point', 'Uganda Prisons Service Headquarters');

        $search = $this->getJson('/api/v1/operations/applications?search=Amina&context=hard_copy')->assertOk()
            ->assertJsonPath('data.0.label', 'Amina Nabirye — UPS/2026/WRD/000321');
        $types = collect($search->json('data.0.document_requirements'))->pluck('document_type');
        $this->assertEqualsCanonicalizing(
            ['national_id', 'application_letter', 'lc1_letter', 'academic_certificate', 'passport_photo', 'skill_certificate'],
            $types->all(),
        );
        $this->assertStringNotContainsString($fixture['application']->id, $search->json('data.0.label'));

        $this->getJson('/api/v1/operations/applications?search=UPS%2F2026%2FWRD%2F000321&context=hard_copy')->assertOk()->assertJsonCount(1, 'data');
    }

    public function test_regional_transmission_role_cannot_search_the_headquarters_receipt_register(): void
    {
        $fixture = $this->recruitmentFixture(['reference' => 'UPS/2026/WRD/000322', 'status' => 'awaiting_hard_copies']);
        $regionalOfficer = User::factory()->create(['user_type' => 'regional_recruitment_officer']);
        $regionalOfficer->scopes()->create(['scope_type' => 'campaign', 'scope_id' => $fixture['campaign']->id, 'allowed_tasks' => ['*']]);
        Sanctum::actingAs($regionalOfficer);

        $this->getJson('/api/v1/operations/lookups')->assertOk()
            ->assertJsonPath('data.hard_copy.can_receive', false);
        $this->getJson('/api/v1/operations/applications?search=UPS%2F2026%2FWRD%2F000322&context=hard_copy')
            ->assertForbidden();
    }

    public function test_applicant_cannot_access_operational_registers(): void
    {
        $fixture = $this->recruitmentFixture();
        Sanctum::actingAs($fixture['user']);

        $this->getJson('/api/v1/operations/lookups')->assertForbidden();
        $this->getJson('/api/v1/operations/applications?search=Amina')->assertForbidden();
    }
}
