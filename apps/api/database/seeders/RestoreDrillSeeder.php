<?php

namespace Database\Seeders;

use App\Models\Applicant;
use App\Models\Application;
use App\Models\CampaignVersion;
use App\Models\Document;
use App\Models\RecruitmentCampaign;
use App\Models\RecruitmentPost;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;

class RestoreDrillSeeder extends Seeder
{
    public function run(): void
    {
        if (env('RESTORE_DRILL_SEED_ACK') !== 'seed-clearly-synthetic-restore-data') {
            throw new RuntimeException('Set RESTORE_DRILL_SEED_ACK=seed-clearly-synthetic-restore-data to create restore-drill records.');
        }

        $campaign = RecruitmentCampaign::query()->where('code', 'UPS-DEMO-2026')->firstOrFail();
        $post = RecruitmentPost::query()->where('recruitment_campaign_id', $campaign->id)->where('code', 'WARDER')->firstOrFail();
        $version = CampaignVersion::query()->where('recruitment_campaign_id', $campaign->id)->where('status', 'published')->latest('version')->firstOrFail();
        $user = User::query()->updateOrCreate(['email' => 'restore-drill@example.test'], [
            'name' => 'SYNTHETIC Restore Drill Applicant',
            'password' => Str::random(64),
            'user_type' => 'applicant',
            'status' => 'active',
            'is_privileged' => false,
            'email_verified_at' => now(),
        ]);
        $applicant = Applicant::query()->updateOrCreate(['user_id' => $user->id], [
            'nin_encrypted' => 'SYNTHETIC-RESTORE-DRILL-NIN',
            'nin_hash' => hash('sha256', 'SYNTHETIC-RESTORE-DRILL-NIN'),
            'first_name' => 'SYNTHETIC',
            'last_name' => 'RESTORE DRILL',
            'date_of_birth' => '2000-01-01',
            'sex' => 'Not applicable',
            'nationality' => 'Synthetic test value',
            'primary_phone' => '+256700000000',
            'email' => 'restore-drill@example.test',
            'preferred_language' => 'en',
        ]);
        $application = Application::query()->updateOrCreate(['reference' => 'SYNTHETIC/RESTORE/000001'], [
            'applicant_id' => $applicant->id,
            'recruitment_campaign_id' => $campaign->id,
            'recruitment_post_id' => $post->id,
            'campaign_version_id' => $version->id,
            'status' => Application::StatusSubmitted,
            'active' => false,
            'draft_data' => ['synthetic_restore_drill' => true],
            'submission_snapshot' => ['classification' => 'SYNTHETIC RESTORE DRILL DATA — NOT AN APPLICANT'],
            'submission_fingerprint' => hash('sha256', 'SYNTHETIC/RESTORE/000001'),
            'submitted_at' => now(),
            'entity_version' => 1,
        ]);

        $bytes = "UPS e-Recruit synthetic restore drill marker. Not applicant evidence.\n";
        $path = 'restore-drill/synthetic-marker.txt';
        Storage::disk('s3')->put($path, $bytes, ['visibility' => 'private']);
        Document::query()->updateOrCreate([
            'application_id' => $application->id,
            'document_type' => 'synthetic_restore_marker',
            'version' => 1,
        ], [
            'original_filename' => 'SYNTHETIC-restore-marker.txt',
            'storage_disk' => 's3',
            'original_path' => $path,
            'mime_type' => 'text/plain',
            'detected_mime_type' => 'text/plain',
            'extension' => 'txt',
            'size_bytes' => strlen($bytes),
            'sha256' => hash('sha256', $bytes),
            'malware_status' => 'clean',
            'processing_status' => 'not_applicable',
            'quality_indicators' => ['synthetic_restore_drill' => true],
            'uploaded_by' => $user->id,
            'uploaded_at' => now(),
        ]);
    }
}
