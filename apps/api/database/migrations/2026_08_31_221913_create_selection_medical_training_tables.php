<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('quota_configurations', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('recruitment_post_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('campaign_version_id')->constrained()->restrictOnDelete();
            $table->string('dimension', 40);
            $table->string('dimension_value', 80);
            $table->string('parent_quota_id', 26)->nullable();
            $table->unsignedInteger('allocated_slots');
            $table->decimal('minimum_score', 8, 2)->nullable();
            $table->string('fallback_rule', 50)->default('return_to_parent');
            $table->timestampsTz();
            $table->unique(['recruitment_post_id', 'campaign_version_id', 'dimension', 'dimension_value'], 'quota_dimension_unique');
        });

        Schema::create('skill_reservation_rules', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('recruitment_post_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('campaign_version_id')->constrained()->restrictOnDelete();
            $table->foreignUlid('skill_category_id')->constrained()->restrictOnDelete();
            $table->unsignedInteger('reserved_slots');
            $table->decimal('minimum_score', 8, 2)->nullable();
            $table->string('fallback_rule', 50)->default('general_merit');
            $table->timestampsTz();
            $table->unique(['recruitment_post_id', 'campaign_version_id', 'skill_category_id'], 'skill_rule_version_unique');
        });

        Schema::create('ranking_runs', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('recruitment_post_id')->constrained()->restrictOnDelete();
            $table->foreignUlid('campaign_version_id')->constrained()->restrictOnDelete();
            $table->unsignedInteger('run_number');
            $table->string('scope_dimension', 40);
            $table->json('input_snapshot');
            $table->json('score_formula');
            $table->json('tie_break_policy');
            $table->char('fingerprint', 64);
            $table->foreignId('run_by')->constrained('users')->restrictOnDelete();
            $table->timestampTz('run_at');
            $table->timestampsTz();
            $table->unique(['recruitment_post_id', 'run_number']);
        });

        Schema::create('ranking_results', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('ranking_run_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('application_id')->constrained()->restrictOnDelete();
            $table->string('bucket_key', 160);
            $table->decimal('aggregate_score', 10, 4);
            $table->unsignedInteger('merit_rank');
            $table->json('tie_break_values');
            $table->string('tie_break_resolution')->nullable();
            $table->timestampsTz();
            $table->unique(['ranking_run_id', 'application_id']);
            $table->index(['ranking_run_id', 'bucket_key', 'merit_rank']);
        });

        Schema::create('selection_runs', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('ranking_run_id')->constrained()->restrictOnDelete();
            $table->foreignUlid('recruitment_post_id')->constrained()->restrictOnDelete();
            $table->foreignUlid('campaign_version_id')->constrained()->restrictOnDelete();
            $table->unsignedInteger('run_number');
            $table->string('mode', 20)->default('scenario');
            $table->string('status', 30)->default('draft')->index();
            $table->json('parameters');
            $table->json('offline_readiness');
            $table->char('input_fingerprint', 64);
            $table->char('output_fingerprint', 64);
            $table->foreignId('run_by')->constrained('users')->restrictOnDelete();
            $table->foreignId('certified_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestampTz('certified_at')->nullable();
            $table->text('exception_reason')->nullable();
            $table->timestampsTz();
            $table->unique(['recruitment_post_id', 'run_number']);
        });

        Schema::create('selection_outcomes', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('selection_run_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('application_id')->constrained()->restrictOnDelete();
            $table->string('bucket_key', 160);
            $table->string('outcome', 30)->index();
            $table->unsignedInteger('position');
            $table->decimal('score', 10, 4);
            $table->boolean('skill_reservation_applied')->default(false);
            $table->boolean('manual_adjustment')->default(false);
            $table->json('decision_trace');
            $table->timestampsTz();
            $table->unique(['selection_run_id', 'application_id']);
        });

        Schema::create('selection_overrides', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('selection_run_id')->constrained()->restrictOnDelete();
            $table->foreignUlid('application_id')->constrained()->restrictOnDelete();
            $table->foreignUlid('replaced_application_id')->nullable()->constrained('applications')->restrictOnDelete();
            $table->string('previous_outcome', 30);
            $table->string('new_outcome', 30);
            $table->string('reason_code', 60);
            $table->text('justification');
            $table->foreignId('requested_by')->constrained('users')->restrictOnDelete();
            $table->foreignId('approved_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->string('status', 30)->default('pending');
            $table->timestampTz('approved_at')->nullable();
            $table->timestampsTz();
        });

        Schema::create('reserve_list_entries', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('selection_run_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('application_id')->constrained()->restrictOnDelete();
            $table->string('bucket_key', 160);
            $table->unsignedInteger('position');
            $table->string('status', 30)->default('available');
            $table->timestampsTz();
            $table->unique(['selection_run_id', 'application_id']);
            $table->unique(['selection_run_id', 'bucket_key', 'position']);
        });

        Schema::create('medical_schedules', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('recruitment_post_id')->constrained()->restrictOnDelete();
            $table->string('facility');
            $table->date('scheduled_date');
            $table->time('reporting_time');
            $table->unsignedInteger('capacity')->nullable();
            $table->timestampsTz();
        });

        Schema::create('medical_results', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('application_id')->constrained()->restrictOnDelete();
            $table->foreignUlid('medical_schedule_id')->constrained()->restrictOnDelete();
            $table->string('outcome', 40)->index();
            $table->text('restricted_notes')->nullable();
            $table->string('clinical_reference')->nullable();
            $table->foreignId('recorded_by')->constrained('users')->restrictOnDelete();
            $table->timestampTz('recorded_at');
            $table->unsignedBigInteger('entity_version')->default(1);
            $table->timestampsTz();
            $table->unique(['application_id', 'medical_schedule_id']);
        });

        Schema::create('final_selections', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('application_id')->constrained()->restrictOnDelete();
            $table->foreignUlid('selection_outcome_id')->constrained()->restrictOnDelete();
            $table->foreignUlid('medical_result_id')->nullable()->constrained()->restrictOnDelete();
            $table->string('status', 30)->default('pending_approval');
            $table->foreignId('approved_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestampTz('approved_at')->nullable();
            $table->timestampsTz();
            $table->unique('application_id');
        });

        Schema::create('training_invites', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('final_selection_id')->constrained()->restrictOnDelete();
            $table->date('reporting_date');
            $table->time('reporting_time');
            $table->string('location');
            $table->json('instructions');
            $table->string('document_path')->nullable();
            $table->timestampTz('issued_at')->nullable();
            $table->timestampsTz();
        });

        Schema::create('training_reporting', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('training_invite_id')->constrained()->restrictOnDelete();
            $table->string('status', 50)->index();
            $table->timestampTz('recorded_at');
            $table->foreignId('recorded_by')->constrained('users')->restrictOnDelete();
            $table->text('notes')->nullable();
            $table->foreignUlid('replacement_for_application_id')->nullable()->constrained('applications')->restrictOnDelete();
            $table->timestampsTz();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('training_reporting');
        Schema::dropIfExists('training_invites');
        Schema::dropIfExists('final_selections');
        Schema::dropIfExists('medical_results');
        Schema::dropIfExists('medical_schedules');
        Schema::dropIfExists('reserve_list_entries');
        Schema::dropIfExists('selection_overrides');
        Schema::dropIfExists('selection_outcomes');
        Schema::dropIfExists('selection_runs');
        Schema::dropIfExists('ranking_results');
        Schema::dropIfExists('ranking_runs');
        Schema::dropIfExists('skill_reservation_rules');
        Schema::dropIfExists('quota_configurations');
    }
};
