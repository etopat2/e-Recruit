<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('roles', function (Blueprint $table) {
            $table->id();
            $table->string('code', 80)->unique();
            $table->string('name');
            $table->boolean('is_decision_role')->default(false);
            $table->timestampsTz();
        });

        Schema::create('permissions', function (Blueprint $table) {
            $table->id();
            $table->string('code', 120)->unique();
            $table->string('name');
            $table->timestampsTz();
        });

        Schema::create('permission_role', function (Blueprint $table) {
            $table->foreignId('permission_id')->constrained()->cascadeOnDelete();
            $table->foreignId('role_id')->constrained()->cascadeOnDelete();
            $table->primary(['permission_id', 'role_id']);
        });

        Schema::create('role_user', function (Blueprint $table) {
            $table->foreignId('role_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('assigned_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampTz('expires_at')->nullable();
            $table->timestampsTz();
            $table->primary(['role_id', 'user_id']);
        });

        Schema::create('recruitment_templates', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->string('code', 50)->unique();
            $table->string('name');
            $table->text('description')->nullable();
            $table->json('default_configuration');
            $table->boolean('active')->default(true);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampsTz();
        });

        Schema::create('recruitment_campaigns', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('recruitment_template_id')->nullable()->constrained()->nullOnDelete();
            $table->string('code', 50)->unique();
            $table->string('name');
            $table->unsignedSmallInteger('year');
            $table->string('status', 30)->default('draft')->index();
            $table->string('timezone', 80)->default('Africa/Kampala');
            $table->timestampTz('opens_at')->nullable();
            $table->timestampTz('closes_at')->nullable();
            $table->timestampTz('hard_copy_deadline_at')->nullable();
            $table->date('age_cutoff_date')->nullable();
            $table->json('privacy_notice')->nullable();
            $table->boolean('appeals_enabled')->default(false);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('published_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampTz('published_at')->nullable();
            $table->timestampsTz();
            $table->index(['status', 'opens_at', 'closes_at']);
        });

        Schema::create('recruitment_posts', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('recruitment_campaign_id')->constrained()->cascadeOnDelete();
            $table->string('code', 30);
            $table->string('name');
            $table->text('description')->nullable();
            $table->string('reference_prefix', 20);
            $table->json('section_configuration');
            $table->json('eligibility_configuration');
            $table->json('selection_configuration')->nullable();
            $table->string('lc_source_policy', 40)->default('origin_or_residence');
            $table->boolean('hard_copy_required')->default(true);
            $table->boolean('active')->default(true);
            $table->timestampsTz();
            $table->unique(['recruitment_campaign_id', 'code']);
        });

        Schema::create('campaign_versions', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('recruitment_campaign_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('version');
            $table->string('status', 30)->default('draft');
            $table->json('snapshot');
            $table->char('fingerprint', 64);
            $table->text('change_reason')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampTz('published_at')->nullable();
            $table->timestampsTz();
            $table->unique(['recruitment_campaign_id', 'version']);
            $table->unique(['recruitment_campaign_id', 'fingerprint']);
        });

        Schema::create('campaign_stages', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('recruitment_post_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('campaign_version_id')->nullable()->constrained()->nullOnDelete();
            $table->string('stage_code', 60);
            $table->string('name');
            $table->unsignedSmallInteger('sequence');
            $table->boolean('required')->default(true);
            $table->json('configuration')->nullable();
            $table->timestampsTz();
            $table->unique(['recruitment_post_id', 'stage_code', 'campaign_version_id'], 'campaign_stage_version_unique');
            $table->unique(['recruitment_post_id', 'sequence', 'campaign_version_id'], 'campaign_stage_sequence_unique');
        });

        Schema::create('campaign_document_requirements', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('recruitment_post_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('campaign_version_id')->nullable()->constrained()->nullOnDelete();
            $table->string('document_type', 80);
            $table->string('label');
            $table->boolean('required')->default(true);
            $table->unsignedSmallInteger('minimum_files')->default(1);
            $table->unsignedSmallInteger('maximum_files')->default(1);
            $table->unsignedInteger('maximum_size_kb')->default(5120);
            $table->json('allowed_extensions');
            $table->boolean('hard_copy_required')->default(false);
            $table->boolean('original_required_at_interview')->default(false);
            $table->json('extraction_profile')->nullable();
            $table->timestampsTz();
            $table->unique(['recruitment_post_id', 'document_type', 'campaign_version_id'], 'campaign_document_version_unique');
        });

        Schema::create('administrative_units', function (Blueprint $table) {
            $table->ulid('id')->primary();
            // Add the self-reference after table creation. PostgreSQL validates
            // an ALTER-based self FK before Laravel's inline primary-key DDL is
            // visible when both are emitted from the same create blueprint.
            $table->ulid('parent_id')->nullable();
            $table->string('code', 50)->unique();
            $table->string('name');
            $table->string('level', 30)->index();
            $table->date('effective_from')->nullable();
            $table->date('effective_to')->nullable();
            $table->boolean('active')->default(true);
            $table->timestampsTz();
            $table->index(['parent_id', 'level', 'active']);
        });

        Schema::table('administrative_units', function (Blueprint $table) {
            $table->foreign('parent_id')->references('id')->on('administrative_units')->restrictOnDelete();
        });

        Schema::create('prison_regions', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->string('code', 30)->unique();
            $table->string('name');
            $table->boolean('active')->default(true);
            $table->timestampsTz();
        });

        Schema::create('recruitment_centres', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('prison_region_id')->constrained()->restrictOnDelete();
            $table->string('code', 30)->unique();
            $table->string('name');
            $table->string('address');
            $table->string('contact_phone', 30)->nullable();
            $table->unsignedInteger('daily_capacity')->nullable();
            $table->date('active_from')->nullable();
            $table->date('active_to')->nullable();
            $table->boolean('active')->default(true);
            $table->timestampsTz();
        });

        Schema::create('district_centre_mappings', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('recruitment_campaign_id')->nullable()->constrained()->cascadeOnDelete();
            $table->foreignUlid('district_id')->constrained('administrative_units')->restrictOnDelete();
            $table->foreignUlid('recruitment_centre_id')->constrained()->restrictOnDelete();
            $table->date('effective_from');
            $table->date('effective_to')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampsTz();
            $table->index(['district_id', 'effective_from', 'effective_to']);
            $table->unique(['recruitment_campaign_id', 'district_id', 'effective_from'], 'district_campaign_mapping_unique');
        });

        Schema::create('unresolved_jurisdictions', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('recruitment_campaign_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('district_id')->nullable()->constrained('administrative_units')->nullOnDelete();
            $table->string('source_value')->nullable();
            $table->string('status', 30)->default('open')->index();
            $table->text('resolution_note')->nullable();
            $table->foreignId('resolved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampTz('resolved_at')->nullable();
            $table->timestampsTz();
        });

        Schema::create('user_scopes', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('scope_type', 30);
            $table->string('scope_id', 26)->nullable();
            $table->json('allowed_tasks')->nullable();
            $table->timestampTz('expires_at')->nullable();
            $table->foreignId('assigned_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampsTz();
            $table->unique(['user_id', 'scope_type', 'scope_id'], 'user_scope_unique');
            $table->index(['scope_type', 'scope_id']);
        });

        Schema::create('reference_sequences', function (Blueprint $table) {
            $table->ulid('recruitment_post_id')->primary();
            $table->unsignedBigInteger('next_value')->default(1);
            $table->timestampsTz();
            $table->foreign('recruitment_post_id')->references('id')->on('recruitment_posts')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('reference_sequences');
        Schema::dropIfExists('user_scopes');
        Schema::dropIfExists('unresolved_jurisdictions');
        Schema::dropIfExists('district_centre_mappings');
        Schema::dropIfExists('recruitment_centres');
        Schema::dropIfExists('prison_regions');
        Schema::dropIfExists('administrative_units');
        Schema::dropIfExists('campaign_document_requirements');
        Schema::dropIfExists('campaign_stages');
        Schema::dropIfExists('campaign_versions');
        Schema::dropIfExists('recruitment_posts');
        Schema::dropIfExists('recruitment_campaigns');
        Schema::dropIfExists('recruitment_templates');
        Schema::dropIfExists('role_user');
        Schema::dropIfExists('permission_role');
        Schema::dropIfExists('permissions');
        Schema::dropIfExists('roles');
    }
};
