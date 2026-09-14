<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->string('mfa_method', 30)->nullable()->after('is_privileged')->index();
        });

        DB::table('users')->whereNotNull('mfa_secret')->update(['mfa_method' => 'authenticator']);

        Schema::create('email_otp_challenges', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('method', 30)->default('email');
            $table->string('purpose', 50);
            $table->string('code_hash');
            $table->char('binding_hash', 64);
            $table->timestampTz('expires_at');
            $table->unsignedSmallInteger('attempt_count')->default(0);
            $table->timestampTz('consumed_at')->nullable();
            $table->timestampTz('last_sent_at');
            $table->timestampsTz();
            $table->index(['user_id', 'purpose', 'consumed_at']);
            $table->index('expires_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('email_otp_challenges');
        Schema::table('users', function (Blueprint $table): void {
            $table->dropColumn('mfa_method');
        });
    }
};
