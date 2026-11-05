<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

class AdminSeeder extends Seeder
{
    public function run(): void
    {
        // Re-running the seeder must not change an existing administrator's login.
        if (User::where('role', 'admin')->orWhere('email', env('ADMIN_EMAIL', 'admin@ahamson.test'))->exists()) {
            return;
        }

        $password = env('ADMIN_PASSWORD') ?: Str::password(24);
        $admin = User::create([
            'name' => env('ADMIN_NAME', 'Administrator'),
            'email' => env('ADMIN_EMAIL', 'admin@ahamson.test'),
            'password' => $password,
            'role' => 'admin',
            'is_active' => false,
        ]);

        $this->command?->info('Legacy account reserved without login access. Link a reviewed Level 1 employee with hrms:link-account, then provision employee credentials.');
    }
}
