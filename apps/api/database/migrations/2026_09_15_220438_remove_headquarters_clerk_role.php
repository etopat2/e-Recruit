<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        $legacyRole = DB::table('roles')->where('code', 'hard_copy_receiving_officer')->first();
        $verificationRole = DB::table('roles')->where('code', 'verification_officer')->first();

        if ($legacyRole !== null && $verificationRole === null) {
            $verificationRoleId = DB::table('roles')->insertGetId([
                'code' => 'verification_officer',
                'name' => 'Verification Officer',
                'is_decision_role' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            $verificationRole = DB::table('roles')->where('id', $verificationRoleId)->first();
        }

        if ($legacyRole !== null && $verificationRole !== null) {
            $assignments = DB::table('role_user')->where('role_id', $legacyRole->id)->get();
            foreach ($assignments as $assignment) {
                DB::table('role_user')->insertOrIgnore([
                    'role_id' => $verificationRole->id,
                    'user_id' => $assignment->user_id,
                    'assigned_by' => $assignment->assigned_by,
                    'expires_at' => $assignment->expires_at,
                    'created_at' => $assignment->created_at,
                    'updated_at' => now(),
                ]);
            }
        }

        DB::table('users')->where('user_type', 'hard_copy_receiving_officer')->update([
            'user_type' => 'verification_officer',
            'updated_at' => now(),
        ]);
        DB::table('roles')->where('code', 'hard_copy_receiving_officer')->delete();
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        DB::table('roles')->insertOrIgnore([
            'code' => 'hard_copy_receiving_officer',
            'name' => 'Headquarters Hard-copy Clerk',
            'is_decision_role' => false,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
};
