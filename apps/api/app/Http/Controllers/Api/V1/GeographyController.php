<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\AdministrativeUnit;
use App\Models\PrisonRegion;
use App\Models\RecruitmentCampaign;
use App\Models\RecruitmentCentre;
use App\Services\AuditService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\StreamedResponse;

class GeographyController extends Controller
{
    private const LEVELS = ['district', 'county', 'subcounty', 'parish', 'village'];

    public function index(Request $request): JsonResponse
    {
        $data = $request->validate([
            'level' => ['nullable', Rule::in(self::LEVELS)],
            'parent_id' => ['nullable', 'ulid'],
            'search' => ['nullable', 'string', 'max:100'],
        ]);
        $units = AdministrativeUnit::query()
            ->when($data['level'] ?? null, fn ($query, $level) => $query->where('level', $level))
            ->when(array_key_exists('parent_id', $data), fn ($query) => $query->where('parent_id', $data['parent_id']))
            ->when($data['search'] ?? null, fn ($query, $search) => $query->where(fn ($nested) => $nested->where('code', 'like', "%{$search}%")->orWhere('name', 'like', "%{$search}%")))
            ->with('parent:id,code,name,level')
            ->orderByRaw("case level when 'district' then 1 when 'county' then 2 when 'subcounty' then 3 when 'parish' then 4 else 5 end")
            ->orderBy('name')
            ->paginate(100);

        return response()->json([
            'units' => $units,
            'regions' => PrisonRegion::query()->with('centres')->orderBy('name')->get(),
            'mappings' => DB::table('district_centre_mappings as mappings')
                ->join('administrative_units as districts', 'districts.id', '=', 'mappings.district_id')
                ->join('recruitment_centres as centres', 'centres.id', '=', 'mappings.recruitment_centre_id')
                ->leftJoin('recruitment_campaigns as campaigns', 'campaigns.id', '=', 'mappings.recruitment_campaign_id')
                ->select('mappings.*', 'districts.code as district_code', 'districts.name as district_name', 'centres.code as centre_code', 'centres.name as centre_name', 'campaigns.code as campaign_code')
                ->orderBy('districts.name')->get(),
        ]);
    }

    public function storeUnit(Request $request, AuditService $audit): JsonResponse
    {
        $data = $request->validate($this->unitRules());
        $this->validateParentLevel($data);
        $unit = AdministrativeUnit::query()->create($data);
        $audit->record('geography.unit_created', $unit, after: $unit->only(['code', 'name', 'level', 'parent_id']), actor: $request->user());

        return response()->json(['unit' => $unit->load('parent')], 201);
    }

    public function updateUnit(Request $request, AdministrativeUnit $unit, AuditService $audit): JsonResponse
    {
        $data = $request->validate($this->unitRules($unit));
        $this->validateParentLevel($data, $unit);
        $before = $unit->toArray();
        $unit->update($data);
        $audit->record('geography.unit_updated', $unit, before: $before, after: $unit->toArray(), actor: $request->user());

        return response()->json(['unit' => $unit->fresh()->load('parent')]);
    }

    public function storeRegion(Request $request, AuditService $audit): JsonResponse
    {
        $data = $request->validate([
            'code' => ['required', 'string', 'max:30', 'unique:prison_regions,code'],
            'name' => ['required', 'string', 'max:255'],
            'active' => ['sometimes', 'boolean'],
        ]);
        $region = PrisonRegion::query()->create($data);
        $audit->record('geography.region_created', $region, after: $region->toArray(), actor: $request->user());

        return response()->json(['region' => $region], 201);
    }

    public function storeCentre(Request $request, AuditService $audit): JsonResponse
    {
        $data = $request->validate([
            'prison_region_id' => ['required', 'exists:prison_regions,id'],
            'code' => ['required', 'string', 'max:30', 'unique:recruitment_centres,code'],
            'name' => ['required', 'string', 'max:255'],
            'address' => ['required', 'string', 'max:1000'],
            'contact_phone' => ['nullable', 'string', 'max:30'],
            'daily_capacity' => ['nullable', 'integer', 'min:1', 'max:100000'],
            'active_from' => ['nullable', 'date'],
            'active_to' => ['nullable', 'date', 'after_or_equal:active_from'],
            'active' => ['sometimes', 'boolean'],
        ]);
        $centre = RecruitmentCentre::query()->create($data);
        $audit->record('geography.centre_created', $centre, after: $centre->toArray(), actor: $request->user());

        return response()->json(['centre' => $centre->load('region')], 201);
    }

    public function storeMapping(Request $request, AuditService $audit): JsonResponse
    {
        $data = $this->validateMapping($request->all());
        $mapping = $this->persistMapping($data, $request->user()->id);
        $audit->record('geography.mapping_created', 'district_centre_mapping', (string) $mapping->id, after: (array) $mapping, actor: $request->user());

        return response()->json(['mapping' => $mapping], 201);
    }

