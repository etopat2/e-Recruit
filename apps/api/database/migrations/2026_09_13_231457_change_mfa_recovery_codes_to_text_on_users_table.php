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
        if (DB::connection()->getDriverName() === 'pgsql') {
            DB::statement(<<<'SQL'
                ALTER TABLE users
                ALTER COLUMN mfa_recovery_codes TYPE TEXT
                USING CASE
                    WHEN json_typeof(mfa_recovery_codes) = 'string'
                        THEN mfa_recovery_codes #>> '{}'
                    ELSE mfa_recovery_codes::text
                END
                SQL);

            return;
        }

        Schema::table('users', function (Blueprint $table) {
            $table->text('mfa_recovery_codes')->nullable()->change();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (DB::connection()->getDriverName() === 'pgsql') {
            DB::statement(<<<'SQL'
                ALTER TABLE users
                ALTER COLUMN mfa_recovery_codes TYPE JSON
                USING to_json(mfa_recovery_codes)
                SQL);

            return;
        }

        Schema::table('users', function (Blueprint $table) {
            $table->json('mfa_recovery_codes')->nullable()->change();
        });
    }
};
