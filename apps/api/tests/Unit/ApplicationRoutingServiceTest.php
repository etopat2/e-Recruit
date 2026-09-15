<?php

namespace Tests\Unit;

use App\Models\Application;
use App\Models\RecruitmentPost;
use App\Services\ApplicationRoutingService;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class ApplicationRoutingServiceTest extends TestCase
{
    public function test_origin_or_residence_policy_uses_the_address_named_on_the_lc1_letter(): void
    {
        $application = new Application([
            'draft_data' => [
                'origin' => ['district_id' => 'origin-district'],
                'residence' => ['district_id' => 'residence-district'],
                'lc1_letter' => ['address_type' => 'residence'],
            ],
        ]);
        $application->setRelation('post', new RecruitmentPost(['lc_source_policy' => 'origin_or_residence']));

        $this->assertSame(
            ['address_type' => 'residence', 'district_id' => 'residence-district'],
            (new ApplicationRoutingService)->resolveDraft($application),
        );
    }

    public function test_origin_or_residence_policy_rejects_ambiguous_routing_without_an_lc1_choice(): void
    {
        $application = new Application([
            'draft_data' => [
                'origin' => ['district_id' => 'origin-district'],
                'residence' => ['district_id' => 'residence-district'],
            ],
        ]);
        $application->setRelation('post', new RecruitmentPost(['lc_source_policy' => 'origin_or_residence']));

        $this->expectException(ValidationException::class);
        (new ApplicationRoutingService)->resolveDraft($application);
    }
}
