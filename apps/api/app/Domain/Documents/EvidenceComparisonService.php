<?php

namespace App\Domain\Documents;

use Illuminate\Support\Str;

class EvidenceComparisonService
{
    public const Consistent = 'VERIFIED/CONSISTENT';

    public const Probable = 'PROBABLE MATCH';

    public const Discrepancy = 'DISCREPANCY';

    public const LowConfidence = 'UNREADABLE/LOW CONFIDENCE';

    public const NotAvailable = 'NOT AVAILABLE';

    /**
     * @param  array<string, mixed>  $sources
     * @return array{field_key: string, sources: array<string, array<string, mixed>>, comparisons: list<array<string, mixed>>, overall: string}
     */
    public function compare(string $fieldKey, array $sources): array
    {
        $prepared = [];
        foreach ($sources as $source => $input) {
            $value = is_array($input) ? ($input['value'] ?? null) : $input;
            $confidence = is_array($input) ? ($input['confidence'] ?? 1.0) : 1.0;
            $prepared[$source] = [
                'value' => $value,
                'normalised' => $this->normalise($fieldKey, $value),
                'confidence' => (float) $confidence,
            ];
        }

        $comparisons = [];
        $sourceNames = array_keys($prepared);
        for ($leftIndex = 0; $leftIndex < count($sourceNames); $leftIndex++) {
            for ($rightIndex = $leftIndex + 1; $rightIndex < count($sourceNames); $rightIndex++) {
                $left = $sourceNames[$leftIndex];
                $right = $sourceNames[$rightIndex];
                $comparisons[] = [
                    'left_source' => $left,
                    'right_source' => $right,
                    ...$this->comparePair($fieldKey, $prepared[$left], $prepared[$right]),
                ];
            }
        }

        $outcomes = array_column($comparisons, 'outcome');
        $overall = match (true) {
            in_array(self::Discrepancy, $outcomes, true) => self::Discrepancy,
            in_array(self::LowConfidence, $outcomes, true) => self::LowConfidence,
            in_array(self::Probable, $outcomes, true) => self::Probable,
            $outcomes !== [] && count(array_unique($outcomes)) === 1 && $outcomes[0] === self::NotAvailable => self::NotAvailable,
            default => self::Consistent,
        };

        return [
            'field_key' => $fieldKey,
            'sources' => $prepared,
            'comparisons' => $comparisons,
            'overall' => $overall,
        ];
    }

    /** @param array{value: mixed, normalised: mixed, confidence: float} $left
     * @param  array{value: mixed, normalised: mixed, confidence: float}  $right
     * @return array{outcome: string, similarity: float|null, explanation: string}
     */
    private function comparePair(string $fieldKey, array $left, array $right): array
    {
        if ($left['normalised'] === null || $right['normalised'] === null) {
            return ['outcome' => self::NotAvailable, 'similarity' => null, 'explanation' => 'One or both sources do not contain this field.'];
        }

        if ($left['confidence'] < 0.55 || $right['confidence'] < 0.55) {
            return ['outcome' => self::LowConfidence, 'similarity' => null, 'explanation' => 'At least one source is below the review confidence threshold.'];
        }

        if ($left['normalised'] === $right['normalised']) {
            return ['outcome' => self::Consistent, 'similarity' => 1.0, 'explanation' => 'Normalised values agree exactly.'];
        }

        if ($fieldKey === 'name') {
            return $this->compareNames((string) $left['normalised'], (string) $right['normalised']);
        }

        return ['outcome' => self::Discrepancy, 'similarity' => 0.0, 'explanation' => 'Normalised values differ materially.'];
    }

    /** @return array{outcome: string, similarity: float, explanation: string} */
    private function compareNames(string $left, string $right): array
    {
        $leftTokens = array_values(array_filter(explode(' ', $left)));
        $rightTokens = array_values(array_filter(explode(' ', $right)));
        $sortedLeft = $leftTokens;
        $sortedRight = $rightTokens;
        sort($sortedLeft);
        sort($sortedRight);

        if ($sortedLeft === $sortedRight) {
            return ['outcome' => self::Consistent, 'similarity' => 1.0, 'explanation' => 'Name tokens agree in a different order.'];
        }

        $matched = 0;
        foreach ($leftTokens as $leftToken) {
            foreach ($rightTokens as $rightToken) {
                if ($leftToken === $rightToken || Str::startsWith($leftToken, $rightToken) || Str::startsWith($rightToken, $leftToken)) {
                    $matched++;
                    break;
                }
            }
        }

        $similarity = $matched / max(count($leftTokens), count($rightTokens), 1);
        if ($similarity >= 0.66) {
            return ['outcome' => self::Probable, 'similarity' => round($similarity, 4), 'explanation' => 'Name tokens contain likely abbreviations or partial forms.'];
        }

        return ['outcome' => self::Discrepancy, 'similarity' => round($similarity, 4), 'explanation' => 'Name tokens differ materially and require review.'];
    }

    private function normalise(string $fieldKey, mixed $value): mixed
    {
        if ($value === null || trim((string) $value) === '') {
            return null;
        }

        $text = trim((string) $value);

        return match ($fieldKey) {
            'name' => preg_replace('/\s+/', ' ', mb_strtoupper(preg_replace('/[^\pL\pN\s]/u', ' ', Str::ascii($text)) ?? '')),
            'nin', 'index_number', 'certificate_number', 'grade' => mb_strtoupper(preg_replace('/[^A-Z0-9]/i', '', $text) ?? ''),
            'dob', 'date_of_birth' => $this->normaliseDate($text),
            default => mb_strtoupper(preg_replace('/\s+/', ' ', $text) ?? ''),
        };
    }

    private function normaliseDate(string $value): string
    {
        $formats = ['Y-m-d', 'd/m/Y', 'd-m-Y', 'd.m.Y'];
        foreach ($formats as $format) {
            $date = \DateTimeImmutable::createFromFormat('!'.$format, $value);
            if ($date !== false && $date->format($format) === $value) {
                return $date->format('Y-m-d');
            }
        }

        return mb_strtoupper(trim($value));
    }
}
