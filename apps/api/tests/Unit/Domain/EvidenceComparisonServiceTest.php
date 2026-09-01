<?php

namespace Tests\Unit\Domain;

use App\Domain\Documents\EvidenceComparisonService;
use PHPUnit\Framework\TestCase;

class EvidenceComparisonServiceTest extends TestCase
{
    public function test_it_compares_every_source_pair_without_majority_vote(): void
    {
        $result = (new EvidenceComparisonService)->compare('name', [
            'entered' => 'Amina Nabirye',
            'national_id' => ['value' => 'NABIRYE AMINA', 'confidence' => 0.99],
            'certificate' => ['value' => 'A. Nabirye', 'confidence' => 0.92],
        ]);

        $this->assertCount(3, $result['comparisons']);
        $this->assertContains($result['overall'], [EvidenceComparisonService::Consistent, EvidenceComparisonService::Probable]);
    }

    public function test_low_confidence_is_flagged_not_treated_as_failure(): void
    {
        $result = (new EvidenceComparisonService)->compare('nin', [
            'entered' => 'CM900000000001',
            'scan' => ['value' => 'CM900000000001', 'confidence' => 0.31],
        ]);

        $this->assertSame(EvidenceComparisonService::LowConfidence, $result['overall']);
    }
}
