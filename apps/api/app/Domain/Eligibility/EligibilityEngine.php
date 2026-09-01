<?php

namespace App\Domain\Eligibility;

use App\Support\CanonicalJson;
use Carbon\CarbonImmutable;
use InvalidArgumentException;

class EligibilityEngine
{
    public function __construct(private CanonicalJson $canonicalJson) {}

    /**
     * @param  list<array<string, mixed>>  $rules
     * @param  array<string, mixed>  $verifiedValues
     * @return array{outcome: string, fingerprint: string, results: list<array<string, mixed>>}
     */
    public function evaluate(array $rules, array $verifiedValues): array
    {
        $results = array_map(
            fn (array $rule): array => $this->evaluateRule($rule, $verifiedValues),
            $rules,
        );
        $outcomes = array_column($results, 'outcome');
        $outcome = match (true) {
            in_array('FAIL', $outcomes, true) => 'FAIL',
            in_array('FLAG/REVIEW', $outcomes, true) => 'FLAG/REVIEW',
            default => 'PASS',
        };

        return [
            'outcome' => $outcome,
            'fingerprint' => $this->canonicalJson->hash(['rules' => $rules, 'verified_values' => $verifiedValues]),
            'results' => $results,
        ];
    }

    /**
     * @param  array<string, mixed>  $rule
     * @param  array<string, mixed>  $values
     * @return array<string, mixed>
     */
    private function evaluateRule(array $rule, array $values): array
    {
        foreach (['id', 'version', 'type'] as $requiredKey) {
            if (! array_key_exists($requiredKey, $rule)) {
                throw new InvalidArgumentException("Eligibility rule is missing {$requiredKey}.");
            }
        }

        $field = (string) ($rule['field'] ?? '');
        $value = $values[$field] ?? null;
        $result = match ($rule['type']) {
            'required_presence' => $this->requiredPresence($value, $rule),
            'age_range' => $this->ageRange($value, $rule),
            'allowed_values' => $this->allowedValues($value, $rule),
            'boolean_true' => $this->booleanTrue($value),
            'minimum_count' => $this->minimumCount($value, $rule),
            'verified_skill' => $this->verifiedSkill($value, $rule),
            'equivalent_qualification' => ['outcome' => 'FLAG/REVIEW', 'explanation' => 'Equivalent or unusual qualification requires authorised review.'],
            default => throw new InvalidArgumentException("Unsupported eligibility rule type [{$rule['type']}]."),
        };

        return [
            'rule_id' => (string) $rule['id'],
            'rule_version' => (int) $rule['version'],
            'field' => $field,
            'input_value' => $value,
            'evidence_references' => $rule['evidence_references'] ?? [],
            ...$result,
        ];
    }

    /** @param array<string, mixed> $rule
     * @return array{outcome: string, explanation: string}
     */
    private function requiredPresence(mixed $value, array $rule): array
    {
        if (($rule['source_status'] ?? null) === 'UNREADABLE/LOW CONFIDENCE') {
            return ['outcome' => 'FLAG/REVIEW', 'explanation' => 'The required value is low confidence and requires human review.'];
        }

        $present = ! ($value === null || $value === '' || $value === []);

        return $present
            ? ['outcome' => 'PASS', 'explanation' => 'Required verified value is present.']
            : ['outcome' => (string) ($rule['missing_outcome'] ?? 'FAIL'), 'explanation' => 'Required verified value is missing.'];
    }

    /** @param array<string, mixed> $rule
     * @return array{outcome: string, explanation: string}
     */
    private function ageRange(mixed $value, array $rule): array
    {
        if ($value === null) {
            return ['outcome' => 'FLAG/REVIEW', 'explanation' => 'Verified date of birth is unavailable.'];
        }

        try {
            $dateOfBirth = CarbonImmutable::parse((string) $value)->startOfDay();
            $cutoff = CarbonImmutable::parse((string) $rule['cutoff_date'])->startOfDay();
        } catch (\Throwable) {
            return ['outcome' => 'FLAG/REVIEW', 'explanation' => 'Date of birth or campaign cut-off date cannot be interpreted safely.'];
        }

        $age = (int) floor($dateOfBirth->diffInYears($cutoff));
        $minimum = (int) $rule['minimum'];
        $maximum = (int) $rule['maximum'];
        $passes = $age >= $minimum && $age <= $maximum;

        return $passes
            ? ['outcome' => 'PASS', 'explanation' => "Age {$age} is within the configured {$minimum}–{$maximum} range at cut-off."]
            : ['outcome' => 'FAIL', 'explanation' => "Age {$age} is outside the configured {$minimum}–{$maximum} range at cut-off."];
    }

    /** @param array<string, mixed> $rule
     * @return array{outcome: string, explanation: string}
     */
    private function allowedValues(mixed $value, array $rule): array
    {
        if ($value === null) {
            return ['outcome' => 'FLAG/REVIEW', 'explanation' => 'A verified value is not available for this rule.'];
        }

        $allowed = array_map(fn (mixed $item): string => mb_strtoupper(trim((string) $item)), $rule['allowed'] ?? []);
        $passes = in_array(mb_strtoupper(trim((string) $value)), $allowed, true);

        return $passes
            ? ['outcome' => 'PASS', 'explanation' => 'Verified value is allowed by the campaign rule.']
            : ['outcome' => 'FAIL', 'explanation' => 'Verified value is not allowed by the campaign rule.'];
    }

    /** @return array{outcome: string, explanation: string} */
    private function booleanTrue(mixed $value): array
    {
        return $value === true
            ? ['outcome' => 'PASS', 'explanation' => 'Required declaration or completion state is confirmed.']
            : ['outcome' => 'FAIL', 'explanation' => 'Required declaration or completion state is not confirmed.'];
    }

    /** @param array<string, mixed> $rule
     * @return array{outcome: string, explanation: string}
     */
    private function minimumCount(mixed $value, array $rule): array
    {
        $count = is_countable($value) ? count($value) : (int) $value;
        $minimum = (int) $rule['minimum'];

        return $count >= $minimum
            ? ['outcome' => 'PASS', 'explanation' => "Verified count {$count} meets the configured minimum {$minimum}."]
            : ['outcome' => 'FAIL', 'explanation' => "Verified count {$count} is below the configured minimum {$minimum}."];
    }

    /** @param array<string, mixed> $rule
     * @return array{outcome: string, explanation: string}
     */
    private function verifiedSkill(mixed $value, array $rule): array
    {
        $skills = is_array($value) ? $value : [];
        $requiredCode = (string) $rule['skill_code'];
        foreach ($skills as $skill) {
            if (($skill['code'] ?? null) === $requiredCode && ($skill['status'] ?? null) === 'VERIFIED') {
                return ['outcome' => 'PASS', 'explanation' => 'Required special-skill evidence is verified.'];
            }
        }

        return ['outcome' => 'FLAG/REVIEW', 'explanation' => 'Required special-skill evidence is not yet verified.'];
    }
}
