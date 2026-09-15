<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::table('roles')
            ->where('code', 'hard_copy_receiving_officer')
            ->update(['name' => 'Headquarters Hard-copy Clerk']);
    }

    public function down(): void
    {
        DB::table('roles')
            ->where('code', 'hard_copy_receiving_officer')
            ->update(['name' => 'Hard-copy Receiving Officer']);
    }
};
