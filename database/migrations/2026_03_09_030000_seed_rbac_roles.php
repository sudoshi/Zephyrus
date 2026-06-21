<?php

use App\Models\User;
use Illuminate\Database\Migrations\Migration;
use Spatie\Permission\Models\Role;

return new class extends Migration
{
    public function up(): void
    {
        // Idempotent: safe to run on fresh DBs and re-run on already-seeded DBs.
        // Ordered (030000) to run AFTER create_permission_tables (020428) so the
        // `roles` table exists. Replaces the misordered 000001 seed.
        $superAdmin = Role::firstOrCreate(['name' => 'super-admin']);
        Role::firstOrCreate(['name' => 'admin']);
        Role::firstOrCreate(['name' => 'user']);

        // Assign super-admin to existing admin users (assignRole is idempotent).
        $adminUsernames = ['acumenus', 'sanjay'];
        User::whereIn('username', $adminUsernames)->each(function ($user) use ($superAdmin) {
            $user->assignRole($superAdmin);
        });

        // Assign 'user' role to everyone else who has no role yet.
        User::whereNotIn('username', $adminUsernames)->each(function ($user) {
            if ($user->roles()->count() === 0) {
                $user->assignRole('user');
            }
        });
    }

    public function down(): void
    {
        Role::whereIn('name', ['super-admin', 'admin', 'user'])->delete();
    }
};
