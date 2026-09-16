<?php

namespace App\Console\Commands;

use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;

#[Signature('erecruit:import-ups-recruitment-geography')]
#[Description('Import the versioned UPS recruitment regions, jurisdictions, centres, and medical facilities reference data')]
class ImportUpsRecruitmentGeography extends Command
{
    private const DatasetVersion = 'ups-recruitment-geography-2025-v1';

    private const SourceHashes = [
        'ups_district_centre_mappings.csv' => '5d98f685c03bffc1508f5977fd6e4321a2cec857124ede3ce5886092f56cba31',
        'ups_medical_facilities.csv' => '436c0612e3ff06f9994206256bb2aeb63a79b171b6c413707447be018a431c43',
        'ups_prison_regions.csv' => 'e8c82300a57328161cbe2906224a6905355ccbb56a6874fcff53ff269f730f76',
        'ups_prison_region_jurisdictions.csv' => '83399067957efe8e6126cf6334c51017801a9f0c8762f18264311638331048bd',
        'ups_recruitment_centres.csv' => '3789f37da68970f5c266ad8be6a8a01ca19cd8e195defc0ed43134f6a40cc0a0',
    ];

    private const UnitAliases = [
        'LUWERO' => 'LUWEERO',
        'SEMBABULE' => 'SSEMBABULE',
    ];

    /** @var array<string, string> */
    private array $unitIds = [];

    public function handle(): int
    {
        $sources = [];
        foreach (self::SourceHashes as $filename => $expectedHash) {
            $path = database_path("data/{$filename}");
            if (! is_file($path)) {
                $this->error("Required source file is missing: {$filename}");

                return self::FAILURE;
            }
            $hash = hash_file('sha256', $path);
            if (! hash_equals($expectedHash, $hash)) {
                $this->error("Source integrity check failed for {$filename}.");

                return self::FAILURE;
            }
            $sources[$filename] = $this->readCsv($path);
        }

        $counts = DB::transaction(function () use ($sources): array {
            $regionIds = [];
            foreach ($sources['ups_prison_regions.csv'] as $row) {
                $regionIds[$row['code']] = $this->upsertUlid('prison_regions', ['code' => $row['code']], [
                    'name' => $row['name'],
                    'headquarters' => $row['headquarters'],
                    'source_metadata' => $this->json(['dataset' => self::DatasetVersion, 'source' => $row['source'], 'interpretation' => $row['interpretation']]),
                    'active' => true,
                ]);
            }

            $centreIds = [];
            foreach ($sources['ups_recruitment_centres.csv'] as $row) {
                $hostUnitId = $this->unitId($row['host_unit']);
                $centreIds[$row['code']] = $this->upsertUlid('recruitment_centres', ['code' => $row['code']], [
                    'prison_region_id' => $regionIds[$row['region_code']],
                    'host_administrative_unit_id' => $hostUnitId,
                    'name' => $row['name'],
                    'host_locality' => $row['host_locality'],
                    'address' => $row['host_locality'].', '.$row['host_unit'],
                    'source_metadata' => $this->json([
                        'dataset' => self::DatasetVersion,
                        'catchments_served' => $row['catchments_served'],
                        'source' => $row['source'],
                        'notes' => $row['notes'],
                    ]),
                    'active_from' => '2025-01-01',
                    'active' => true,
                ]);
            }

            foreach ($sources['ups_prison_region_jurisdictions.csv'] as $row) {
                $unitId = $this->unitId($row['political_unit']);
                $this->upsertUlid('prison_region_jurisdictions', [
                    'prison_region_id' => $regionIds[$row['region_code']],
                    'administrative_unit_id' => $unitId,
                ], [
                    'jurisdiction_type' => $row['jurisdiction_type'],
                    'source_metadata' => $this->json(['dataset' => self::DatasetVersion, 'source' => $row['source']]),
                ]);
            }

            foreach ($sources['ups_district_centre_mappings.csv'] as $row) {
                $districtId = $this->unitId($row['political_unit']);
                $this->upsertUlid('district_centre_mappings', [
                    'recruitment_campaign_id' => null,
                    'district_id' => $districtId,
                    'effective_from' => '2025-01-01',
                ], [
                    'recruitment_centre_id' => $centreIds[$row['centre_code']],
                    'effective_to' => null,
                    'created_by' => null,
                    'source_metadata' => $this->json([
                        'dataset' => self::DatasetVersion,
                        'prisons_catchment' => $row['prisons_catchment'],
                        'mapping_basis' => $row['mapping_basis'],
                        'centre_review' => $row['centre_review'],
                        'source' => $row['source'],
                    ]),
                ]);
            }

            foreach ($sources['ups_medical_facilities.csv'] as $row) {
                $this->upsertUlid('medical_facilities', ['code' => $row['code']], [
                    'name' => $row['name'],
                    'location' => $row['location'],
                    'host_administrative_unit_id' => $this->unitId($row['host_unit']),
                    'prison_region_id' => $row['region_code'] === '' ? null : $regionIds[$row['region_code']],
                    'region_attribution' => $row['region_attribution'],
                    'referral_rule' => $row['referral_rule'],
                    'source_metadata' => $this->json([
                        'dataset' => self::DatasetVersion,
                        'attribution_basis' => $row['attribution_basis'],
                        'source' => $row['source'],
                        'allocation_limitation' => $row['allocation_limitation'],
                        'notes' => $row['notes'],
                    ]),
                    'active' => true,
                ]);
            }

            $counts = [
                'regions' => count($sources['ups_prison_regions.csv']),
                'jurisdictions' => count($sources['ups_prison_region_jurisdictions.csv']),
                'centres' => count($sources['ups_recruitment_centres.csv']),
                'district_centre_mappings' => count($sources['ups_district_centre_mappings.csv']),
                'medical_facilities' => count($sources['ups_medical_facilities.csv']),
            ];
            DB::table('recruitment_geography_imports')->updateOrInsert(
                ['dataset_version' => self::DatasetVersion],
                [
                    'id' => DB::table('recruitment_geography_imports')->where('dataset_version', self::DatasetVersion)->value('id') ?? (string) Str::ulid(),
                    'source_hashes' => $this->json(self::SourceHashes),
                    'record_counts' => $this->json($counts),
                    'unresolved_units' => $this->json([]),
                    'imported_at' => now(),
                    'created_at' => DB::table('recruitment_geography_imports')->where('dataset_version', self::DatasetVersion)->value('created_at') ?? now(),
                    'updated_at' => now(),
                ],
            );

            return $counts;
        }, 3);

        $this->info('UPS recruitment geography imported: '.collect($counts)->map(fn (int $count, string $type): string => "{$count} {$type}")->implode(', ').'.');

        return self::SUCCESS;
    }

