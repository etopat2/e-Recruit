<?php

namespace Tests\Unit\Domain;

use App\Domain\Selection\SelectionService;
use App\Support\CanonicalJson;
use DomainException;
use PHPUnit\Framework\TestCase;

class SelectionServiceTest extends TestCase
{
    public function test_selection_is_reproducible_and_only_verified_skills_reserve_slots(): void
    {
        $service = new SelectionService(new CanonicalJson);
        $candidates = [
            ['application_id' => 'A', 'score' => 90, 'bucket' => 'central', 'skills' => []],
            ['application_id' => 'B', 'score' => 85, 'bucket' => 'central', 'skills' => [['code' => 'DRIVER', 'status' => 'CLAIMED']]],
            ['application_id' => 'C', 'score' => 80, 'bucket' => 'east', 'skills' => [['code' => 'DRIVER', 'status' => 'VERIFIED']]],
        ];
        $policy = ['total_slots' => 2, 'reserve_size' => 1, 'bucket_field' => 'bucket', 'skill_reservations' => [['skill_code' => 'DRIVER', 'slots' => 1]], 'quotas' => [], 'unfilled_quota_rule' => 'general_merit'];

        $first = $service->run($candidates, $policy, true);
        $second = $service->run($candidates, $policy, true);

        $this->assertSame($first['fingerprint'], $second['fingerprint']);
        $this->assertTrue(collect($first['outcomes'])->firstWhere('application_id', 'C')['skill_reservation_applied']);
        $this->assertFalse(collect($first['outcomes'])->firstWhere('application_id', 'B')['skill_reservation_applied']);
    }

    public function test_unsynchronised_offline_work_blocks_selection(): void
    {
        $this->expectException(DomainException::class);
        (new SelectionService(new CanonicalJson))->run([], ['total_slots' => 0], false);
    }
}
