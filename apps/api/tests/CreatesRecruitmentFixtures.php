<?php

namespace Tests;

use App\Models\Applicant;
use App\Models\Application;
use App\Models\CampaignVersion;
use App\Models\RecruitmentCampaign;
use App\Models\RecruitmentPost;
use App\Models\User;
use App\Support\CanonicalJson;
use App\Support\Nin;

trait CreatesRecruitmentFixtures
{
    /** @return array{user: User, applicant: Applicant, campaign: RecruitmentCampaign, post: RecruitmentPost, version: CampaignVersion, application: Application} */
    protected function recruitmentFixture(array $applicationAttributes = [], array $postAttributes = []): array
    {
        $user = User::factory()->create(['user_type' => 'applicant']);
        $nin = 'CM900000000001';
        $applicant = Applicant::query()->create([
            'user_id' => $user->id,
            'nin_encrypted' => $nin,
            'nin_hash' => Nin::fingerprint($nin),
            'first_name' => 'Amina',
            'last_name' => 'Nabirye',
            'date_of_birth' => '2001-05-12',
            'sex' => 'Female',
            'nationality' => 'Ugandan',
            'primary_phone' => '+256700000001',
            'email' => $user->email,
        ]);
        $campaign = RecruitmentCampaign::factory()->create(['code' => 'UPS-TEST-2026', 'year' => 2026]);
        $post = RecruitmentPost::factory()->create([
            'recruitment_campaign_id' => $campaign->id,
            'code' => 'WARDER',
            'reference_prefix' => 'WRD',
            'section_configuration' => [],
            ...$postAttributes,
        ]);
        $snapshot = ['campaign_id' => $campaign->id, 'post_id' => $post->id, 'version' => 1];
        $version = CampaignVersion::query()->create([
            'recruitment_campaign_id' => $campaign->id,
            'version' => 1,
            'status' => 'published',
            'snapshot' => $snapshot,
            'fingerprint' => app(CanonicalJson::class)->hash($snapshot),
            'published_at' => now(),
        ]);
        $application = Application::query()->create([
            'applicant_id' => $applicant->id,
            'recruitment_campaign_id' => $campaign->id,
            'recruitment_post_id' => $post->id,
            'campaign_version_id' => $version->id,
            'status' => Application::StatusDraft,
            'active' => true,
            'draft_data' => [],
            'entity_version' => 1,
            ...$applicationAttributes,
        ]);

        return compact('user', 'applicant', 'campaign', 'post', 'version', 'application');
    }
}
