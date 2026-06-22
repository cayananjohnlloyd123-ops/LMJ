<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\Modules;

class ModuleSeeder extends Seeder
{
    public function run(): void
    {
        Modules::truncate();

        /*
        |--------------------------------------------------------------------------
        | Dashboard
        |--------------------------------------------------------------------------
        */
        $dashboard = Modules::create([
            'parent_id'  => null,
            'name'       => 'Dashboard',
            'slug'       => 'dashboard',
            'icon'       => 'home',
            'route'      => 'dashboard',
            'sort_order' => 1,
            'is_active'  => 1,
        ]);

        /*
        |--------------------------------------------------------------------------
        | Sales
        |--------------------------------------------------------------------------
        */
        $sales = Modules::create([
            'parent_id'  => null,
            'name'       => 'Sales',
            'slug'       => 'sales',
            'icon'       => 'shopping-cart',
            'route'      => null,
            'sort_order' => 2,
            'is_active'  => 1,
        ]);

        Modules::create([
            'parent_id'  => $sales->id,
            'name'       => 'POS',
            'slug'       => 'sales-pos',
            'icon'       => null,
            'route'      => 'sales.pos',
            'sort_order' => 1,
            'is_active'  => 1,
        ]);

        Modules::create([
            'parent_id'  => $sales->id,
            'name'       => 'Sales History',
            'slug'       => 'sales-history',
            'icon'       => null,
            'route'      => 'sales.index',
            'sort_order' => 2,
            'is_active'  => 1,
        ]);

        /*
        |--------------------------------------------------------------------------
        | Expenses
        |--------------------------------------------------------------------------
        */
        $expenses = Modules::create([
            'parent_id'  => null,
            'name'       => 'Expenses',
            'slug'       => 'expenses',
            'icon'       => 'wallet',
            'route'      => null,
            'sort_order' => 3,
            'is_active'  => 1,
        ]);

        Modules::create([
            'parent_id'  => $expenses->id,
            'name'       => 'Expense Categories',
            'slug'       => 'expense-categories',
            'icon'       => null,
            'route'      => 'expenses.categories',
            'sort_order' => 1,
            'is_active'  => 1,
        ]);

        Modules::create([
            'parent_id'  => $expenses->id,
            'name'       => 'Expense Entries',
            'slug'       => 'expense-entries',
            'icon'       => null,
            'route'      => 'expenses.index',
            'sort_order' => 2,
            'is_active'  => 1,
        ]);

        /*
        |--------------------------------------------------------------------------
        | Inventory
        |--------------------------------------------------------------------------
        */
        $inventory = Modules::create([
            'parent_id'  => null,
            'name'       => 'Inventory',
            'slug'       => 'inventory',
            'icon'       => 'archive-box',
            'route'      => null,
            'sort_order' => 4,
            'is_active'  => 1,
        ]);

        Modules::create([
            'parent_id'  => $inventory->id,
            'name'       => 'Products',
            'slug'       => 'products',
            'icon'       => null,
            'route'      => 'products.index',
            'sort_order' => 1,
            'is_active'  => 1,
        ]);

        /*
        |--------------------------------------------------------------------------
        | Reports
        |--------------------------------------------------------------------------
        */
        $reports = Modules::create([
            'parent_id'  => null,
            'name'       => 'Reports',
            'slug'       => 'reports',
            'icon'       => 'chart-bar',
            'route'      => null,
            'sort_order' => 5,
            'is_active'  => 1,
        ]);

        Modules::create([
            'parent_id'  => $reports->id,
            'name'       => 'Sales Report',
            'slug'       => 'sales-report',
            'icon'       => null,
            'route'      => 'reports.sales',
            'sort_order' => 1,
            'is_active'  => 1,
        ]);

        Modules::create([
            'parent_id'  => $reports->id,
            'name'       => 'Expense Report',
            'slug'       => 'expense-report',
            'icon'       => null,
            'route'      => 'reports.expenses',
            'sort_order' => 2,
            'is_active'  => 1,
        ]);

        Modules::create([
            'parent_id'  => $reports->id,
            'name'       => 'Profit Report',
            'slug'       => 'profit-report',
            'icon'       => null,
            'route'      => 'reports.profit',
            'sort_order' => 3,
            'is_active'  => 1,
        ]);

        /*
        |--------------------------------------------------------------------------
        | Administration
        |--------------------------------------------------------------------------
        */
        $administration = Modules::create([
            'parent_id'  => null,
            'name'       => 'Administration',
            'slug'       => 'administration',
            'icon'       => 'cog-6-tooth',
            'route'      => null,
            'sort_order' => 99,
            'is_active'  => 1,
        ]);

        Modules::create([
            'parent_id'  => $administration->id,
            'name'       => 'Users',
            'slug'       => 'users',
            'icon'       => null,
            'route'      => 'users.index',
            'sort_order' => 1,
            'is_active'  => 1,
        ]);

        Modules::create([
            'parent_id'  => $administration->id,
            'name'       => 'Roles',
            'slug'       => 'roles',
            'icon'       => null,
            'route'      => 'roles.index',
            'sort_order' => 2,
            'is_active'  => 1,
        ]);

        Modules::create([
            'parent_id'  => $administration->id,
            'name'       => 'Modules',
            'slug'       => 'modules',
            'icon'       => null,
            'route'      => 'modules.index',
            'sort_order' => 3,
            'is_active'  => 1,
        ]);
    }
}