    public function unresolved(Request $request): JsonResponse
    {
        $request->validate(['campaign_id' => ['nullable', 'exists:recruitment_campaigns,id'], 'status' => ['nullable', 'in:open,resolved']]);

        return response()->json(['data' => DB::table('unresolved_jurisdictions')
            ->when($request->input('campaign_id'), fn ($query, $campaignId) => $query->where('recruitment_campaign_id', $campaignId))
            ->when($request->input('status'), fn ($query, $status) => $query->where('status', $status))
            ->orderByDesc('created_at')->paginate(100)]);
    }

    public function resolve(Request $request, string $unresolved, AuditService $audit): JsonResponse
    {
        $record = DB::table('unresolved_jurisdictions')->where('id', $unresolved)->first();
        abort_if($record === null, 404);
        abort_if($record->status !== 'open', 409, 'This jurisdiction item is already resolved.');
        $data = $this->validateMapping([
            ...$request->all(),
            'recruitment_campaign_id' => $record->recruitment_campaign_id,
            'district_id' => $request->input('district_id', $record->district_id),
        ]);
        $request->validate(['resolution_note' => ['required', 'string', 'max:2000']]);

        $mapping = DB::transaction(function () use ($data, $request, $record): object {
            $mapping = $this->persistMapping($data, $request->user()->id);
            DB::table('unresolved_jurisdictions')->where('id', $record->id)->update([
                'district_id' => $data['district_id'],
                'status' => 'resolved',
                'resolution_note' => $request->string('resolution_note'),
                'resolved_by' => $request->user()->id,
                'resolved_at' => now(),
                'updated_at' => now(),
            ]);

            return $mapping;
        });
        $audit->record('geography.jurisdiction_resolved', 'unresolved_jurisdiction', $record->id, before: (array) $record, after: ['mapping_id' => $mapping->id], actor: $request->user(), reason: $request->string('resolution_note'));

        return response()->json(['mapping' => $mapping, 'status' => 'resolved']);
    }

    public function template(string $type, Request $request): StreamedResponse|BinaryFileResponse
    {
        abort_unless(in_array($type, ['administrative-units', 'district-centre-mappings'], true), 404);
        $format = $request->validate(['format' => ['nullable', 'in:csv,xlsx']])['format'] ?? 'csv';
        $headers = $type === 'administrative-units'
            ? ['code', 'name', 'level', 'parent_code', 'effective_from', 'effective_to', 'active']
            : ['campaign_code', 'district_code', 'centre_code', 'effective_from', 'effective_to'];
        if ($format === 'csv') {
            return response()->streamDownload(function () use ($headers): void {
                $stream = fopen('php://output', 'wb');
                fputcsv($stream, $headers, escape: '\\');
                fclose($stream);
            }, "{$type}-template.csv", ['Content-Type' => 'text/csv; charset=UTF-8']);
        }

        $spreadsheet = new Spreadsheet;
        $spreadsheet->getActiveSheet()->fromArray($headers);
        $path = tempnam(sys_get_temp_dir(), 'ups-geography-template-');
        (new Xlsx($spreadsheet))->save($path);
        $spreadsheet->disconnectWorksheets();

        return response()->download($path, "{$type}-template.xlsx")->deleteFileAfterSend(true);
    }

    public function import(string $type, Request $request, AuditService $audit): JsonResponse
    {
        abort_unless(in_array($type, ['administrative-units', 'district-centre-mappings'], true), 404);
        $data = $request->validate(['file' => ['required', 'file', 'mimes:csv,txt,xlsx', 'max:10240']]);
        $rows = $this->readRows($data['file']->getRealPath(), $data['file']->getClientOriginalExtension());
        [$normalised, $errors] = $type === 'administrative-units'
            ? $this->validateUnitRows($rows)
            : $this->validateMappingRows($rows);
        if ($errors !== []) {
            return response()->json([
                'status' => 'validation_failed',
                'imported' => 0,
                'errors' => $errors,
            ], 422);
        }

        DB::transaction(function () use ($type, $normalised, $request): void {
            if ($type === 'administrative-units') {
                $order = array_flip(self::LEVELS);
                usort($normalised, fn (array $left, array $right): int => $order[$left['level']] <=> $order[$right['level']]);
                foreach ($normalised as $row) {
                    $parentId = $row['parent_code'] ? AdministrativeUnit::query()->where('code', $row['parent_code'])->value('id') : null;
                    AdministrativeUnit::query()->updateOrCreate(['code' => $row['code']], [
                        'name' => $row['name'],
                        'level' => $row['level'],
                        'parent_id' => $parentId,
                        'effective_from' => $row['effective_from'] ?: null,
                        'effective_to' => $row['effective_to'] ?: null,
                        'active' => $row['active'],
                    ]);
                }
            } else {
                foreach ($normalised as $row) {
                    $this->persistMapping([
                        'recruitment_campaign_id' => $row['campaign_code'] ? RecruitmentCampaign::query()->where('code', $row['campaign_code'])->value('id') : null,
                        'district_id' => AdministrativeUnit::query()->where('code', $row['district_code'])->value('id'),
                        'recruitment_centre_id' => RecruitmentCentre::query()->where('code', $row['centre_code'])->value('id'),
                        'effective_from' => $row['effective_from'],
                        'effective_to' => $row['effective_to'] ?: null,
                    ], $request->user()->id);
                }
            }
        });
        $audit->record('geography.import_completed', 'reference_import', $type, after: ['row_count' => count($normalised), 'source_sha256' => hash_file('sha256', $data['file']->getRealPath())], actor: $request->user());

        return response()->json(['status' => 'imported', 'imported' => count($normalised), 'errors' => []]);
    }

