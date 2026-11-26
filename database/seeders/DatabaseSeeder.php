<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    public function run(): void
    {
        // Roles and permissions are application data and are safe to provision
        // in every environment. Demo users/catalog data must never be seeded
        // into shared staging or production databases.
        $this->call(RolePermissionSeeder::class);

        if (! app()->environment(['local', 'testing'])) {
            $this->command?->warn(
                'Demo storefront data was not seeded: APP_ENV must be local or testing.'
            );

            return;
        }

        $this->call([
            StorefrontDemoSeeder::class,
            StorefrontScenarioSeeder::class,
        ]);

        User::firstOrCreate(
            ['email' => 'test@example.com'],
            ['name' => 'Test User', 'password' => 'password'],
        );
    }
}
