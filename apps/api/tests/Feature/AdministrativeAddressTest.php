<?php

namespace Tests\Feature;

use App\Models\AdministrativeUnit;
use App\Models\User;
use App\Services\AdministrativeUnitPathService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\CreatesRecruitmentFixtures;
use Tests\TestCase;

class AdministrativeAddressTest extends TestCase
{
    use CreatesRecruitmentFixtures;
    use RefreshDatabase;

    public function test_cascading_filters_and_village_search_return_the_complete_administrative_path(): void
    {
        $units = $this->administrativeHierarchy();
        Sanctum::actingAs(User::factory()->create());

        $this->getJson('/api/v1/geography/units?level=county&district_id='.$units['district']->id)
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.name', 'KAMPALA COUNTY');

        $this->getJson('/api/v1/geography/units?level=village&search=kisenyi')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.name', 'KISENYI')
            ->assertJsonPath('data.0.lineage.region.name', 'CENTRAL')
            ->assertJsonPath('data.0.lineage.district.name', 'KAMPALA')
            ->assertJsonPath('data.0.lineage.parish.name', 'CENTRAL WARD')
            ->assertJsonPath('data.0.full_address', 'KISENYI, CENTRAL WARD, CENTRAL DIVISION, KAMPALA COUNTY, KAMPALA, BUGANDA, CENTRAL');
    }

    public function test_application_address_path_is_validated_and_persisted_at_submission(): void
    {
        Storage::fake('local');
        $units = $this->administrativeHierarchy();
        $fixture = $this->recruitmentFixture();
        Sanctum::actingAs($fixture['user']);
        $address = [
            'region_id' => $units['region']->id,
            'region' => 'CENTRAL',
            'subregion_id' => $units['subregion']->id,
            'subregion' => 'BUGANDA',
            'district_id' => $units['district']->id,
            'district' => 'KAMPALA',
            'county_id' => $units['county']->id,
            'county' => 'KAMPALA COUNTY',
            'subcounty_id' => $units['subcounty']->id,
            'subcounty' => 'CENTRAL DIVISION',
            'parish_id' => $units['parish']->id,
            'parish' => 'CENTRAL WARD',
            'village_id' => $units['village']->id,
            'village' => 'KISENYI',
            'full_address' => 'KISENYI, CENTRAL WARD, CENTRAL DIVISION, KAMPALA COUNTY, KAMPALA, BUGANDA, CENTRAL',
            'physical_address' => 'Near the synthetic market',
        ];

        $this->putJson("/api/v1/applications/{$fixture['application']->id}", [
            'draft_data' => ['address' => [...$address, 'county_id' => $units['district']->id]],
            'entity_version' => 1,
        ])->assertUnprocessable()->assertJsonValidationErrorFor('draft_data.address.county_id');

        $this->putJson("/api/v1/applications/{$fixture['application']->id}", [
            'draft_data' => ['address' => $address],
            'entity_version' => 1,
        ])->assertOk();

        $this->postJson("/api/v1/applications/{$fixture['application']->id}/submit", [
            'entity_version' => 2,
            'privacy_accepted' => true,
            'declaration_accepted' => true,
            'idempotency_key' => (string) Str::uuid(),
        ])->assertOk();

        $this->assertDatabaseHas('applicant_addresses', [
            'application_id' => $fixture['application']->id,
            'address_type' => 'residence',
            'district_id' => $units['district']->id,
            'village_id' => $units['village']->id,
            'physical_address' => 'Near the synthetic market',
        ]);
    }

    public function test_canonical_import_excludes_electoral_nodes_and_records_provenance(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'uganda-admin-test-');
        file_put_contents($path, implode("\n", [
            'unit_id,source_normalized_id,name,level,unit_type,unit_class,parent_id,region_id,subregion_id',
            'region:central,,CENTRAL,REGION,REGION,ADMINISTRATIVE,,region:central,',
            'subregion:buganda,,BUGANDA,SUBREGION,SUBREGION,ADMINISTRATIVE,region:central,region:central,subregion:buganda',
            'district:kampala,D012,KAMPALA,DISTRICT_CITY,CAPITAL_CITY,ADMINISTRATIVE,subregion:buganda,region:central,subregion:buganda',
            'county:kampala,D012-C001,KAMPALA COUNTY,COUNTY,COUNTY,ADMINISTRATIVE,district:kampala,region:central,subregion:buganda',
            'subcounty:central,D012-C001-S001,CENTRAL DIVISION,SUBCOUNTY_TIER,DIVISION,ADMINISTRATIVE,county:kampala,region:central,subregion:buganda',
            'parish:central,D012-C001-S001-P001,CENTRAL WARD,PARISH_TIER,WARD,ADMINISTRATIVE,subcounty:central,region:central,subregion:buganda',
            'village:kisenyi,D012-C001-S001-P001-V001,KISENYI,VILLAGE_TIER,VILLAGE,ADMINISTRATIVE,parish:central,region:central,subregion:buganda',
            'constituency:kampala,D012-C001,KAMPALA CONSTITUENCY,CONSTITUENCY,CONSTITUENCY,ELECTORAL,district:kampala,region:central,subregion:buganda',
        ]));

