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
        Schema::table('administrative_units', function (Blueprint $table) {
            $table->string('code', 120)->change();
            $table->string('source_id', 100)->nullable()->after('code')->index();
            $table->string('source', 80)->default('manual')->after('source_id')->index();
            $table->string('unit_type', 30)->nullable()->after('level')->index();
        });

        Schema::create('administrative_unit_paths', function (Blueprint $table) {
            $table->foreignUlid('unit_id')->primary()->constrained('administrative_units')->cascadeOnDelete();
            $table->ulid('region_id')->nullable()->index();
            $table->ulid('subregion_id')->nullable()->index();
            $table->ulid('district_id')->nullable()->index();
            $table->ulid('county_id')->nullable()->index();
            $table->ulid('subcounty_id')->nullable()->index();
            $table->ulid('parish_id')->nullable()->index();
            $table->ulid('village_id')->nullable()->index();
            $table->text('full_address');
            $table->text('search_text');
            $table->timestampsTz();
        });

        Schema::create('administrative_unit_imports', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->string('source_file');
            $table->string('source_sha256', 64)->index();
            $table->string('status', 20)->index();
            $table->unsignedInteger('administrative_rows')->default(0);
            $table->unsignedInteger('electoral_rows_skipped')->default(0);
            $table->text('error')->nullable();
            $table->timestampTz('completed_at')->nullable();
            $table->timestampsTz();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('administrative_unit_imports');
        Schema::dropIfExists('administrative_unit_paths');

        Schema::table('administrative_units', function (Blueprint $table) {
            $table->dropColumn(['source_id', 'source', 'unit_type']);
            $table->string('code', 50)->change();
        });
    }
};