    /** @return array<string, array<int, mixed>|string> */
    private function unitRules(?AdministrativeUnit $unit = null): array
    {
        return [
            'parent_id' => ['nullable', 'exists:administrative_units,id', Rule::notIn(array_filter([$unit?->id]))],
            'code' => ['required', 'string', 'max:50', Rule::unique('administrative_units')->ignore($unit?->id)],
            'name' => ['required', 'string', 'max:255'],
            'level' => ['required', Rule::in(self::LEVELS)],
            'effective_from' => ['nullable', 'date'],
            'effective_to' => ['nullable', 'date', 'after_or_equal:effective_from'],
            'active' => ['sometimes', 'boolean'],
        ];
    }

    /** @param array<string, mixed> $data */
    private function validateParentLevel(array $data, ?AdministrativeUnit $unit = null): void
    {
        $level = $data['level'];
        if ($level === 'district') {
            Validator::make($data, ['parent_id' => ['nullable', 'prohibited']])->validate();

            return;
        }
        Validator::make($data, ['parent_id' => ['required']])->validate();
        $parent = AdministrativeUnit::query()->findOrFail($data['parent_id']);
        $expected = self::LEVELS[array_search($level, self::LEVELS, true) - 1];
        if ($parent->level !== $expected || ($unit && $parent->id === $unit->id)) {
            Validator::make([], ['parent_id' => ['required']])->after(fn ($validator) => $validator->errors()->add('parent_id', "A {$level} must have a {$expected} parent."))->validate();
        }
    }

