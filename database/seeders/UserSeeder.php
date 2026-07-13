<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use RuntimeException;

class UserSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        if (app()->environment('production')) {
            $this->command?->warn('UserSeeder skipped: production identities must come from governed administration or institutional SSO.');

            return;
        }

        $environment = static fn (string $key): string => (string) (getenv($key) ?: '');
        $username = trim($environment('DEMO_BOOTSTRAP_USERNAME'));
        $email = strtolower(trim($environment('DEMO_BOOTSTRAP_EMAIL')));
        $password = $environment('DEMO_BOOTSTRAP_PASSWORD');

        if ($username === '' && $email === '' && $password === '') {
            $this->command?->warn('UserSeeder skipped: no explicit DEMO_BOOTSTRAP_* identity was supplied.');

            return;
        }
        if (! (bool) config('demo.enabled')) {
            throw new RuntimeException('DEMO_MODE=true is required before provisioning a bootstrap demo identity.');
        }
        if ($username === '' || filter_var($email, FILTER_VALIDATE_EMAIL) === false || strlen($password) < 16) {
            throw new RuntimeException('DEMO_BOOTSTRAP_USERNAME, a valid DEMO_BOOTSTRAP_EMAIL, and a 16+ character DEMO_BOOTSTRAP_PASSWORD are required.');
        }

        User::updateOrCreate(['username' => $username], [
            'name' => 'Demo bootstrap operator',
            'email' => $email,
            'password' => Hash::make($password),
            'workflow_preference' => 'superuser',
            'role' => 'user',
            'is_active' => true,
            'must_change_password' => true,
        ]);
    }
}
