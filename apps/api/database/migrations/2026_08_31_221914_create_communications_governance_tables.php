<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('notification_templates', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('recruitment_campaign_id')->nullable()->constrained()->cascadeOnDelete();
            $table->foreignUlid('recruitment_post_id')->nullable()->constrained()->cascadeOnDelete();
            $table->string('event_code', 80);
            $table->string('channel', 30);
            $table->unsignedInteger('version');
            $table->string('subject')->nullable();
            $table->text('body');
            $table->boolean('active')->default(true);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampsTz();
            $table->unique(['recruitment_campaign_id', 'recruitment_post_id', 'event_code', 'channel', 'version'], 'notification_template_version_unique');
        });

        Schema::create('notifications', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('application_id')->nullable()->constrained()->cascadeOnDelete();
            $table->foreignUlid('notification_template_id')->nullable()->constrained()->nullOnDelete();
            $table->string('event_code', 80);
            $table->string('channel', 30);
            $table->string('recipient');
            $table->string('status', 30)->default('pending')->index();
            $table->unsignedSmallInteger('attempt_count')->default(0);
            $table->timestampTz('next_attempt_at')->nullable();
            $table->timestampTz('delivered_at')->nullable();
            $table->json('provider_metadata')->nullable();
            $table->text('last_error')->nullable();
            $table->char('idempotency_key', 64)->unique();
            $table->timestampsTz();
        });

        Schema::create('helpdesk_tickets', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('application_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignUlid('recruitment_campaign_id')->constrained()->restrictOnDelete();
            $table->foreignId('opened_by')->constrained('users')->restrictOnDelete();
            $table->string('category', 60);
            $table->string('subject');
            $table->text('description');
            $table->string('status', 30)->default('open')->index();
            $table->foreignId('assigned_to')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampTz('first_response_due_at')->nullable();
            $table->timestampTz('resolution_due_at')->nullable();
            $table->timestampTz('resolved_at')->nullable();
            $table->timestampsTz();
        });

        Schema::create('helpdesk_messages', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('helpdesk_ticket_id')->constrained()->cascadeOnDelete();
            $table->foreignId('author_id')->constrained('users')->restrictOnDelete();
            $table->text('message');
            $table->json('attachment_references')->nullable();
            $table->boolean('internal_only')->default(false);
            $table->timestampsTz();
        });

        Schema::create('appeals', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('application_id')->constrained()->restrictOnDelete();
            $table->foreignId('submitted_by')->constrained('users')->restrictOnDelete();
            $table->string('category', 60);
            $table->text('grounds');
            $table->json('evidence_references')->nullable();
            $table->string('status', 30)->default('submitted')->index();
            $table->foreignId('decided_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->text('decision_reason')->nullable();
            $table->timestampTz('decided_at')->nullable();
            $table->foreignUlid('resulting_eligibility_run_id')->nullable()->constrained('eligibility_runs')->nullOnDelete();
            $table->timestampsTz();
        });

        Schema::create('exports', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignId('requested_by')->constrained('users')->restrictOnDelete();
            $table->string('export_type', 80);
            $table->string('format', 20);
            $table->json('scope');
            $table->json('filters')->nullable();
            $table->json('masking_policy');
            $table->text('purpose');
            $table->string('status', 30)->default('pending')->index();
            $table->string('storage_path')->nullable();
            $table->char('sha256', 64)->nullable();
            $table->timestampTz('expires_at')->nullable();
            $table->timestampsTz();
        });

        Schema::create('audit_logs', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignId('actor_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('action', 120)->index();
            $table->string('entity_type', 120);
            $table->string('entity_id', 64)->nullable();
            $table->json('before_values')->nullable();
            $table->json('after_values')->nullable();
            $table->text('reason')->nullable();
            $table->string('approval_reference')->nullable();
            $table->uuid('correlation_id')->index();
            $table->string('session_id')->nullable();
            $table->string('device_id', 64)->nullable();
            $table->string('ip_address', 45)->nullable();
            $table->string('user_agent', 500)->nullable();
            $table->char('previous_hash', 64)->nullable();
            $table->char('entry_hash', 64)->unique();
            $table->timestampTz('occurred_at')->index();
            $table->timestampsTz();
            $table->index(['entity_type', 'entity_id', 'occurred_at']);
        });

        Schema::create('integrity_flags', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->string('indicator_type', 100)->index();
            $table->string('severity', 20)->default('review');
            $table->string('entity_type', 100);
            $table->string('entity_id', 64);
            $table->json('evidence');
            $table->string('status', 30)->default('open')->index();
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->text('review_outcome')->nullable();
            $table->timestampTz('reviewed_at')->nullable();
            $table->timestampsTz();
        });

        Schema::create('retention_policies', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('recruitment_campaign_id')->nullable()->constrained()->cascadeOnDelete();
            $table->string('record_category', 80);
            $table->unsignedInteger('retention_days');
            $table->string('disposition', 30)->default('review_for_purge');
            $table->string('legal_basis_reference')->nullable();
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampTz('approved_at')->nullable();
            $table->timestampsTz();
            $table->unique(['recruitment_campaign_id', 'record_category']);
        });

        Schema::create('legal_holds', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->string('entity_type', 100);
            $table->string('entity_id', 64);
            $table->text('reason');
            $table->foreignId('placed_by')->constrained('users')->restrictOnDelete();
            $table->timestampTz('placed_at');
            $table->foreignId('released_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestampTz('released_at')->nullable();
            $table->timestampsTz();
            $table->index(['entity_type', 'entity_id', 'released_at']);
        });

        Schema::create('purge_requests', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->string('record_category', 80);
            $table->json('scope');
            $table->unsignedBigInteger('eligible_record_count')->default(0);
            $table->string('status', 30)->default('pending_approval');
            $table->text('reason');
            $table->foreignId('requested_by')->constrained('users')->restrictOnDelete();
            $table->foreignId('approved_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestampTz('approved_at')->nullable();
            $table->timestampTz('executed_at')->nullable();
            $table->char('evidence_hash', 64)->nullable();
            $table->timestampsTz();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('purge_requests');
        Schema::dropIfExists('legal_holds');
        Schema::dropIfExists('retention_policies');
        Schema::dropIfExists('integrity_flags');
        Schema::dropIfExists('audit_logs');
        Schema::dropIfExists('exports');
        Schema::dropIfExists('appeals');
        Schema::dropIfExists('helpdesk_messages');
        Schema::dropIfExists('helpdesk_tickets');
        Schema::dropIfExists('notifications');
        Schema::dropIfExists('notification_templates');
    }
};
