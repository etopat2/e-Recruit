<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class RecruitmentGeographyImportTest extends TestCase
{
    use RefreshDatabase;

    public function test_canonical_recruitment_geography_import_is_complete_and_idempotent(): void
    {
        $handle = fopen(database_path('data/uganda_admin_units_nodes.csv'), 'rb');
        $headers = fgetcsv($handle, escape: '\\');
        $headers[0] = ltrim($headers[0], "\xEF\xBB\xBF");
        $rows = [];
        while (($values = fgetcsv($handle, escape: '\\')) !== false) {
            $row = array_combine($headers, $values);
            if ($row['level'] !== 'DISTRICT_CITY' || $row['unit_class'] !== 'ADMINISTRATIVE') {
                continue;
            }
            $rows[] = [
                'id' => (string) Str::ulid(),
                'code' => $row['unit_id'],
                'source_id' => $row['source_normalized_id'] ?: null,
                'source' => 'uganda_admin_units_nodes',
                'name' => $row['name'],
                'level' => 'district',
                'unit_type' => strtolower($row['unit_type']),
                'active' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ];
        }
        fclose($handle);
        foreach (array_chunk($rows, 100) as $chunk) {
            DB::table('administrative_units')->insert($chunk);
        }

        $this->artisan('erecruit:import-ups-recruitment-geography')->assertSuccessful();
        $this->artisan('erecruit:import-ups-recruitment-geography')->assertSuccessful();

        $this->assertDatabaseCount('prison_regions', 19);
        $this->assertDatabaseCount('prison_region_jurisdictions', 151);
        $this->assertDatabaseCount('recruitment_centres', 19);
        $this->assertDatabaseCount('district_centre_mappings', 146);
        $this->assertDatabaseCount('medical_facilities', 17);
        $this->assertDatabaseCount('recruitment_geography_imports', 1);
        $wakisoId = DB::table('administrative_units')->where('name', 'WAKISO')->value('id');
        $this->assertSame(2, DB::table('prison_region_jurisdictions')->where('administrative_unit_id', $wakisoId)->where('jurisdiction_type', 'shared')->count());
    }
}
