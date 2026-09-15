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
        Schema::create('interview_allocation_results', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('interview_allocation_run_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('district_id')->constrained('administrative_units')->restrictOnDelete();
            $table->foreignUlid('recruitment_centre_id')->constrained()->restrictOnDelete();
            $table->unsignedInteger('candidate_count');
            $table->unsignedInteger('centre_load_after_assignment');
            $table->timestamps();
            $table->unique(['interview_allocation_run_id', 'district_id'], 'interview_allocation_district_unique');
            $table->index(['interview_allocation_run_id', 'recruitment_centre_id'], 'interview_allocation_centre_index');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('interview_allocation_results');
    }
};
