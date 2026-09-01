<?php

namespace Tests\Unit\Domain;

use App\Domain\Assessments\AssessmentService;
use PHPUnit\Framework\TestCase;

class AssessmentServiceTest extends TestCase
{
    public function test_it_aggregates_configured_components_and_flags_divergence(): void
    {
        $result = (new AssessmentService)->aggregate([
            ['code' => 'INTERVIEW', 'maximum_mark' => 100, 'weight' => 60, 'pass_mark' => 50, 'mandatory' => true, 'aggregation_method' => 'mean', 'required_assessors' => 2, 'divergence_threshold' => 15],
            ['code' => 'FITNESS', 'maximum_mark' => 50, 'weight' => 40, 'pass_mark' => 25, 'mandatory' => true, 'aggregation_method' => 'single'],
        ], ['INTERVIEW' => [80, 60], 'FITNESS' => [40]]);

        $this->assertSame(74.0, $result['aggregate']);
        $this->assertTrue($result['complete']);
        $this->assertTrue($result['passed']);
        $this->assertTrue($result['components'][0]['divergence_flagged']);
    }
}
