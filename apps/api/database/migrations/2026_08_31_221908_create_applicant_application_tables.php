<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('applicants', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignId('user_id')->unique()->constrained()->cascadeOnDelete();
            $table->text('nin_encrypted');
            $table->char('nin_hash', 64)->unique();
            $table->string('first_name', 100);
            $table->string('middle_names')->nullable();
            $table->string('last_name', 100);
            $table->date('date_of_birth');
            $table->string('sex', 30);
            $table->string('nationality', 80)->default('Ugandan');
            $table->string('primary_phone', 30);
            $table->string('alternative_phone', 30)->nullable();
            $table->string('email')->nullable();
            $table->string('preferred_language', 10)->default('en');
            $table->timestampsTz();
            $table->index(['last_name', 'first_name']);
        });

        Schema::create('applications', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('applicant_id')->constrained()->restrictOnDelete();
            $table->foreignUlid('recruitment_campaign_id')->constrained()->restrictOnDelete();
            $table->foreignUlid('recruitment_post_id')->constrained()->restrictOnDelete();
            $table->foreignUlid('campaign_version_id')->nullable()->constrained()->restrictOnDelete();
            $table->string('reference', 60)->nullable()->unique();
            $table->string('status', 50)->default('draft')->index();
            $table->boolean('active')->default(true);
            $table->json('draft_data')->nullable();
            $table->json('submission_snapshot')->nullable();
            $table->char('submission_fingerprint', 64)->nullable();
            $table->uuid('submission_idempotency_key')->nullable()->unique();
            $table->string('qr_payload')->nullable();
            $table->string('acknowledgement_path')->nullable();
            $table->timestampTz('submitted_at')->nullable();
            $table->unsignedBigInteger('entity_version')->default(1);
            $table->foreignId('assisted_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampsTz();
            $table->index(['recruitment_campaign_id', 'recruitment_post_id', 'status']);
            $table->index(['applicant_id', 'updated_at']);
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::statement('CREATE UNIQUE INDEX applications_one_active_per_post ON applications (applicant_id, recruitment_campaign_id, recruitment_post_id) WHERE active = true');
        } else {
            DB::statement('CREATE UNIQUE INDEX applications_one_active_per_post ON applications (applicant_id, recruitment_campaign_id, recruitment_post_id) WHERE active = 1');
        }

        Schema::create('application_exceptions', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('application_id')->constrained()->cascadeOnDelete();
            $table->string('exception_type', 80);
            $table->text('reason');
            $table->foreignId('requested_by')->constrained('users')->restrictOnDelete();
            $table->foreignId('approved_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestampTz('approved_at')->nullable();
            $table->timestampTz('expires_at')->nullable();
            $table->timestampsTz();
        });

        Schema::create('application_status_history', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('application_id')->constrained()->cascadeOnDelete();
            $table->string('from_status', 50)->nullable();
            $table->string('to_status', 50);
            $table->text('reason')->nullable();
            $table->foreignId('changed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('source', 30)->default('online');
            $table->timestampsTz();
            $table->index(['application_id', 'created_at']);
        });

        Schema::create('applicant_addresses', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('application_id')->constrained()->cascadeOnDelete();
            $table->string('address_type', 20);
            $table->foreignUlid('district_id')->constrained('administrative_units')->restrictOnDelete();
            $table->foreignUlid('county_id')->nullable()->constrained('administrative_units')->restrictOnDelete();
            $table->foreignUlid('subcounty_id')->nullable()->constrained('administrative_units')->restrictOnDelete();
            $table->foreignUlid('parish_id')->nullable()->constrained('administrative_units')->restrictOnDelete();
            $table->foreignUlid('village_id')->nullable()->constrained('administrative_units')->restrictOnDelete();
            $table->string('physical_address')->nullable();
            $table->unsignedSmallInteger('residence_months')->nullable();
            $table->timestampsTz();
            $table->unique(['application_id', 'address_type']);
        });

        Schema::create('education_records', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('application_id')->constrained()->cascadeOnDelete();
            $table->string('education_level', 80);
            $table->string('institution');
            $table->string('examining_body')->nullable();
            $table->string('index_or_certificate_number')->nullable();
            $table->unsignedSmallInteger('completion_year')->nullable();
            $table->string('result_class')->nullable();
            $table->timestampsTz();
            $table->index(['index_or_certificate_number', 'examining_body']);
        });

        Schema::create('subject_results', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('education_record_id')->constrained()->cascadeOnDelete();
            $table->string('subject_code', 40);
            $table->string('subject_name');
            $table->string('grade', 20);
            $table->timestampsTz();
            $table->unique(['education_record_id', 'subject_code']);
        });

        Schema::create('employment_records', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('application_id')->constrained()->cascadeOnDelete();
            $table->string('employer');
            $table->string('position');
            $table->date('started_on')->nullable();
            $table->date('ended_on')->nullable();
            $table->text('responsibilities')->nullable();
            $table->timestampsTz();
        });

        Schema::create('professional_registrations', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('application_id')->constrained()->cascadeOnDelete();
            $table->string('professional_body');
            $table->string('registration_number');
            $table->date('issued_on')->nullable();
            $table->date('expires_on')->nullable();
            $table->string('verification_status', 30)->default('claimed');
            $table->timestampsTz();
            $table->index(['professional_body', 'registration_number']);
        });

        Schema::create('skill_categories', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->string('code', 50)->unique();
            $table->string('name');
            $table->boolean('active')->default(true);
            $table->timestampsTz();
        });

        Schema::create('applicant_skills', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('application_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('skill_category_id')->constrained()->restrictOnDelete();
            $table->string('title');
            $table->string('institution')->nullable();
            $table->string('certificate_number')->nullable();
            $table->date('issued_on')->nullable();
            $table->string('verification_status', 40)->default('CLAIMED')->index();
            $table->foreignId('verified_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampTz('verified_at')->nullable();
            $table->timestampsTz();
        });

        Schema::create('privacy_acceptances', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('application_id')->constrained()->cascadeOnDelete();
            $table->string('notice_version', 50);
            $table->char('notice_fingerprint', 64);
            $table->timestampTz('accepted_at');
            $table->string('ip_address', 45)->nullable();
            $table->string('user_agent', 500)->nullable();
            $table->timestampsTz();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('privacy_acceptances');
        Schema::dropIfExists('applicant_skills');
        Schema::dropIfExists('skill_categories');
        Schema::dropIfExists('professional_registrations');
        Schema::dropIfExists('employment_records');
        Schema::dropIfExists('subject_results');
        Schema::dropIfExists('education_records');
        Schema::dropIfExists('applicant_addresses');
        Schema::dropIfExists('application_status_history');
        Schema::dropIfExists('application_exceptions');
        Schema::dropIfExists('applications');
        Schema::dropIfExists('applicants');
    }
};
