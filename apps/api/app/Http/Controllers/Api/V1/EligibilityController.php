<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Eligibility\EligibilityEngine;
use App\Http\Controllers\Controller;
use App\Models\Application;
use App\Models\EligibilityRun;
use App\Models\VerifiedValue;
use App\Services\AuditService;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class EligibilityController extends Controller
{
    public function store(Application $application, EligibilityEngine $engine, AuditService $audit): JsonResponse
    {
        $this->authorize('view', $application);
        abort_unless(request()->user()->hasRole('verification_officer', 'data_clerk', 'hq_recruitment_administrator'), 403);
        $application->loadMissing('post');
        $verifiedValues = VerifiedValue::query()
            ->whereBelongsTo($application)
            ->where('current', true)
            ->get()
            ->mapWithKeys(fn (VerifiedValue $value): array => [$value->field_key => data_get($value->verified_value, 'value')])
            ->all();
        $result = $engine->evaluate($application->post->eligibility_configuration, $verifiedValues);
        $run = DB::transaction(function () use ($application, $result, $verifiedValues): EligibilityRun {
            $run = EligibilityRun::query()->create([
                'application_id' => $application->id,
                'campaign_version_id' => $application->campaign_version_id,
                'status' => $result['outcome'],
                'input_snapshot' => $verifiedValues,
                'input_fingerprint' => $result['fingerprint'],
                'run_by' => request()->user()->id,
                'run_at' => now(),
            ]);
            foreach ($result['results'] as $ruleResult) {
                DB::table('eligibility_rule_results')->insert([
                    'id' => (string) Str::ulid(),
                    'eligibility_run_id' => $run->id,
                    'rule_id' => $ruleResult['rule_id'],
                    'rule_version' => $ruleResult['rule_version'],
                    'outcome' => $ruleResult['outcome'],
                    'explanation' => $ruleResult['explanation'],
                    'input_values' => json_encode(['value' => $ruleResult['input_value']], JSON_THROW_ON_ERROR),
                    'evidence_references' => json_encode($ruleResult['evidence_references'], JSON_THROW_ON_ERROR),
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }

            return $run;
        });
        $audit->record('eligibility.run', $application, actor: request()->user(), after: ['outcome' => $result['outcome'], 'run_id' => $run->id]);

        return response()->json(['run' => $run, 'results' => $result['results']], 201);
    }
}
