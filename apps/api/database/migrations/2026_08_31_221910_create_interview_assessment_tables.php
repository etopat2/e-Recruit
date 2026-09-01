<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('eligibility_runs', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('application_id')->constrained()->restrictOnDelete();
            $table->foreignUlid('campaign_version_id')->constrained()->restrictOnDelete();
            $table->string('status', 30)->index();
            $table->json('input_snapshot');
            $table->char('input_fingerprint', 64);
            $table->foreignId('run_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampTz('run_at');
            $table->timestampsTz();
            $table->index(['application_id', 'run_at']);
        });

        Schema::create('eligibility_rule_results', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('eligibility_run_id')->constrained()->cascadeOnDelete();
            $table->string('rule_id', 100);
            $table->unsignedInteger('rule_version');
            $table->string('outcome', 20);
            $table->text('explanation');
            $table->json('input_values');
            $table->json('evidence_references')->nullable();
            $table->timestampsTz();
            $table->unique(['eligibility_run_id', 'rule_id']);
        });

        Schema::create('centre_sessions', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('recruitment_centre_id')->constrained()->restrictOnDelete();
            $table->foreignUlid('recruitment_post_id')->constrained()->restrictOnDelete();
            $table->string('code', 50);
            $table->date('session_date');
            $table->time('reporting_time');
            $table->string('room')->nullable();
            $table->unsignedInteger('capacity');
            $table->string('status', 30)->default('scheduled');
            $table->timestampsTz();
            $table->unique(['recruitment_centre_id', 'code', 'session_date']);
        });

        Schema::create('panels', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('centre_session_id')->constrained()->cascadeOnDelete();
            $table->string('code', 50);
            $table->string('name');
            $table->unsignedInteger('capacity');
            $table->string('status', 30)->default('open');
            $table->timestampsTz();
            $table->unique(['centre_session_id', 'code']);
        });

        Schema::create('panel_members', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('panel_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->string('panel_role', 30);
            $table->timestampTz('effective_from');
            $table->timestampTz('effective_to')->nullable();
            $table->timestampsTz();
            $table->unique(['panel_id', 'user_id', 'effective_from']);
        });

        Schema::create('interview_assignments', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('application_id')->constrained()->restrictOnDelete();
            $table->foreignUlid('centre_session_id')->constrained()->restrictOnDelete();
            $table->foreignUlid('panel_id')->constrained()->restrictOnDelete();
            $table->unsignedInteger('assignment_order');
            $table->string('algorithm_version', 30);
            $table->char('input_fingerprint', 64);
            $table->boolean('manual_adjustment')->default(false);
            $table->text('adjustment_reason')->nullable();
            $table->foreignId('assigned_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampsTz();
            $table->unique('application_id');
            $table->unique(['panel_id', 'assignment_order']);
        });

        Schema::create('attendance_records', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('interview_assignment_id')->constrained()->restrictOnDelete();
            $table->string('status', 30)->index();
            $table->timestampTz('recorded_at');
            $table->foreignId('recorded_by')->constrained('users')->restrictOnDelete();
            $table->text('exception_reason')->nullable();
            $table->unsignedBigInteger('entity_version')->default(1);
            $table->timestampsTz();
            $table->unique('interview_assignment_id');
        });

        Schema::create('assessment_definitions', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('recruitment_post_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('campaign_version_id')->constrained()->restrictOnDelete();
            $table->string('code', 60);
            $table->string('name');
            $table->string('component_type', 60);
            $table->decimal('maximum_mark', 8, 2);
            $table->decimal('pass_mark', 8, 2)->nullable();
            $table->decimal('weight', 7, 4);
            $table->boolean('mandatory')->default(true);
            $table->string('assessor_model', 40);
            $table->string('aggregation_method', 40);
            $table->decimal('divergence_threshold', 8, 2)->nullable();
            $table->boolean('blind_scoring')->default(false);
            $table->timestampsTz();
            $table->unique(['recruitment_post_id', 'campaign_version_id', 'code'], 'assessment_definition_version_unique');
        });

        Schema::create('assessment_scores', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('interview_assignment_id')->constrained()->restrictOnDelete();
            $table->foreignUlid('assessment_definition_id')->constrained()->restrictOnDelete();
            $table->foreignId('assessor_id')->constrained('users')->restrictOnDelete();
            $table->decimal('score', 8, 2);
            $table->text('notes')->nullable();
            $table->string('status', 30)->default('draft');
            $table->unsignedBigInteger('entity_version')->default(1);
            $table->timestampTz('submitted_at')->nullable();
            $table->timestampsTz();
            $table->unique(['interview_assignment_id', 'assessment_definition_id', 'assessor_id'], 'assessment_score_owner_unique');
        });

        Schema::create('score_adjustments', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('assessment_score_id')->constrained()->restrictOnDelete();
            $table->decimal('previous_score', 8, 2);
            $table->decimal('new_score', 8, 2);
            $table->string('reason_code', 50);
            $table->text('justification');
            $table->foreignId('requested_by')->constrained('users')->restrictOnDelete();
            $table->foreignId('approved_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestampTz('approved_at')->nullable();
            $table->timestampsTz();
        });

        Schema::create('panel_closures', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('panel_id')->constrained()->restrictOnDelete();
            $table->foreignId('closed_by')->constrained('users')->restrictOnDelete();
            $table->timestampTz('closed_at');
            $table->char('score_fingerprint', 64);
            $table->string('status', 30)->default('closed');
            $table->text('reopen_reason')->nullable();
            $table->foreignId('reopened_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestampTz('reopened_at')->nullable();
            $table->timestampsTz();
        });

        Schema::create('score_imports', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('recruitment_post_id')->constrained()->restrictOnDelete();
            $table->foreignUlid('assessment_definition_id')->constrained()->restrictOnDelete();
            $table->foreignId('imported_by')->constrained('users')->restrictOnDelete();
            $table->string('source_filename');
            $table->char('source_sha256', 64);
            $table->string('status', 30);
            $table->unsignedInteger('accepted_rows')->default(0);
            $table->unsignedInteger('rejected_rows')->default(0);
            $table->json('error_report')->nullable();
            $table->timestampsTz();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('score_imports');
        Schema::dropIfExists('panel_closures');
        Schema::dropIfExists('score_adjustments');
        Schema::dropIfExists('assessment_scores');
        Schema::dropIfExists('assessment_definitions');
        Schema::dropIfExists('attendance_records');
        Schema::dropIfExists('interview_assignments');
        Schema::dropIfExists('panel_members');
        Schema::dropIfExists('panels');
        Schema::dropIfExists('centre_sessions');
        Schema::dropIfExists('eligibility_rule_results');
        Schema::dropIfExists('eligibility_runs');
    }
};
