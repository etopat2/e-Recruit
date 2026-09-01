<?php

namespace App\Domain\Selection;

use App\Support\CanonicalJson;
use DomainException;

class SelectionService
{
    public function __construct(private CanonicalJson $canonicalJson) {}

    /**
     * @param  list<array<string, mixed>>  $candidates
     * @param  array<string, mixed>  $policy
     * @return array{fingerprint: string, ranked: list<array<string, mixed>>, outcomes: list<array<string, mixed>>}
     */
    public function run(array $candidates, array $policy, bool $offlineDataReady): array
    {
        if (! $offlineDataReady) {
            throw new DomainException('Selection certification is blocked while required offline data or conflicts remain unresolved.');
        }

        $totalSlots = (int) ($policy['total_slots'] ?? 0);
        if ($totalSlots < 0) {
            throw new DomainException('Selection total cannot be negative.');
        }

        $eligible = array_values(array_filter(
            $candidates,
            fn (array $candidate): bool => ($candidate['eligible'] ?? true) === true
                && ($candidate['assessment_complete'] ?? true) === true,
        ));
        $tieBreakers = $policy['tie_breakers'] ?? [];
        usort($eligible, fn (array $left, array $right): int => $this->compareCandidates($left, $right, $tieBreakers));

        $ranked = [];
        foreach ($eligible as $index => $candidate) {
            $ranked[] = [
                ...$candidate,
                'merit_rank' => $index + 1,
            ];
        }

        $selected = [];
        $selectionTrace = [];
        foreach ($policy['skill_reservations'] ?? [] as $reservation) {
            $slots = (int) ($reservation['slots'] ?? 0);
            $minimumScore = (float) ($reservation['minimum_score'] ?? 0);
            $skillCode = (string) $reservation['skill_code'];
            foreach ($ranked as $candidate) {
                if ($slots <= 0 || count($selected) >= $totalSlots) {
                    break;
                }
                if (isset($selected[$candidate['application_id']]) || (float) $candidate['score'] < $minimumScore) {
                    continue;
                }
                if (! $this->hasVerifiedSkill($candidate, $skillCode)) {
                    continue;
                }
                $selected[$candidate['application_id']] = $candidate;
                $selectionTrace[$candidate['application_id']] = "verified-skill-reservation:{$skillCode}";
                $slots--;
            }
        }

        $bucketField = (string) ($policy['bucket_field'] ?? 'bucket');
        foreach ($policy['quotas'] ?? [] as $bucketValue => $allocatedSlots) {
            $alreadyInBucket = count(array_filter(
                $selected,
                fn (array $candidate): bool => (string) ($candidate[$bucketField] ?? '') === (string) $bucketValue,
            ));
            $remainingSlots = max((int) $allocatedSlots - $alreadyInBucket, 0);
            foreach ($ranked as $candidate) {
                if ($remainingSlots <= 0 || count($selected) >= $totalSlots) {
                    break;
                }
                if (isset($selected[$candidate['application_id']])) {
                    continue;
                }
                if ((string) ($candidate[$bucketField] ?? '') !== (string) $bucketValue) {
                    continue;
                }
                $selected[$candidate['application_id']] = $candidate;
                $selectionTrace[$candidate['application_id']] = "quota:{$bucketField}:{$bucketValue}";
                $remainingSlots--;
            }
        }

        if (($policy['unfilled_quota_rule'] ?? 'general_merit') === 'general_merit') {
            foreach ($ranked as $candidate) {
                if (count($selected) >= $totalSlots) {
                    break;
                }
                if (! isset($selected[$candidate['application_id']])) {
                    $selected[$candidate['application_id']] = $candidate;
                    $selectionTrace[$candidate['application_id']] = 'general-merit-fallback';
                }
            }
        }

        $reserveSize = max((int) ($policy['reserve_size'] ?? 0), 0);
        $reservePosition = 0;
        $outcomes = [];
        foreach ($ranked as $candidate) {
            $applicationId = (string) $candidate['application_id'];
            if (isset($selected[$applicationId])) {
                $outcome = 'selected';
                $trace = $selectionTrace[$applicationId];
                $position = array_search($applicationId, array_keys($selected), true) + 1;
            } elseif ($reservePosition < $reserveSize) {
                $reservePosition++;
                $outcome = 'reserve';
                $trace = 'next-merit-order';
                $position = $reservePosition;
            } else {
                $outcome = 'not_selected';
                $trace = 'outside-authorised-vacancies';
                $position = (int) $candidate['merit_rank'];
            }

            $outcomes[] = [
                'application_id' => $applicationId,
                'outcome' => $outcome,
                'position' => $position,
                'score' => round((float) $candidate['score'], 4),
                'merit_rank' => (int) $candidate['merit_rank'],
                'bucket_key' => (string) ($candidate[$bucketField] ?? 'national'),
                'skill_reservation_applied' => str_starts_with($trace, 'verified-skill-reservation:'),
                'decision_trace' => [$trace],
            ];
        }

        return [
            'fingerprint' => $this->canonicalJson->hash([
                'candidates' => $candidates,
                'policy' => $policy,
                'outcomes' => $outcomes,
            ]),
            'ranked' => $ranked,
            'outcomes' => $outcomes,
        ];
    }

    /** @param list<array<string, string>> $tieBreakers */
    private function compareCandidates(array $left, array $right, array $tieBreakers): int
    {
        $scoreComparison = (float) $right['score'] <=> (float) $left['score'];
        if ($scoreComparison !== 0) {
            return $scoreComparison;
        }

        foreach ($tieBreakers as $tieBreaker) {
            $field = $tieBreaker['field'];
            $direction = $tieBreaker['direction'] ?? 'asc';
            $comparison = $this->compareValues($left[$field] ?? null, $right[$field] ?? null);
            if ($comparison !== 0) {
                return $direction === 'desc' ? -$comparison : $comparison;
            }
        }

        return strcmp((string) $left['application_id'], (string) $right['application_id']);
    }

    private function compareValues(mixed $left, mixed $right): int
    {
        if (is_numeric($left) && is_numeric($right)) {
            return (float) $left <=> (float) $right;
        }

        return strcmp((string) $left, (string) $right);
    }

    private function hasVerifiedSkill(array $candidate, string $skillCode): bool
    {
        foreach ($candidate['skills'] ?? [] as $skill) {
            if (($skill['code'] ?? null) === $skillCode && ($skill['status'] ?? null) === 'VERIFIED') {
                return true;
            }
        }

        return false;
    }
}
