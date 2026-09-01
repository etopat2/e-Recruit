<?php

namespace Database\Seeders;

use App\Models\CampaignVersion;
use App\Models\RecruitmentCampaign;
use App\Models\RecruitmentPost;
use App\Models\RecruitmentTemplate;
use App\Models\Role;
use App\Models\User;
use App\Support\CanonicalJson;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    public function run(): void
    {
        $roles = [
            'applicant' => ['Applicant', false],
            'assisted_application_officer' => ['Assisted Application Officer', false],
            'hard_copy_receiving_officer' => ['Hard-copy Receiving Officer', false],
            'verification_officer' => ['Verification Officer', true],
            'data_clerk' => ['Data Clerk', false],
            'attendance_officer' => ['Attendance Officer', false],
            'panel_member' => ['Panel Member', true],
            'panel_head' => ['Panel Head', true],
            'written_examination_officer' => ['Written Examination Officer', true],
            'centre_coordinator' => ['Centre Coordinator', true],
            'regional_recruitment_officer' => ['Regional Recruitment Officer', true],
            'medical_officer' => ['Medical Officer', true],
            'training_school_officer' => ['Training School Officer', true],
            'helpdesk_officer' => ['Helpdesk Officer', false],
            'hq_recruitment_administrator' => ['HQ Recruitment Administrator', true],
            'prisons_council_secretariat' => ['Prisons Council Secretariat', true],
            'executive_viewer' => ['Executive Viewer', false],
            'auditor' => ['Auditor', false],
            'system_administrator' => ['System Administrator', false],
        ];
        foreach ($roles as $code => [$name, $decisionRole]) {
            Role::query()->updateOrCreate(['code' => $code], ['name' => $name, 'is_decision_role' => $decisionRole]);
        }

        foreach ([
            'campaign.configure', 'application.assist', 'hard_copy.receive', 'evidence.verify',
            'eligibility.run', 'interview.schedule', 'attendance.record', 'assessment.score',
            'panel.close', 'selection.run', 'selection.certify', 'medical.record',
            'training.record', 'report.view', 'audit.view', 'system.configure',
        ] as $permission) {
            DB::table('permissions')->updateOrInsert(['code' => $permission], [
                'name' => Str::headline($permission), 'created_at' => now(), 'updated_at' => now(),
            ]);
        }

        $template = RecruitmentTemplate::query()->updateOrCreate(['code' => 'UPS-GENERAL'], [
            'name' => 'UPS General Recruitment',
            'description' => 'Configurable UPS recruitment baseline. No external authority integration is presumed.',
            'default_configuration' => [
                'stages' => ['application', 'hard_copy', 'verification', 'eligibility', 'interview', 'selection', 'medical', 'training'],
                'reference_pattern' => 'UPS/{year}/{post}/{sequence}',
            ],
            'active' => true,
        ]);
        $campaign = RecruitmentCampaign::query()->updateOrCreate(['code' => 'UPS-DEMO-2026'], [
            'recruitment_template_id' => $template->id,
            'name' => 'UPS Demonstration Recruitment 2026',
            'year' => 2026,
            'status' => 'published',
            'timezone' => 'Africa/Kampala',
            'opens_at' => now()->subDay(),
            'closes_at' => now()->addDays(30),
            'hard_copy_deadline_at' => now()->addDays(45),
            'age_cutoff_date' => now()->addDays(30)->toDateString(),
            'privacy_notice' => [
                'version' => 'UPS-PRIVACY-2026-01',
                'summary' => 'Applicant data is used for recruitment, verification, statutory accountability, and appeals.',
            ],
            'appeals_enabled' => true,
            'published_at' => now(),
        ]);
        $post = RecruitmentPost::query()->updateOrCreate([
            'recruitment_campaign_id' => $campaign->id,
            'code' => 'WARDER',
        ], [
            'name' => 'Recruit Warder',
            'description' => 'Demonstration recruitment post whose criteria are campaign-configurable.',
            'reference_prefix' => 'WRD',
            'section_configuration' => [
                'personal' => ['required' => true],
                'address' => ['required' => true],
                'education' => ['required' => true],
                'declaration' => ['required' => true],
            ],
            'eligibility_configuration' => [
                ['id' => 'nationality', 'version' => 1, 'type' => 'allowed_values', 'field' => 'nationality', 'allowed' => ['Ugandan'], 'evidence_references' => ['national_id']],
                ['id' => 'age', 'version' => 1, 'type' => 'age_range', 'field' => 'date_of_birth', 'minimum' => 18, 'maximum' => 30, 'cutoff_date' => now()->addDays(30)->toDateString(), 'evidence_references' => ['national_id']],
            ],
            'selection_configuration' => [
                'total_slots' => 100,
                'reserve_size' => 20,
                'tie_breakers' => [['field' => 'submitted_at', 'direction' => 'asc']],
                'unfilled_quota_rule' => 'general_merit',
            ],
            'lc_source_policy' => 'origin_or_residence',
            'hard_copy_required' => true,
            'active' => true,
        ]);
        $snapshot = ['campaign' => $campaign->toArray(), 'posts' => [$post->toArray()]];
        $fingerprint = app(CanonicalJson::class)->hash($snapshot);
        $version = CampaignVersion::query()->updateOrCreate([
            'recruitment_campaign_id' => $campaign->id,
            'version' => 1,
        ], [
            'status' => 'published',
            'snapshot' => $snapshot,
            'fingerprint' => $fingerprint,
            'change_reason' => 'Initial seeded reference configuration.',
            'published_at' => now(),
        ]);

        foreach ([
            ['national_id', 'National Identification', true, true],
            ['academic_certificate', 'Academic Certificate', true, true],
            ['passport_photo', 'Passport Photograph', true, false],
        ] as [$type, $label, $required, $hardCopy]) {
            $key = ['recruitment_post_id' => $post->id, 'document_type' => $type, 'campaign_version_id' => $version->id];
            $requirementId = DB::table('campaign_document_requirements')->where($key)->value('id') ?? (string) Str::ulid();
            DB::table('campaign_document_requirements')->updateOrInsert($key, [
                'id' => $requirementId,
                'label' => $label,
                'required' => $required,
                'minimum_files' => 1,
                'maximum_files' => 1,
                'maximum_size_kb' => 5120,
                'allowed_extensions' => json_encode(['pdf', 'jpg', 'png'], JSON_THROW_ON_ERROR),
                'hard_copy_required' => $hardCopy,
                'original_required_at_interview' => $hardCopy,
                'extraction_profile' => json_encode(['fields' => $type === 'national_id' ? ['name', 'nin', 'dob'] : ['name', 'index_number', 'grade']], JSON_THROW_ON_ERROR),
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
        foreach ([['DRIVER', 'Driving'], ['ICT', 'Information and Communication Technology'], ['MEDICAL', 'Medical']] as [$code, $name]) {
            $skillId = DB::table('skill_categories')->where('code', $code)->value('id') ?? (string) Str::ulid();
            DB::table('skill_categories')->updateOrInsert(['code' => $code], [
                'id' => $skillId, 'name' => $name, 'active' => true, 'created_at' => now(), 'updated_at' => now(),
            ]);
        }

        if (config('erecruit.seed_demo_users')) {
            foreach (['hq_recruitment_administrator', 'verification_officer', 'panel_head', 'medical_officer', 'auditor'] as $roleCode) {
                $user = User::query()->updateOrCreate(['email' => "{$roleCode}@example.test"], [
                    'name' => Str::headline($roleCode),
                    'password' => 'ChangeMe!2026',
                    'user_type' => $roleCode,
                    'status' => 'active',
                    'is_privileged' => in_array($roleCode, config('erecruit.security.privileged_roles'), true),
                ]);
                $roleId = Role::query()->where('code', $roleCode)->value('id');
                $user->roles()->syncWithoutDetaching([$roleId]);
                $scopeKey = ['user_id' => $user->id, 'scope_type' => 'national', 'scope_id' => null];
                $scopeId = DB::table('user_scopes')->where($scopeKey)->value('id') ?? (string) Str::ulid();
                DB::table('user_scopes')->updateOrInsert($scopeKey, [
                    'id' => $scopeId,
                    'allowed_tasks' => json_encode(['*'], JSON_THROW_ON_ERROR),
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }
        }
    }
}
