<?php

namespace App\Console\Commands;

use App\Models\AdministrativeUnit;
use App\Services\AdministrativeUnitPathService;
use Generator;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

#[Signature('erecruit:import-uganda-administrative-units {--path= : Override the canonical node CSV path}')]
#[Description('Import canonical Uganda administrative units while excluding all electoral units')]
class ImportUgandaAdministrativeUnits extends Command
{
    private const SOURCE = 'uganda_admin_complete_v1';

    /** @var array<string, string> */
    private const LEVELS = [
        'REGION' => 'region',
        'SUBREGION' => 'subregion',
        'DISTRICT_CITY' => 'district',
        'COUNTY' => 'county',
        'SUBCOUNTY_TIER' => 'subcounty',
        'PARISH_TIER' => 'parish',
        'VILLAGE_TIER' => 'village',
    ];

    public function handle(AdministrativeUnitPathService $paths): int
    {
        $path = (string) ($this->option('path') ?: database_path('data/uganda_admin_units_nodes.csv'));
        if (! is_file($path) || ! is_readable($path)) {
            $this->error("Administrative unit source is not readable: {$path}");

            return self::FAILURE;
        }

        DB::table('administrative_unit_imports')->where('status', 'processing')->update([
            'status' => 'failed',
            'error' => 'The previous import process was interrupted before completion.',
            'completed_at' => now(),
            'updated_at' => now(),
        ]);
        $importId = (string) Str::ulid();
        $sha256 = hash_file('sha256', $path);
        DB::table('administrative_unit_imports')->insert([
            'id' => $importId,
            'source_file' => basename($path),
            'source_sha256' => $sha256,
            'status' => 'processing',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        try {
            [$ids, $skipped] = $this->indexAdministrativeNodes($path);
            $this->assignDatabaseIds($ids);

            DB::transaction(function () use ($path, $ids, $paths): void {
                AdministrativeUnit::query()->where('source', self::SOURCE)->update(['active' => false]);
                foreach (self::LEVELS as $sourceLevel => $level) {
                    $this->importLevel($path, $ids, $sourceLevel, $level);
                }
                $paths->rebuildAll();
            }, 3);

            DB::table('administrative_unit_imports')->where('id', $importId)->update([
                'status' => 'completed',
                'administrative_rows' => count($ids),
                'electoral_rows_skipped' => $skipped,
                'completed_at' => now(),
                'updated_at' => now(),
            ]);
            $this->info(sprintf(
                'Imported %s administrative units; skipped %s electoral units. Source SHA-256: %s',
                number_format(count($ids)),
                number_format($skipped),
                $sha256,
            ));

            return self::SUCCESS;
        } catch (Throwable $exception) {
            DB::table('administrative_unit_imports')->where('id', $importId)->update([
                'status' => 'failed',
                'error' => Str::limit($exception->getMessage(), 4000, ''),
                'completed_at' => now(),
                'updated_at' => now(),
            ]);
            $this->error($exception->getMessage());

            return self::FAILURE;
        }
    }

    /** @return array{array<string, string|null>, int} */
    private function indexAdministrativeNodes(string $path): array
    {
        $ids = [];
        $skipped = 0;
        foreach ($this->rows($path) as $row) {
            if (($row['unit_class'] ?? '') === 'ELECTORAL') {
                $skipped++;

                continue;
            }
            if (($row['unit_class'] ?? '') !== 'ADMINISTRATIVE') {
                throw new RuntimeException('The canonical node CSV contains an unsupported unit class.');
            }

            $code = trim($row['unit_id'] ?? '');
            $sourceLevel = trim($row['level'] ?? '');
            if ($code === '' || ! isset(self::LEVELS[$sourceLevel])) {
                throw new RuntimeException("Unsupported administrative node {$code} at level {$sourceLevel}.");
            }
            if (array_key_exists($code, $ids)) {
                throw new RuntimeException("Duplicate canonical administrative unit ID: {$code}.");
            }
            if (trim($row['name'] ?? '') === '') {
                throw new RuntimeException("Administrative unit {$code} has no name.");
            }
            $ids[$code] = null;
        }

        return [$ids, $skipped];
    }

    /** @param array<string, string|null> $ids */
    private function assignDatabaseIds(array &$ids): void
    {
        AdministrativeUnit::query()
            ->select(['id', 'code'])
            ->chunkById(1000, function ($units) use (&$ids): void {
                foreach ($units as $unit) {
                    if (array_key_exists($unit->code, $ids)) {
                        $ids[$unit->code] = $unit->id;
                    }
                }
            });

        foreach ($ids as &$id) {
            $id ??= (string) Str::ulid();
        }
        unset($id);
    }

    /** @param array<string, string|null> $ids */
    private function importLevel(string $path, array $ids, string $sourceLevel, string $level): void
    {
        $rows = [];
        $now = now();
        foreach ($this->rows($path) as $row) {
            if (($row['unit_class'] ?? '') !== 'ADMINISTRATIVE' || ($row['level'] ?? '') !== $sourceLevel) {
                continue;
            }

            $code = trim($row['unit_id']);
            $parentCode = trim($row['parent_id'] ?? '');
            if ($level === 'region' && $parentCode !== '') {
                throw new RuntimeException("Region {$code} must not have a parent.");
            }
            if ($level !== 'region' && ! array_key_exists($parentCode, $ids)) {
                throw new RuntimeException("Administrative unit {$code} references missing administrative parent {$parentCode}.");
            }

            $rows[] = [
                'id' => $ids[$code],
                'parent_id' => $parentCode ? $ids[$parentCode] : null,
                'code' => $code,
                'source_id' => trim($row['source_normalized_id'] ?? '') ?: null,
                'source' => self::SOURCE,
                'name' => trim($row['name']),
                'level' => $level,
                'unit_type' => strtolower(str_replace([' ', '_'], '-', trim($row['unit_type'] ?? ''))),
                'active' => true,
                'created_at' => $now,
                'updated_at' => $now,
            ];
            if (count($rows) === 1000) {
                $this->upsert($rows);
                $rows = [];
            }
        }

        if ($rows !== []) {
            $this->upsert($rows);
        }
    }

    /** @param list<array<string, mixed>> $rows */
    private function upsert(array $rows): void
    {
        DB::table('administrative_units')->upsert(
            $rows,
            ['code'],
            ['parent_id', 'source_id', 'source', 'name', 'level', 'unit_type', 'active', 'updated_at'],
        );
    }

    /** @return Generator<int, array<string, string>> */
    private function rows(string $path): Generator
    {
        $handle = fopen($path, 'rb');
        if ($handle === false) {
            throw new RuntimeException("Unable to open {$path}.");
        }

        try {
            $headers = fgetcsv($handle, escape: '\\');
            $required = ['unit_id', 'source_normalized_id', 'name', 'level', 'unit_type', 'unit_class', 'parent_id'];
            if ($headers !== false) {
                $headers = array_map(fn (string $header): string => trim($header, "\xEF\xBB\xBF \t\r\n"), $headers);
            }
            if ($headers === false || array_diff($required, $headers) !== []) {
                throw new RuntimeException('The CSV does not match the documented canonical node schema.');
            }

            while (($values = fgetcsv($handle, escape: '\\')) !== false) {
                if (count($values) !== count($headers)) {
                    throw new RuntimeException('The canonical node CSV contains a malformed row.');
                }
                yield array_combine($headers, $values);
            }
        } finally {
            fclose($handle);
        }
    }
}
