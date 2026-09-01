<?php

namespace App\Domain\Assessments;

use DomainException;

class AssessmentService
{
    /**
     * @param  list<array<string, mixed>>  $definitions
     * @param  array<string, list<float|int>>  $scoresByComponent
     * @return array{aggregate: float, complete: bool, passed: bool, components: list<array<string, mixed>>}
     */
    public function aggregate(array $definitions, array $scoresByComponent): array
    {
        $components = [];
        $weightedTotal = 0.0;
        $complete = true;
        $passed = true;

        foreach ($definitions as $definition) {
            $code = (string) $definition['code'];
            $maximum = (float) $definition['maximum_mark'];
            $weight = (float) $definition['weight'];
            $scores = array_map('floatval', $scoresByComponent[$code] ?? []);
            if ($maximum <= 0 || $weight < 0) {
                throw new DomainException("Assessment component {$code} has an invalid maximum or weight.");
            }
            foreach ($scores as $score) {
                if ($score < 0 || $score > $maximum) {
                    throw new DomainException("Assessment score for {$code} is outside its configured range.");
                }
            }

            $requiredAssessors = (int) ($definition['required_assessors'] ?? 1);
            $componentComplete = count($scores) >= $requiredAssessors;
            $complete = $complete && (! ($definition['mandatory'] ?? true) || $componentComplete);
            $aggregated = $this->aggregateComponent($scores, (string) $definition['aggregation_method']);
            $componentPassed = $aggregated !== null && (
                ! isset($definition['pass_mark']) || $aggregated >= (float) $definition['pass_mark']
            );
            if ($definition['mandatory'] ?? true) {
                $passed = $passed && $componentPassed;
            }
            $contribution = $aggregated === null ? 0.0 : ($aggregated / $maximum) * $weight;
            $weightedTotal += $contribution;
            $divergence = count($scores) > 1 ? max($scores) - min($scores) : 0.0;

            $components[] = [
                'code' => $code,
                'scores' => $scores,
                'aggregated_score' => $aggregated === null ? null : round($aggregated, 4),
                'weighted_contribution' => round($contribution, 4),
                'complete' => $componentComplete,
                'passed' => $componentPassed,
                'divergence' => round($divergence, 4),
                'divergence_flagged' => isset($definition['divergence_threshold'])
                    && $divergence > (float) $definition['divergence_threshold'],
            ];
        }

        return [
            'aggregate' => round($weightedTotal, 4),
            'complete' => $complete,
            'passed' => $complete && $passed,
            'components' => $components,
        ];
    }

    /** @param list<float> $scores */
    private function aggregateComponent(array $scores, string $method): ?float
    {
        if ($scores === []) {
            return null;
        }

        return match ($method) {
            'mean', 'average' => array_sum($scores) / count($scores),
            'sum' => array_sum($scores),
            'single', 'consensus', 'adjudicated' => $scores[array_key_last($scores)],
            default => throw new DomainException("Unsupported assessment aggregation method [{$method}]."),
        };
    }
}
