<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Artisan;

class DatabaseSeeder extends Seeder
{
    /**
     * Seed the workbench: an admin to sign in with, then the same sample data `php artisan odden:demo` loads into any install.
     */
    public function run(): void
    {
        User::firstOrCreate(
            ['email' => 'admin@odden.test'],
            ['name' => 'Odden Admin', 'password' => bcrypt('password')]
        );

        Artisan::call('odden:demo', ['--force' => true]);
    }
}
