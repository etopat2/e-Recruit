<?php

namespace Tests\Unit\Domain;

use App\Domain\Applications\ApplicationReferenceService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\CreatesRecruitmentFixtures;
use Tests\TestCase;

class ApplicationReferenceServiceTest extends TestCase
{
    use CreatesRecruitmentFixtures;
    use RefreshDatabase;

    public function test_reference_is_post_scoped_has_no_centre_and_is_idempotent(): void
    {
        $fixture = $this->recruitmentFixture();
        $service = app(ApplicationReferenceService::class);

        $first = $service->allocate($fixture['application']);
        $second = $service->allocate($fixture['application']);

        $this->assertSame('UPS/2026/WRD/000001', $first);
        $this->assertSame($first, $second);
        $this->assertStringNotContainsString('CENTRE', $first);
    }
}
