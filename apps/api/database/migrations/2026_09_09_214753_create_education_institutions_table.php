<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('education_institutions', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->string('source', 30);
            $table->string('source_id', 100);
            $table->string('name');
            $table->string('normalized_name');
            $table->string('institution_type', 100)->nullable();
            $table->string('district', 120)->nullable();
            $table->string('registration_number', 120)->nullable();
            $table->string('registration_status', 160)->nullable();
            $table->string('operational_status', 80)->nullable();
            $table->json('qualification_levels');
            $table->string('source_url', 500);
            $table->json('source_payload')->nullable();
            $table->timestampTz('last_verified_at');
            $table->boolean('active')->default(true);
            $table->timestamps();

            $table->unique(['source', 'source_id']);
            $table->index(['active', 'source']);
            $table->index('normalized_name');
        });

        if (DB::connection()->getDriverName() === 'pgsql') {
            DB::statement('CREATE EXTENSION IF NOT EXISTS pg_trgm');
            DB::statement('CREATE INDEX education_institutions_name_trgm_index ON education_institutions USING gin (normalized_name gin_trgm_ops)');
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('education_institutions');
    }
};
