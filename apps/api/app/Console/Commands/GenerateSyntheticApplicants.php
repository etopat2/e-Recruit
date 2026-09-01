<?php

namespace App\Console\Commands;

use App\Models\CampaignVersion;
use App\Models\RecruitmentPost;
use App\Support\Nin;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

class GenerateSyntheticApplicants extends Command
{
    protected $signature = 'erecruit:generate-synthetic
                            {--count=1000 : Number of applications to generate (maximum 150000)}
                            {--seed=20260901 : Deterministic scenario seed}
                            {--post= : Recruitment post ULID; defaults to the first active post}';

    protected $description = 'Generate clearly synthetic, non-PII applicant/application load data';

    public function handle(): int
    {
        if (app()->environment('production')) {
            $this->error('Synthetic data generation is disabled in production. Use an isolated test or staging environment.');

            return self::FAILURE;
        }

        $count = (int) $this->option('count');
        $seed = (int) $this->option('seed');
        if ($count < 1 || $count > 150000) {
            $this->error('--count must be between 1 and 150000.');

            return self::INVALID;
        }

        $post = RecruitmentPost::query()
            ->when($this->option('post'), fn ($query, $postId) => $query->whereKey($postId))
            ->where('active', true)
            ->first();
        if ($post === null) {
            $this->error('No active recruitment post exists. Seed or configure a campaign first.');

            return self::FAILURE;
        }
        $version = CampaignVersion::query()
            ->where('recruitment_campaign_id', $post->recruitment_campaign_id)
            ->where('status', 'published')
            ->latest('version')
            ->first();
        if ($version === null) {
            $this->error('The selected post has no published campaign version.');

            return self::FAILURE;
        }

        $start = DB::table('users')->where('email', 'like', 'synthetic+%@example.invalid')->count() + 1;
        $password = Hash::make(Str::random(64));
        $bar = $this->output->createProgressBar($count);
        $bar->start();

        foreach (array_chunk(range($start, $start + $count - 1), 500) as $indexes) {
            DB::transaction(function () use ($indexes, $post, $version, $password, $seed, $bar): void {
                $now = now();
                $emails = array_map(fn (int $index): string => "synthetic+{$index}@example.invalid", $indexes);
                DB::table('users')->insert(array_map(fn (int $index): array => [
                    'name' => "Synthetic Applicant {$index}",
                    'email' => "synthetic+{$index}@example.invalid",
                    'email_verified_at' => $now,
                    'password' => $password,
                    'user_type' => 'applicant',
                    'status' => 'active',
                    'is_privileged' => false,
                    'created_at' => $now,
                    'updated_at' => $now,
                ], $indexes));
                $userIds = DB::table('users')->whereIn('email', $emails)->pluck('id', 'email');
                $applicants = [];
                $applications = [];
                foreach ($indexes as $index) {
                    $nin = 'SY'.str_pad((string) $index, 12, '0', STR_PAD_LEFT);
                    $applicantId = (string) Str::ulid();
                    $applicationId = (string) Str::ulid();
                    $email = "synthetic+{$index}@example.invalid";
                    $submittedAt = $now->copy()->subSeconds(($index * 37 + $seed) % 86400);
                    $snapshot = [
                        'synthetic' => true,
                        'seed' => $seed,
                        'personal' => [
                            'full_name' => "Synthetic Applicant {$index}",
                            'nationality' => 'Ugandan',
                        ],
                        'education' => [['level' => $index % 3 === 0 ? 'Diploma' : 'UCE', 'result' => 'SYNTHETIC']],
                        'declaration' => ['accepted' => true],
                    ];
                    $applicants[] = [
                        'id' => $applicantId,
                        'user_id' => $userIds[$email],
                        'nin_encrypted' => Crypt::encryptString($nin),
                        'nin_hash' => Nin::fingerprint($nin),
                        'first_name' => 'Synthetic',
                        'last_name' => "Applicant{$index}",
                        'date_of_birth' => sprintf('%04d-%02d-%02d', 1988 + ($index % 15), 1 + ($index % 12), 1 + ($index % 27)),
                        'sex' => $index % 2 === 0 ? 'Female' : 'Male',
                        'nationality' => 'Ugandan',
                        'primary_phone' => '+256000'.str_pad((string) ($index % 1000000), 6, '0', STR_PAD_LEFT),
                        'email' => $email,
                        'preferred_language' => 'en',
                        'created_at' => $now,
                        'updated_at' => $now,
                    ];
                    $applications[] = [
                        'id' => $applicationId,
                        'applicant_id' => $applicantId,
                        'recruitment_campaign_id' => $post->recruitment_campaign_id,
                        'recruitment_post_id' => $post->id,
                        'campaign_version_id' => $version->id,
                        'reference' => 'SYN-'.$post->reference_prefix.'-'.str_pad((string) $index, 9, '0', STR_PAD_LEFT),
                        'status' => 'submitted_online',
                        'active' => true,
                        'draft_data' => json_encode($snapshot, JSON_THROW_ON_ERROR),
                        'submission_snapshot' => json_encode($snapshot, JSON_THROW_ON_ERROR),
                        'submission_fingerprint' => hash('sha256', json_encode($snapshot, JSON_THROW_ON_ERROR)),
                        'submission_idempotency_key' => (string) Str::uuid(),
                        'qr_payload' => "UPS-ERECRUIT:SYNTHETIC:{$applicationId}",
                        'submitted_at' => $submittedAt,
                        'entity_version' => 2,
                        'created_at' => $now,
                        'updated_at' => $now,
                    ];
                }
                DB::table('applicants')->insert($applicants);
                DB::table('applications')->insert($applications);
                $bar->advance(count($indexes));
            }, 3);
        }

        $bar->finish();
        $this->newLine(2);
        $this->info("Generated {$count} synthetic applications using seed {$seed}.");

        return self::SUCCESS;
    }
}
