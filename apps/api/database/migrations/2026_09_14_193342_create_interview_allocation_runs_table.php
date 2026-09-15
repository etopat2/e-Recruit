<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('interview_allocation_runs', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('recruitment_campaign_id')->constrained()->restrictOnDelete();
            $table->foreignUlid('recruitment_post_id')->constrained()->restrictOnDelete();
            $table->foreignUlid('campaign_version_id')->constrained()->restrictOnDelete();
            $table->foreignUlid('prison_region_id')->constrained()->restrictOnDelete();
            $table->unsignedInteger('run_number');
            $table->string('status', 30)->default('preview')->index();
            $table->string('algorithm_version', 40)->default('district-lpt-v1');
            $table->unsignedInteger('candidate_count');
            $table->json('input_snapshot');
            $table->json('result_snapshot');
            $table->char('input_fingerprint', 64);
            $table->char('output_fingerprint', 64);
            $table->foreignId('run_by')->constrained('users')->restrictOnDelete();
            $table->foreignId('committed_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestampTz('committed_at')->nullable();
            $table->timestamps();
            $table->unique(['recruitment_post_id', 'prison_region_id', 'run_number'], 'interview_allocation_run_version_unique');
            $table->index(['recruitment_campaign_id', 'prison_region_id', 'status'], 'interview_allocation_scope_status_index');
        });

        Schema::table('applications', function (Blueprint $table): void {
            $table->string('routing_address_type', 20)->nullable()->after('status');
            $table->foreignUlid('routing_district_id')->nullable()->after('routing_address_type')->constrained('administrative_units')->restrictOnDelete();
            $table->index(['recruitment_post_id', 'routing_district_id', 'status'], 'applications_interview_routing_index');
        });

        Schema::table('interview_assignments', function (Blueprint $table): void {
            $table->foreignUlid('interview_allocation_run_id')->nullable()->after('application_id')->constrained()->nullOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('interview_assignments', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('interview_allocation_run_id');
        });
        Schema::table('applications', function (Blueprint $table): void {
            $table->dropIndex('applications_interview_routing_index');
            $table->dropConstrainedForeignId('routing_district_id');
            $table->dropColumn('routing_address_type');
        });
        Schema::dropIfExists('interview_allocation_runs');
    }
};
