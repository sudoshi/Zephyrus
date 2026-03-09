<?php

use Illuminate\Database\Migrations\Migration;
use Spatie\Permission\Models\Role;
use App\Models\User;

return new class extends Migration
{
    public function up(): void
    {
        // Create roles
        $superAdmin = Role::create(['name' => 'super-admin']);
        Role::create(['name' => 'admin']);
        Role::create(['name' => 'user']);

        // Assign super-admin to existing admin users
        $adminUsernames = ['acumenus', 'sanjay'];
        User::whereIn('username', $adminUsernames)->each(function ($user) use ($superAdmin) {
            $user->assignRole($superAdmin);
        });

        // Assign 'user' role to everyone else
        User::whereNotIn('username', $adminUsernames)->each(function ($user) {
            $user->assignRole('user');
        });
    }

    public function down(): void
    {
        // Remove roles
        Role::whereIn('name', ['super-admin', 'admin', 'user'])->delete();
    }
};
