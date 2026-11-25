<?php

namespace Database\Seeders;

use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

use Spatie\Permission\Models\Role;
use Spatie\Permission\Models\Permission;

class RolePermissionSeeder extends Seeder
{
    public function run(): void
    {
        app()[\Spatie\Permission\PermissionRegistrar::class]
            ->forgetCachedPermissions();

        $permissions = [
            'users.view',
            'users.create',
            'users.update',
            'users.delete',

            'products.view',
            'products.create',
            'products.update',
            'products.delete',

            'orders.view',
            'orders.update',
            'products.approve',
            'stores.manage',
            'brands.manage',
            'categories.manage',
            'inventory.update',

            'roles.view',
            'roles.create',
            'roles.update',
            'roles.delete',
            'permissions.view',
            'permissions.create',
            'permissions.update',
            'permissions.delete',
        ];

        foreach ($permissions as $permission) {
            Permission::firstOrCreate([
                'name' => $permission
            ]);
        }

        $admin = Role::firstOrCreate([
            'name' => 'Admin'
        ]);

        $vendor = Role::firstOrCreate([
            'name' => 'Vendor'
        ]);

        $customer = Role::firstOrCreate([
            'name' => 'Customer'
        ]);

        $admin->syncPermissions(Permission::all());

        $vendor->syncPermissions([
            'products.view',
            'products.create',
            'products.update',
            'products.delete',
            'stores.manage',
            'inventory.update',
            'orders.view',
        ]);

        $customer->syncPermissions([]);
    }
}
