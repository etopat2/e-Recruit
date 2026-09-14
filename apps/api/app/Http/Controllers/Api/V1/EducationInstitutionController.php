<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\SearchEducationInstitutionsRequest;
use App\Http\Resources\EducationInstitutionResource;
use App\Models\EducationInstitution;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Str;

class EducationInstitutionController extends Controller
{
    public function catalogue(): JsonResponse
    {
        $groups = collect(config('education.groups'))->map(fn (array $group): array => [
            'label' => $group['label'],
            'options' => collect($group['options'])->map(fn (array $level): array => collect($level)
                ->except('directory_source')
                ->all())->all(),
        ])->all();

        return response()->json(['data' => $groups]);
    }

    public function index(SearchEducationInstitutionsRequest $request): AnonymousResourceCollection
    {
        $data = $request->validated();

        $search = Str::upper(Str::ascii($data['search']));
        $escaped = str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $search);
        $limit = min(
            (int) ($data['limit'] ?? config('erecruit.institution_directory.maximum_results')),
            (int) config('erecruit.institution_directory.maximum_results'),
        );

        $institutions = EducationInstitution::query()
            ->select([
                'id', 'name', 'institution_type', 'district', 'registration_number',
                'registration_status', 'operational_status', 'source', 'source_url', 'last_verified_at',
            ])
            ->where('active', true)
            ->whereJsonContains('qualification_levels', $data['level'])
            ->where('normalized_name', 'like', "%{$escaped}%")
            ->orderByRaw(
                'CASE WHEN normalized_name = ? THEN 0 WHEN normalized_name LIKE ? THEN 1 ELSE 2 END',
                [$search, "{$escaped}%"],
            )
            ->orderBy('name')
            ->limit($limit)
            ->get();

        return EducationInstitutionResource::collection($institutions);
    }
}
