<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class UserSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        // Create default admin user first
        User::updateOrCreate(
            ['username' => 'admin'],
            [
                'name' => 'Administrator',
                'email' => 'admin@example.com',
                'password' => Hash::make('password'),
                'workflow_preference' => 'superuser',
            ]
        );

        // Create users with matching usernames and passwords
        $users = [
            [
                'name' => 'Sanjay',
                'email' => 'sanjay@example.com',
                'username' => 'sanjay',
                'password' => 'sanjay',
                'workflow_preference' => 'perioperative',
            ],
            [
                'name' => 'Acumenus',
                'email' => 'acumenus@example.com',
                'username' => 'acumenus',
                'password' => 'acumenus',
                'workflow_preference' => 'superuser',
            ],
            [
                'name' => 'Kartheek',
                'email' => 'kartheek@example.com',
                'username' => 'kartheek',
                'password' => 'kartheek',
                'workflow_preference' => 'rtdc',
            ],
            [
                'name' => 'Hakan',
                'email' => 'hakan@example.com',
                'username' => 'hakan',
                'password' => 'hakan',
                'workflow_preference' => 'improvement',
            ]
        ];

        foreach ($users as $userData) {
            User::updateOrCreate(
                ['email' => $userData['email']],
                [
                    'name' => $userData['name'],
                    'username' => $userData['username'],
                    'password' => Hash::make($userData['password']),
                    'workflow_preference' => $userData['workflow_preference'] ?? 'superuser',
                ]
            );
        }
    }
}
