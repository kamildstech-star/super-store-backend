<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;

class PosDemoUserSeeder extends Seeder
{
    public function run(): void
    {
        if (! app()->environment('local')) {
            $this->command?->warn('The POS demo account can only be seeded in the local environment.');

            return;
        }

        $password = config('pos.demo_password');
        if (! is_string($password) || strlen($password) < 8) {
            $this->command?->warn('Set POS_DEMO_PASSWORD to at least 8 characters in the local .env before seeding.');

            return;
        }

        // User hashes passwords on write. An existing account keeps its password.
        User::firstOrCreate(
            ['email' => 'admin@demo.com'],
            ['name' => 'Demo Admin', 'password' => $password],
        );
    }
}
