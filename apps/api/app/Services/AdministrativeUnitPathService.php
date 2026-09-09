<?php

namespace App\Services;

use App\Models\AdministrativeUnit;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class AdministrativeUnitPathService
{
    /** @var array<string, string> */
    private const LEVEL_COLUMNS = [
        'region' => 'region_id',
        'subregion' => 'subregion_id',
        'district' => 'district_id',
        'county' => 'county_id',
        'subcounty' => 'subcounty_id',
        'parish' => 'parish_id',
        'village' => 'village_id',
    ];

    public function rebuildAll(): int
    {
        DB::table('administrative_unit_paths')->delete();
        $written = 0;

        foreach (array_keys(self::LEVEL_COLUMNS) as $level) {
            $this->pathQuery()
                ->where('administrative_units.level', $level)
                ->chunkById(1000, function ($units) use (&$written): void {
                    $this->upsert($this->pathRows($units));
                    $written += count($units);
                }, 'administrative_units.id', 'id');
        }

        return $written;
    }

    public function rebuildForUnitAndDescendants(AdministrativeUnit $unit): int
    {
        $frontier = [$unit->id];
        $written = 0;

        while ($frontier !== []) {
            foreach (array_chunk($frontier, 1000) as $ids) {
                $units = $this->pathQuery()->whereIn('administrative_units.id', $ids)->get();
                $this->upsert($this->pathRows($units));
                $written += count($units);
            }

            $children = [];
            foreach (array_chunk($frontier, 1000) as $parentIds) {
                $children = [
                    ...$children,
                    ...AdministrativeUnit::query()->whereIn('parent_id', $parentIds)->pluck('id')->all(),
                ];
            }
            $frontier = $children;
        }

        return $written;
    }

    /** @return Builder<AdministrativeUnit> */
    private function pathQuery(): Builder
    {
        return AdministrativeUnit::query()
            ->leftJoin('administrative_unit_paths as parent_paths', 'parent_paths.unit_id', '=', 'administrative_units.parent_id')
            ->select([
                'administrative_units.id',
                'administrative_units.code',
                'administrative_units.name',
                'administrative_units.level',
                'administrative_units.unit_type',
                ...collect(array_values(self::LEVEL_COLUMNS))
                    ->map(fn (string $column) => "parent_paths.{$column} as parent_{$column}")
                    ->all(),
                'parent_paths.full_address as parent_full_address',
                'parent_paths.search_text as parent_search_text',
            ]);
    }

    /**
     * @param  iterable<int, AdministrativeUnit>  $units
     * @return list<array<string, mixed>>
     */
    private function pathRows(iterable $units): array
    {
        $now = now();
        $rows = [];
        foreach ($units as $unit) {
            $columns = [];
            foreach (array_values(self::LEVEL_COLUMNS) as $column) {
                $columns[$column] = $unit->{"parent_{$column}"};
            }
            $columns[self::LEVEL_COLUMNS[$unit->level]] = $unit->id;
            $ownSearch = implode(' ', array_filter([$unit->name, $unit->code, $unit->unit_type]));
            $rows[] = [
                'unit_id' => $unit->id,
                ...$columns,
                'full_address' => $unit->name.($unit->parent_full_address ? ", {$unit->parent_full_address}" : ''),
                'search_text' => Str::lower(Str::ascii($ownSearch.' '.($unit->parent_search_text ?? ''))),
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }

        return $rows;
    }

    /** @param list<array<string, mixed>> $rows */
    private function upsert(array $rows): void
    {
        if ($rows === []) {
            return;
        }

        DB::table('administrative_unit_paths')->upsert(
            $rows,
            ['unit_id'],
            [
                'region_id', 'subregion_id', 'district_id', 'county_id',
                'subcounty_id', 'parish_id', 'village_id', 'full_address',
                'search_text', 'updated_at',
            ],
        );
    }
}
