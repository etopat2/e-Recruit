<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('score_adjustments', function (Blueprint $table): void {
            $table->string('status', 30)->default('pending')->index();
            $table->text('decision_reason')->nullable();
            $table->timestampTz('decided_at')->nullable();
        });

        Schema::table('notifications', function (Blueprint $table): void {
            $table->timestampTz('read_at')->nullable()->index();
        });

        Schema::table('training_invites', function (Blueprint $table): void {
            $table->char('sha256', 64)->nullable();
        });

        Schema::create('notification_attempts', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('notification_id')->constrained()->cascadeOnDelete();
            $table->unsignedSmallInteger('attempt_number');
            $table->string('channel', 30);
            $table->string('provider', 80)->nullable();
            $table->string('status', 30)->index();
            $table->string('provider_message_id')->nullable();
            $table->json('response_metadata')->nullable();
            $table->string('error_code', 80)->nullable();
            $table->text('error_message')->nullable();
            $table->timestampTz('started_at');
            $table->timestampTz('completed_at')->nullable();
            $table->timestampsTz();
            $table->unique(['notification_id', 'attempt_number']);
        });

        Schema::create('push_subscriptions', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->char('endpoint_hash', 64)->unique();
            $table->text('endpoint_encrypted');
            $table->text('public_key_encrypted');
            $table->text('auth_token_encrypted');
            $table->string('content_encoding', 30)->default('aes128gcm');
            $table->string('user_agent', 500)->nullable();
            $table->timestampTz('last_used_at')->nullable();
            $table->timestampTz('revoked_at')->nullable();
            $table->timestampsTz();
            $table->index(['user_id', 'revoked_at']);
        });

        Schema::create('interview_invitations', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('interview_assignment_id')->constrained()->restrictOnDelete();
            $table->json('instructions');
            $table->string('document_path', 1024);
            $table->char('sha256', 64);
            $table->foreignId('issued_by')->constrained('users')->restrictOnDelete();
            $table->timestampTz('issued_at');
            $table->timestampsTz();
            $table->unique('interview_assignment_id');
        });

        Schema::create('medical_invitations', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('application_id')->constrained()->restrictOnDelete();
            $table->foreignUlid('medical_schedule_id')->constrained()->restrictOnDelete();
            $table->json('instructions');
            $table->string('document_path', 1024);
            $table->char('sha256', 64);
            $table->foreignId('issued_by')->constrained('users')->restrictOnDelete();
            $table->timestampTz('issued_at');
            $table->timestampsTz();
            $table->unique(['application_id', 'medical_schedule_id']);
        });

        Schema::create('reserve_replacement_recommendations', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('selection_run_id')->constrained()->restrictOnDelete();
            $table->foreignUlid('replaced_application_id')->constrained('applications')->restrictOnDelete();
            $table->foreignUlid('reserve_application_id')->constrained('applications')->restrictOnDelete();
            $table->string('trigger', 40);
            $table->text('reason');
            $table->string('status', 30)->default('pending_approval')->index();
            $table->foreignId('recommended_by')->constrained('users')->restrictOnDelete();
            $table->foreignId('decided_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->text('decision_reason')->nullable();
            $table->string('approval_reference')->nullable();
            $table->timestampTz('decided_at')->nullable();
            $table->timestampsTz();
            $table->unique(['selection_run_id', 'replaced_application_id', 'reserve_application_id'], 'reserve_replacement_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('reserve_replacement_recommendations');
        Schema::dropIfExists('medical_invitations');
        Schema::dropIfExists('interview_invitations');
        Schema::dropIfExists('push_subscriptions');
        Schema::dropIfExists('notification_attempts');

        Schema::table('notifications', function (Blueprint $table): void {
            $table->dropColumn('read_at');
        });

        Schema::table('training_invites', function (Blueprint $table): void {
            $table->dropColumn('sha256');
        });

        Schema::table('score_adjustments', function (Blueprint $table): void {
            $table->dropColumn(['status', 'decision_reason', 'decided_at']);
        });
    }
};