        try {
            $this->artisan('erecruit:import-uganda-administrative-units', ['--path' => $path])->assertSuccessful();
            $this->artisan('erecruit:import-uganda-administrative-units', ['--path' => $path])->assertSuccessful();
        } finally {
            unlink($path);
        }

        $this->assertDatabaseCount('administrative_units', 7);
        $this->assertDatabaseMissing('administrative_units', ['code' => 'constituency:kampala']);
        $this->assertDatabaseHas('administrative_unit_imports', [
            'status' => 'completed',
            'administrative_rows' => 7,
            'electoral_rows_skipped' => 1,
        ]);
        $this->assertDatabaseHas('administrative_unit_paths', [
            'unit_id' => AdministrativeUnit::query()->where('code', 'village:kisenyi')->value('id'),
            'full_address' => 'KISENYI, CENTRAL WARD, CENTRAL DIVISION, KAMPALA COUNTY, KAMPALA, BUGANDA, CENTRAL',
        ]);
    }

    public function test_administrator_can_create_update_and_delete_only_unreferenced_units(): void
    {
        $administrator = User::factory()->create([
            'user_type' => 'hq_recruitment_administrator',
            'is_privileged' => true,
            'mfa_confirmed_at' => now(),
        ]);
        Sanctum::actingAs($administrator);

        $regionId = $this->postJson('/api/v1/admin/geography/units', [
            'code' => 'region:test', 'name' => 'TEST REGION', 'level' => 'region', 'unit_type' => 'region', 'active' => true,
        ])->assertCreated()->json('unit.id');
        $subregionId = $this->postJson('/api/v1/admin/geography/units', [
            'code' => 'subregion:test', 'name' => 'TEST SUBREGION', 'level' => 'subregion', 'unit_type' => 'subregion', 'parent_id' => $regionId, 'active' => true,
        ])->assertCreated()->json('unit.id');

        $this->putJson("/api/v1/admin/geography/units/{$subregionId}", [
            'code' => 'subregion:test', 'name' => 'RENAMED SUBREGION', 'level' => 'subregion', 'unit_type' => 'subregion', 'parent_id' => $regionId, 'active' => false,
        ])->assertOk()->assertJsonPath('unit.name', 'RENAMED SUBREGION');
        $this->deleteJson("/api/v1/admin/geography/units/{$regionId}")->assertStatus(409);
        $this->deleteJson("/api/v1/admin/geography/units/{$subregionId}")->assertOk();
        $this->deleteJson("/api/v1/admin/geography/units/{$regionId}")->assertOk();
    }

    /** @return array<string, AdministrativeUnit> */
    private function administrativeHierarchy(): array
    {
        $definitions = [
            'region' => ['region:central', 'CENTRAL', 'region', 'region', null],
            'subregion' => ['subregion:buganda', 'BUGANDA', 'subregion', 'subregion', 'region'],
            'district' => ['district:kampala', 'KAMPALA', 'district', 'capital-city', 'subregion'],
            'county' => ['county:kampala', 'KAMPALA COUNTY', 'county', 'county', 'district'],
            'subcounty' => ['subcounty:central', 'CENTRAL DIVISION', 'subcounty', 'division', 'county'],
            'parish' => ['parish:central', 'CENTRAL WARD', 'parish', 'ward', 'subcounty'],
            'village' => ['village:kisenyi', 'KISENYI', 'village', 'village', 'parish'],
        ];
        $units = [];
        foreach ($definitions as $key => [$code, $name, $level, $unitType, $parent]) {
            $units[$key] = AdministrativeUnit::query()->create([
                'code' => $code,
                'name' => $name,
                'level' => $level,
                'unit_type' => $unitType,
                'parent_id' => $parent ? $units[$parent]->id : null,
                'active' => true,
            ]);
        }
        app(AdministrativeUnitPathService::class)->rebuildAll();

        return $units;
    }
}
