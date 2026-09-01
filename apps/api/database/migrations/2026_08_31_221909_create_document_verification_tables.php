<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('upload_sessions', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('application_id')->constrained()->cascadeOnDelete();
            $table->foreignId('initiated_by')->constrained('users')->restrictOnDelete();
            $table->string('document_type', 80);
            $table->string('original_filename');
            $table->unsignedBigInteger('expected_bytes');
            $table->unsignedInteger('chunk_size');
            $table->unsignedInteger('received_chunks')->default(0);
            $table->char('idempotency_key', 64)->unique();
            $table->string('status', 30)->default('initiated');
            $table->timestampTz('expires_at');
            $table->timestampsTz();
        });

        Schema::create('documents', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('application_id')->constrained()->restrictOnDelete();
            $table->foreignUlid('upload_session_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignUlid('replaces_document_id')->nullable()->constrained('documents')->nullOnDelete();
            $table->string('document_type', 80)->index();
            $table->unsignedSmallInteger('version')->default(1);
            $table->string('original_filename');
            $table->string('storage_disk', 30)->default('s3');
            $table->string('original_path', 1024);
            $table->string('preview_path', 1024)->nullable();
            $table->string('mime_type', 100);
            $table->string('detected_mime_type', 100);
            $table->string('extension', 20);
            $table->unsignedBigInteger('size_bytes');
            $table->char('sha256', 64)->index();
            $table->string('malware_status', 30)->default('pending')->index();
            $table->string('processing_status', 30)->default('pending')->index();
            $table->json('quality_indicators')->nullable();
            $table->foreignId('uploaded_by')->constrained('users')->restrictOnDelete();
            $table->timestampTz('uploaded_at');
            $table->timestampsTz();
            $table->unique(['application_id', 'document_type', 'version']);
        });

        Schema::create('document_processing_jobs', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('document_id')->constrained()->cascadeOnDelete();
            $table->string('job_type', 50)->default('ocr');
            $table->string('status', 30)->default('pending')->index();
            $table->unsignedSmallInteger('attempts')->default(0);
            $table->char('idempotency_key', 64)->unique();
            $table->text('last_error')->nullable();
            $table->timestampTz('available_at')->nullable();
            $table->timestampTz('started_at')->nullable();
            $table->timestampTz('completed_at')->nullable();
            $table->timestampsTz();
        });

        Schema::create('document_extractions', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('document_id')->constrained()->cascadeOnDelete();
            $table->string('profile_code', 80);
            $table->unsignedInteger('profile_version');
            $table->string('engine', 80);
            $table->string('engine_version', 100);
            $table->string('status', 30);
            $table->longText('raw_text')->nullable();
            $table->decimal('mean_confidence', 5, 4)->nullable();
            $table->json('quality_indicators')->nullable();
            $table->timestampsTz();
            $table->unique(['document_id', 'profile_code', 'profile_version'], 'document_extraction_profile_unique');
        });

        Schema::create('extracted_fields', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('document_extraction_id')->constrained()->cascadeOnDelete();
            $table->string('field_key', 120);
            $table->text('raw_value')->nullable();
            $table->text('normalised_value')->nullable();
            $table->decimal('confidence', 5, 4)->nullable();
            $table->unsignedSmallInteger('page_number')->nullable();
            $table->json('bounding_polygon')->nullable();
            $table->timestampsTz();
            $table->index(['field_key', 'normalised_value']);
        });

        Schema::create('document_comparisons', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('application_id')->constrained()->cascadeOnDelete();
            $table->string('field_key', 120);
            $table->string('left_source_type', 50);
            $table->string('left_source_id', 26)->nullable();
            $table->text('left_value')->nullable();
            $table->string('right_source_type', 50);
            $table->string('right_source_id', 26)->nullable();
            $table->text('right_value')->nullable();
            $table->string('outcome', 40)->index();
            $table->decimal('similarity', 5, 4)->nullable();
            $table->string('algorithm_version', 30);
            $table->text('explanation')->nullable();
            $table->timestampsTz();
            $table->index(['application_id', 'field_key']);
        });

        Schema::create('verified_values', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('application_id')->constrained()->restrictOnDelete();
            $table->foreignUlid('supersedes_id')->nullable()->constrained('verified_values')->nullOnDelete();
            $table->string('field_key', 120);
            $table->json('verified_value');
            $table->json('evidence_references');
            $table->string('verification_method', 50);
            $table->text('reason')->nullable();
            $table->foreignId('verified_by')->constrained('users')->restrictOnDelete();
            $table->timestampTz('verified_at');
            $table->boolean('current')->default(true);
            $table->timestampsTz();
            $table->index(['application_id', 'field_key', 'current']);
        });

        Schema::create('document_verifications', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('document_id')->constrained()->restrictOnDelete();
            $table->foreignUlid('extracted_field_id')->nullable()->constrained()->nullOnDelete();
            $table->string('action', 50);
            $table->string('outcome', 50);
            $table->text('reason')->nullable();
            $table->json('review_state')->nullable();
            $table->foreignId('reviewed_by')->constrained('users')->restrictOnDelete();
            $table->timestampTz('reviewed_at');
            $table->timestampsTz();
        });

        Schema::create('hard_copy_receipts', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('application_id')->constrained()->restrictOnDelete();
            $table->string('receiving_office');
            $table->foreignId('received_by')->constrained('users')->restrictOnDelete();
            $table->timestampTz('received_at');
            $table->string('receipt_number', 80)->unique();
            $table->string('status', 40)->default('received');
            $table->text('notes')->nullable();
            $table->timestampsTz();
        });

        Schema::create('physical_document_checks', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('hard_copy_receipt_id')->constrained()->cascadeOnDelete();
            $table->string('document_type', 80);
            $table->string('status', 60);
            $table->text('notes')->nullable();
            $table->json('discrepancy_evidence')->nullable();
            $table->timestampsTz();
            $table->unique(['hard_copy_receipt_id', 'document_type']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('physical_document_checks');
        Schema::dropIfExists('hard_copy_receipts');
        Schema::dropIfExists('document_verifications');
        Schema::dropIfExists('verified_values');
        Schema::dropIfExists('document_comparisons');
        Schema::dropIfExists('extracted_fields');
        Schema::dropIfExists('document_extractions');
        Schema::dropIfExists('document_processing_jobs');
        Schema::dropIfExists('documents');
        Schema::dropIfExists('upload_sessions');
    }
};
