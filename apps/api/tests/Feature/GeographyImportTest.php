<?php

namespace Tests\Feature;

use App\Models\AdministrativeUnit;
use App\Models\PrisonRegion;
use App\Models\RecruitmentCampaign;
use App\Models\RecruitmentCentre;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class GeographyImportTest extends TestCase
{
    use RefreshDatabase;

    public function test_administrative_hierarchy_csv_import_is_transactional_and_parent_aware(): void
    {
        $this->actingAsAdministrator();
        $valid = implode("\n", [
            'code,name,level,parent_code,effective_from,effective_to,active',
            'KLA,Kampala,district,,2027-01-01,,true',
            'KLA-CEN,Kampala Central,county,KLA,2027-01-01,,true',
        ]);

        $this->post('/api/v1/admin/geography/imports/administrative-units', [
            'file' => UploadedFile::fake()->createWithContent('administrative-units.csv', $valid),
        ], ['Accept' => 'application/json'])
            ->assertOk()
            ->assertJson(['status' => 'imported', 'imported' => 2, 'errors' => []]);

        $district = AdministrativeUnit::query()->where('code', 'KLA')->firstOrFail();
        $this->assertDatabaseHas('administrative_units', [
            'code' => 'KLA-CEN',
            'level' => 'county',
            'parent_id' => $district->id,
        ]);

        $invalid = implode("\n", [
            'code,name,level,parent_code,effective_from,effective_to,active',
            'WAK,Wakiso,district,,2027-01-01,,true',
            'BAD,Orphan Parish,parish,NOT-FOUND,2027-01-01,,true',
        ]);
        $this->post('/api/v1/admin/geography/imports/administrative-units', [
            'file' => UploadedFile::fake()->createWithContent('administrative-units.csv', $invalid),
        ], ['Accept' => 'application/json'])
            ->assertUnprocessable()
            ->assertJsonPath('status', 'validation_failed')
            ->assertJsonPath('imported', 0)
            ->assertJsonPath('errors.0.row', 3);
        $this->assertDatabaseCount('administrative_units', 2);
        $this->assertDatabaseMissing('administrative_units', ['code' => 'WAK']);
    }

    public function test_mapping_import_and_unresolved_resolution_never_guess_a_centre(): void
    {
        $user = $this->actingAsAdministrator();
        $campaign = RecruitmentCampaign::factory()->create(['code' => 'UPS-GEO-2027']);
        $district = AdministrativeUnit::query()->create(['code' => 'GUL', 'name' => 'Gulu', 'level' => 'district', 'active' => true]);
        $region = PrisonRegion::query()->create(['code' => 'NOR', 'name' => 'Northern', 'active' => true]);
        $centre = RecruitmentCentre::query()->create(['prison_region_id' => $region->id, 'code' => 'GUL-CTR', 'name' => 'Synthetic Gulu Centre', 'address' => 'Synthetic address', 'active' => true]);

        DB::table('unresolved_jurisdictions')->insert([
            'id' => $unresolvedId = (string) Str::ulid(),
            'recruitment_campaign_id' => $campaign->id,
            'district_id' => $district->id,
            'source_value' => 'Gulu',
            'status' => 'open',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $this->assertDatabaseCount('district_centre_mappings', 0);

        $this->postJson("/api/v1/admin/geography/unresolved/{$unresolvedId}/resolve", [
            'recruitment_centre_id' => $centre->id,
            'effective_from' => '2027-01-01',
            'resolution_note' => 'Approved synthetic mapping for automated test.',
        ])->assertOk()->assertJsonPath('status', 'resolved');
        $this->assertDatabaseHas('unresolved_jurisdictions', ['id' => $unresolvedId, 'status' => 'resolved', 'resolved_by' => $user->id]);
        $this->assertDatabaseHas('district_centre_mappings', [
            'recruitment_campaign_id' => $campaign->id,
            'district_id' => $district->id,
            'recruitment_centre_id' => $centre->id,
        ]);

        $mappingCsv = implode("\n", [
            'campaign_code,district_code,centre_code,effective_from,effective_to',
            'UPS-GEO-2027,GUL,GUL-CTR,2027-02-01,',
        ]);
        $this->post('/api/v1/admin/geography/imports/district-centre-mappings', [
            'file' => UploadedFile::fake()->createWithContent('district-centre-mappings.csv', $mappingCsv),
        ], ['Accept' => 'application/json'])
            ->assertOk()
            ->assertJson(['status' => 'imported', 'imported' => 1]);
        $this->assertDatabaseCount('district_centre_mappings', 2);
    }

    public function test_csv_and_xlsx_templates_are_downloadable(): void
    {
        $this->actingAsAdministrator();

        $this->get('/api/v1/admin/geography/templates/administrative-units?format=csv')
            ->assertOk()
            ->assertDownload('administrative-units-template.csv');
        $this->get('/api/v1/admin/geography/templates/district-centre-mappings?format=xlsx')
            ->assertOk()
            ->assertDownload('district-centre-mappings-template.xlsx');
    }

    private function actingAsAdministrator(): User
    {
        $user = User::factory()->create([
            'user_type' => 'hq_recruitment_administrator',
            'is_privileged' => true,
            'mfa_confirmed_at' => now(),
        ]);
        Sanctum::actingAs($user);

        return $user;
    }
}
