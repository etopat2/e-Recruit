<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('assessment_score_imports', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('assessment_definition_id')->constrained()->restrictOnDelete();
            $table->foreignUlid('centre_session_id')->nullable()->constrained()->restrictOnDelete();
            $table->string('source_filename');
            $table->string('storage_disk', 30);
            $table->string('source_path', 1024);
            $table->char('source_sha256', 64);
            $table->string('status', 30)->default('validating')->index();
            $table->unsignedInteger('total_rows')->default(0);
            $table->unsignedInteger('accepted_rows')->default(0);
            $table->unsignedInteger('rejected_rows')->default(0);
            $table->string('error_report_path', 1024)->nullable();
            $table->text('purpose');
            $table->foreignId('imported_by')->constrained('users')->restrictOnDelete();
            $table->timestampTz('completed_at')->nullable();
            $table->timestampsTz();
        });

        Schema::create('assessment_score_import_rows', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('assessment_score_import_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('row_number');
            $table->string('application_reference')->nullable();
            $table->string('raw_score')->nullable();
            $table->text('notes')->nullable();
            $table->string('status', 30)->index();
            $table->json('errors')->nullable();
            $table->foreignUlid('assessment_score_id')->nullable()->constrained()->nullOnDelete();
            $table->timestampsTz();
            $table->unique(['assessment_score_import_id', 'row_number'], 'assessment_import_row_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('assessment_score_import_rows');
        Schema::dropIfExists('assessment_score_imports');
    }
};
