<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('exports', function (Blueprint $table) {
            $table->string('title')->nullable()->after('export_type');
            $table->text('failure_reason')->nullable()->after('sha256');
            $table->timestampTz('completed_at')->nullable()->after('failure_reason');
        });
    }

    public function down(): void
    {
        Schema::table('exports', function (Blueprint $table) {
            $table->dropColumn(['title', 'failure_reason', 'completed_at']);
        });
    }
};
