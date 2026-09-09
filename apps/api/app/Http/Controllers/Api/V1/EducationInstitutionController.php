<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\SearchEducationInstitutionsRequest;
use App\Http\Resources\EducationInstitutionResource;
use App\Models\EducationInstitution;
use App\Services\OfficialEducationInstitutionDirectory;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Str;

class EducationInstitutionController extends Controller
{
    public function index(
        SearchEducationInstitutionsRequest $request,
        OfficialEducationInstitutionDirectory $directory,
    ): AnonymousResourceCollection {
        $data = $request->validated();
        $directory->refreshSchoolMatches($data['level'], $data['search']);

        $search = Str::upper(Str::ascii($data['search']));
        $escaped = str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $search);
        $limit = min(
            (int) ($data['limit'] ?? config('erecruit.institution_directory.maximum_results')),
            (int) config('erecruit.institution_directory.maximum_results'),
        );

        $institutions = EducationInstitution::query()
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
