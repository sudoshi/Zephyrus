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
        // Create users with matching usernames and passwords
        $users = [
            [
                'name' => 'Sanjay',
                'email' => 'sanjay@example.com',
                'username' => 'sanjay',
                'password' => 'sanjay'
            ],
            [
                'name' => 'Acumenus',
                'email' => 'acumenus@example.com',
                'username' => 'acumenus',
                'password' => 'acumenus'
            ],
            [
                'name' => 'Kartheek',
                'email' => 'kartheek@example.com',
                'username' => 'kartheek',
                'password' => 'kartheek'
            ],
            [
                'name' => 'Hakan',
                'email' => 'hakan@example.com',
                'username' => 'hakan',
                'password' => 'hakan'
            ]
        ];

        foreach ($users as $userData) {
            User::updateOrCreate(
                ['email' => $userData['email']],
                [
                    'name' => $userData['name'],
                    'username' => $userData['username'],
                    'password' => Hash::make($userData['password']),
                ]
            );
        }
    }
}
