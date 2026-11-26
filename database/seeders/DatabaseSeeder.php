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

        // Bootstrap a privileged account only when explicit deployment secrets
        // are supplied. Never create a default or hard-coded super-admin login.
        $superAdminEmail = env('SUPER_ADMIN_EMAIL');
        $superAdminPassword = env('SUPER_ADMIN_PASSWORD');
        if ($superAdminEmail && $superAdminPassword) {
            $superAdmin = User::firstOrCreate(
                ['email' => strtolower(trim($superAdminEmail))],
                [
                    'name' => env('SUPER_ADMIN_NAME', 'Marketplace Super Admin'),
                    'password' => $superAdminPassword,
                    'is_active' => true,
                ],
            );
            $superAdmin->syncRoles(['Super Admin']);
        }

        if (! app()->environment(['local', 'testing'])) {
            $this->command?->warn(
                'Demo storefront data was not seeded: APP_ENV must be local or testing.'
            );

            return;
        }

        $this->call([
            StorefrontDemoSeeder::class,
            StorefrontScenarioSeeder::class,
            MarketplaceLifecycleDemoSeeder::class,
        ]);

        User::firstOrCreate(
            ['email' => 'test@example.com'],
            ['name' => 'Test User', 'password' => 'password'],
        );
    }
}
