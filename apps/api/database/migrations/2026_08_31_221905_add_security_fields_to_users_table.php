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
        Schema::table('users', function (Blueprint $table) {
            $table->string('phone', 30)->nullable()->unique()->after('email');
            $table->char('nin_hash', 64)->nullable()->index()->after('phone');
            $table->string('user_type', 80)->default('applicant')->index()->after('nin_hash');
            $table->string('status', 30)->default('active')->index()->after('user_type');
            $table->boolean('is_privileged')->default(false)->after('status');
            $table->text('mfa_secret')->nullable()->after('password');
            $table->json('mfa_recovery_codes')->nullable()->after('mfa_secret');
            $table->timestampTz('mfa_confirmed_at')->nullable()->after('mfa_recovery_codes');
            $table->timestampTz('last_login_at')->nullable()->after('mfa_confirmed_at');
            $table->string('locale', 10)->default('en')->after('last_login_at');
            $table->unsignedBigInteger('entity_version')->default(1)->after('locale');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn([
                'phone',
                'nin_hash',
                'user_type',
                'status',
                'is_privileged',
                'mfa_secret',
                'mfa_recovery_codes',
                'mfa_confirmed_at',
                'last_login_at',
                'locale',
                'entity_version',
            ]);
        });
    }
};
