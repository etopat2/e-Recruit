<?php

namespace Tests\Unit\Domain;

use App\Domain\Eligibility\EligibilityEngine;
use App\Support\CanonicalJson;
use PHPUnit\Framework\TestCase;

class EligibilityEngineTest extends TestCase
{
    public function test_it_returns_explainable_results_and_a_stable_fingerprint(): void
    {
        $engine = new EligibilityEngine(new CanonicalJson);
        $rules = [
            ['id' => 'age', 'version' => 1, 'type' => 'age_range', 'field' => 'dob', 'minimum' => 18, 'maximum' => 30, 'cutoff_date' => '2026-12-31'],
            ['id' => 'nationality', 'version' => 1, 'type' => 'allowed_values', 'field' => 'nationality', 'allowed' => ['Ugandan']],
        ];

        $first = $engine->evaluate($rules, ['dob' => '2001-05-12', 'nationality' => 'Ugandan']);
        $second = $engine->evaluate($rules, ['nationality' => 'Ugandan', 'dob' => '2001-05-12']);

        $this->assertSame('PASS', $first['outcome']);
        $this->assertSame($first['fingerprint'], $second['fingerprint']);
        $this->assertNotEmpty($first['results'][0]['explanation']);
    }

    public function test_uncertain_evidence_requires_review(): void
    {
        $result = (new EligibilityEngine(new CanonicalJson))->evaluate([
            ['id' => 'nin', 'version' => 1, 'type' => 'required_presence', 'field' => 'nin', 'source_status' => 'UNREADABLE/LOW CONFIDENCE'],
        ], ['nin' => 'CM900000000001']);

        $this->assertSame('FLAG/REVIEW', $result['outcome']);
    }
}
