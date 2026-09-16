<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('prison_regions', function (Blueprint $table) {
            $table->string('headquarters')->nullable()->after('name');
            $table->json('source_metadata')->nullable()->after('headquarters');
        });

        Schema::table('recruitment_centres', function (Blueprint $table) {
            $table->foreignUlid('host_administrative_unit_id')->nullable()->after('prison_region_id')->constrained('administrative_units')->nullOnDelete();
            $table->string('host_locality')->nullable()->after('name');
            $table->json('source_metadata')->nullable()->after('host_locality');
        });

        Schema::create('prison_region_jurisdictions', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('prison_region_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('administrative_unit_id')->constrained()->restrictOnDelete();
            $table->string('jurisdiction_type', 20)->default('exclusive');
            $table->json('source_metadata')->nullable();
            $table->timestampsTz();
            $table->unique(['prison_region_id', 'administrative_unit_id'], 'prison_region_unit_unique');
            $table->index(['administrative_unit_id', 'jurisdiction_type']);
        });

        Schema::create('medical_facilities', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->string('code', 30)->unique();
            $table->string('name');
            $table->string('location');
            $table->foreignUlid('host_administrative_unit_id')->nullable()->constrained('administrative_units')->nullOnDelete();
            $table->foreignUlid('prison_region_id')->nullable()->constrained()->nullOnDelete();
            $table->string('region_attribution')->nullable();
            $table->string('referral_rule')->nullable();
            $table->json('source_metadata')->nullable();
            $table->boolean('active')->default(true);
            $table->timestampsTz();
            $table->index(['active', 'name']);
        });

        Schema::table('district_centre_mappings', function (Blueprint $table) {
            $table->json('source_metadata')->nullable()->after('created_by');
        });

        Schema::table('medical_schedules', function (Blueprint $table) {
            $table->foreignUlid('medical_facility_id')->nullable()->after('recruitment_post_id')->constrained('medical_facilities')->restrictOnDelete();
        });

        Schema::create('recruitment_geography_imports', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->string('dataset_version', 40)->unique();
            $table->json('source_hashes');
            $table->json('record_counts');
            $table->json('unresolved_units');
            $table->timestampTz('imported_at');
            $table->timestampsTz();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('recruitment_geography_imports');
        Schema::table('medical_schedules', function (Blueprint $table) {
            $table->dropConstrainedForeignId('medical_facility_id');
        });
        Schema::table('district_centre_mappings', function (Blueprint $table) {
            $table->dropColumn('source_metadata');
        });
        Schema::dropIfExists('medical_facilities');
        Schema::dropIfExists('prison_region_jurisdictions');
        Schema::table('recruitment_centres', function (Blueprint $table) {
            $table->dropConstrainedForeignId('host_administrative_unit_id');
            $table->dropColumn(['host_locality', 'source_metadata']);
        });
        Schema::table('prison_regions', function (Blueprint $table) {
            $table->dropColumn(['headquarters', 'source_metadata']);
        });
    }
};
