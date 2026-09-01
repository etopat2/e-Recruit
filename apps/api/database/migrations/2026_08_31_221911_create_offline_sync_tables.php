<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('registered_devices', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->uuid('public_identifier')->unique();
            $table->string('label');
            $table->string('platform')->nullable();
            $table->string('public_key', 2048)->nullable();
            $table->string('status', 20)->default('active')->index();
            $table->timestampTz('enrolled_at');
            $table->timestampTz('last_seen_at')->nullable();
            $table->timestampTz('last_sync_at')->nullable();
            $table->timestampTz('revoked_at')->nullable();
            $table->foreignId('revoked_by')->nullable()->constrained('users')->nullOnDelete();
            $table->text('revocation_reason')->nullable();
            $table->timestampsTz();
        });

        Schema::create('offline_packages', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('registered_device_id')->constrained()->restrictOnDelete();
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->string('pack_type', 40);
            $table->unsignedInteger('version')->default(1);
            $table->json('scope');
            $table->json('permitted_actions');
            $table->json('manifest');
            $table->char('manifest_fingerprint', 64);
            $table->string('status', 20)->default('active')->index();
            $table->timestampTz('issued_at');
            $table->timestampTz('expires_at')->index();
            $table->timestampTz('last_sync_at')->nullable();
            $table->timestampTz('revoked_at')->nullable();
            $table->unsignedInteger('outstanding_events')->default(0);
            $table->timestampsTz();
            $table->index(['user_id', 'pack_type', 'status']);
        });

        Schema::create('offline_package_records', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('offline_package_id')->constrained()->cascadeOnDelete();
            $table->string('entity_type', 80);
            $table->string('entity_id', 64);
            $table->unsignedBigInteger('server_version');
            $table->char('payload_fingerprint', 64);
            $table->timestampsTz();
            $table->unique(['offline_package_id', 'entity_type', 'entity_id'], 'offline_pack_entity_unique');
        });

        Schema::create('sync_batches', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('offline_package_id')->constrained()->restrictOnDelete();
            $table->foreignUlid('registered_device_id')->constrained()->restrictOnDelete();
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->string('status', 30)->default('processing');
            $table->unsignedInteger('accepted_count')->default(0);
            $table->unsignedInteger('rejected_count')->default(0);
            $table->unsignedInteger('conflict_count')->default(0);
            $table->string('server_cursor')->nullable();
            $table->timestampTz('started_at');
            $table->timestampTz('completed_at')->nullable();
            $table->timestampsTz();
        });

        Schema::create('offline_events', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUlid('offline_package_id')->constrained()->restrictOnDelete();
            $table->foreignUlid('sync_batch_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignUlid('registered_device_id')->constrained()->restrictOnDelete();
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->string('entity_type', 80);
            $table->string('entity_id', 64);
            $table->string('action_type', 80);
            $table->unsignedSmallInteger('payload_schema_version');
            $table->json('payload');
            $table->unsignedBigInteger('base_entity_version');
            $table->unsignedBigInteger('local_sequence');
            $table->timestampTz('local_timestamp');
            $table->timestampTz('received_at');
            $table->string('sync_state', 30)->default('accepted')->index();
            $table->text('error')->nullable();
            $table->timestampsTz();
            $table->unique(['offline_package_id', 'local_sequence']);
            $table->index(['entity_type', 'entity_id', 'action_type']);
        });

        Schema::create('sync_conflicts', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUuid('offline_event_id')->constrained()->restrictOnDelete();
            $table->string('entity_type', 80);
            $table->string('entity_id', 64);
            $table->string('field_key', 120);
            $table->json('local_value');
            $table->json('server_value');
            $table->unsignedBigInteger('local_base_version');
            $table->unsignedBigInteger('server_version');
            $table->string('status', 30)->default('open')->index();
            $table->string('resolution', 40)->nullable();
            $table->json('resolved_value')->nullable();
            $table->text('resolution_reason')->nullable();
            $table->foreignId('resolved_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestampTz('resolved_at')->nullable();
            $table->timestampsTz();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sync_conflicts');
        Schema::dropIfExists('offline_events');
        Schema::dropIfExists('sync_batches');
        Schema::dropIfExists('offline_package_records');
        Schema::dropIfExists('offline_packages');
        Schema::dropIfExists('registered_devices');
    }
};