    /** @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    private function validateMapping(array $input): array
    {
        $data = Validator::make($input, [
            'recruitment_campaign_id' => ['nullable', 'exists:recruitment_campaigns,id'],
            'district_id' => ['required', 'exists:administrative_units,id'],
            'recruitment_centre_id' => ['required', 'exists:recruitment_centres,id'],
            'effective_from' => ['required', 'date'],
            'effective_to' => ['nullable', 'date', 'after_or_equal:effective_from'],
        ])->validate();
        if (AdministrativeUnit::query()->whereKey($data['district_id'])->value('level') !== 'district') {
            throw ValidationException::withMessages(['district_id' => 'Only a district can be mapped to a recruitment centre.']);
        }

        return $data;
    }

    /** @param array<string, mixed> $data */
    private function persistMapping(array $data, int $actorId): object
    {
        $key = [
            'recruitment_campaign_id' => $data['recruitment_campaign_id'] ?? null,
            'district_id' => $data['district_id'],
            'effective_from' => $data['effective_from'],
        ];
        $id = DB::table('district_centre_mappings')->where($key)->value('id') ?? (string) Str::ulid();
        DB::table('district_centre_mappings')->updateOrInsert($key, [
            'id' => $id,
            'recruitment_centre_id' => $data['recruitment_centre_id'],
            'effective_to' => $data['effective_to'] ?? null,
            'created_by' => $actorId,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return DB::table('district_centre_mappings')->where('id', $id)->first();
    }

    /** @return list<array<string, string|int>> */
    private function readRows(string $path, string $extension): array
    {
        $reader = IOFactory::createReader(strtolower($extension) === 'xlsx' ? 'Xlsx' : 'Csv');
        $sheet = $reader->load($path)->getActiveSheet()->toArray(null, true, true, false);
        $headings = array_map(fn ($heading): string => Str::snake(trim((string) $heading, "\xEF\xBB\xBF \t\r\n")), array_shift($sheet) ?? []);
        $rows = [];
        foreach ($sheet as $index => $values) {
            if (collect($values)->every(fn ($value): bool => trim((string) $value) === '')) {
                continue;
            }
            $values = array_pad($values, count($headings), null);
            $rows[] = [...array_combine($headings, array_slice($values, 0, count($headings))), '__row' => $index + 2];
        }

        return $rows;
    }

    /** @param list<array<string, mixed>> $rows
     * @return array{list<array<string, mixed>>, list<array<string, mixed>>}
     */
    private function validateUnitRows(array $rows): array
    {
        $errors = [];
        $normalised = [];
        $incomingCodes = array_map(fn (array $row): string => strtoupper(trim((string) ($row['code'] ?? ''))), $rows);
        foreach ($rows as $row) {
            $candidate = [
                'code' => strtoupper(trim((string) ($row['code'] ?? ''))),
                'name' => trim((string) ($row['name'] ?? '')),
                'level' => strtolower(trim((string) ($row['level'] ?? ''))),
                'parent_code' => strtoupper(trim((string) ($row['parent_code'] ?? ''))),
                'effective_from' => trim((string) ($row['effective_from'] ?? '')),
                'effective_to' => trim((string) ($row['effective_to'] ?? '')),
                'active' => $this->booleanValue($row['active'] ?? true),
            ];
            $validator = Validator::make($candidate, [
                'code' => ['required', 'max:50'],
                'name' => ['required', 'max:255'],
                'level' => ['required', Rule::in(self::LEVELS)],
                'effective_from' => ['nullable', 'date'],
                'effective_to' => ['nullable', 'date', 'after_or_equal:effective_from'],
                'active' => ['required', 'boolean'],
            ]);
            if (! $validator->fails() && $candidate['level'] !== 'district') {
                $parent = AdministrativeUnit::query()->where('code', $candidate['parent_code'])->first();
                $expected = self::LEVELS[array_search($candidate['level'], self::LEVELS, true) - 1];
                $incomingParentIndex = array_search($candidate['parent_code'], $incomingCodes, true);
                $incomingParentLevel = $incomingParentIndex === false ? null : strtolower(trim((string) ($rows[$incomingParentIndex]['level'] ?? '')));
                if ($candidate['parent_code'] === '' || ($parent?->level !== $expected && $incomingParentLevel !== $expected)) {
                    $validator->errors()->add('parent_code', "Parent must reference an existing or imported {$expected}.");
                }
            }
            if (count(array_keys($incomingCodes, $candidate['code'], true)) > 1) {
                $validator->errors()->add('code', 'The file contains a duplicate code.');
            }
            if ($validator->errors()->isNotEmpty()) {
                $errors[] = ['row' => $row['__row'], 'errors' => $validator->errors()->toArray()];
            } else {
                $normalised[] = $candidate;
            }
        }

        return [$normalised, $errors];
    }

    /** @param list<array<string, mixed>> $rows
     * @return array{list<array<string, mixed>>, list<array<string, mixed>>}
     */
    private function validateMappingRows(array $rows): array
    {
        $errors = [];
        $normalised = [];
        foreach ($rows as $row) {
            $candidate = [
                'campaign_code' => strtoupper(trim((string) ($row['campaign_code'] ?? ''))),
                'district_code' => strtoupper(trim((string) ($row['district_code'] ?? ''))),
                'centre_code' => strtoupper(trim((string) ($row['centre_code'] ?? ''))),
                'effective_from' => trim((string) ($row['effective_from'] ?? '')),
                'effective_to' => trim((string) ($row['effective_to'] ?? '')),
            ];
            $validator = Validator::make($candidate, [
                'district_code' => ['required', Rule::exists('administrative_units', 'code')->where('level', 'district')],
                'centre_code' => ['required', 'exists:recruitment_centres,code'],
                'effective_from' => ['required', 'date'],
                'effective_to' => ['nullable', 'date', 'after_or_equal:effective_from'],
            ]);
            if ($candidate['campaign_code'] !== '' && ! RecruitmentCampaign::query()->where('code', $candidate['campaign_code'])->exists()) {
                $validator->errors()->add('campaign_code', 'The campaign code was not found.');
            }
            if ($validator->errors()->isNotEmpty()) {
                $errors[] = ['row' => $row['__row'], 'errors' => $validator->errors()->toArray()];
            } else {
                $normalised[] = $candidate;
            }
        }

        return [$normalised, $errors];
    }

    private function booleanValue(mixed $value): bool|string
    {
        if (is_bool($value)) {
            return $value;
        }
        $normalised = strtolower(trim((string) $value));
        if (in_array($normalised, ['1', 'true', 'yes', 'active'], true)) {
            return true;
        }
        if (in_array($normalised, ['0', 'false', 'no', 'inactive'], true)) {
            return false;
        }

        return (string) $value;
    }
}