    /** @return list<array<string, string>> */
    private function readCsv(string $path): array
    {
        $handle = fopen($path, 'rb');
        throw_if($handle === false, RuntimeException::class, "Unable to read {$path}.");
        $headers = fgetcsv($handle, escape: '\\');
        throw_if($headers === false, RuntimeException::class, "Source file {$path} has no header.");
        $headers[0] = ltrim($headers[0], "\xEF\xBB\xBF");
        $rows = [];
        while (($values = fgetcsv($handle, escape: '\\')) !== false) {
            if (count($values) === 1 && $values[0] === null) {
                continue;
            }
            $rows[] = array_combine($headers, array_pad($values, count($headers), ''));
        }
        fclose($handle);

        return $rows;
    }

    private function unitId(string $name): string
    {
        $key = Str::upper(trim($name));
        $key = self::UnitAliases[$key] ?? $key;
        if (isset($this->unitIds[$key])) {
            return $this->unitIds[$key];
        }
        $id = DB::table('administrative_units')->where('level', 'district')->whereRaw('UPPER(name) = ?', [$key])->value('id');
        throw_if($id === null, RuntimeException::class, "Administrative district or city not found: {$name}.");

        return $this->unitIds[$key] = (string) $id;
    }

    /** @param array<string, mixed> $identity
     * @param  array<string, mixed>  $values
     */
    private function upsertUlid(string $table, array $identity, array $values): string
    {
        $existing = DB::table($table)->where($identity)->first();
        $id = $existing->id ?? (string) Str::ulid();
        DB::table($table)->updateOrInsert($identity, [
            'id' => $id,
            ...$values,
            'created_at' => $existing->created_at ?? now(),
            'updated_at' => now(),
        ]);

        return (string) $id;
    }

    /** @param array<string, mixed> $value */
    private function json(array $value): string
    {
        return json_encode($value, JSON_THROW_ON_ERROR);
    }
}
