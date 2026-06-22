<?php

namespace Database\Seeders;

use App\Models\ModuleAction;
use App\Models\Modules;
use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Role;

/**
 * Seeds the standard view/create/edit/delete actions for the 'users' module
 * and grants all of them to the 'manager' role, as a worked example of the
 * module_actions + module_permissions pattern (see those migrations and the
 * HasModuleAccess trait on User).
 *
 * Run standalone with:
 *   php artisan db:seed --class=Database\\Seeders\\UsersModuleActionsSeeder
 * or add a call to it from DatabaseSeeder::run().
 *
 * Idempotent: safe to re-run — uses updateOrCreate keyed on the unique
 * (module_id, key) / (role_id, module_action_id) pairs, so re-running this
 * won't create duplicate actions or duplicate grants.
 */
class UsersModuleActionsSeeder extends Seeder
{
    public function run(): void
    {
        $module = Modules::where('slug', 'users')->first();

        if (! $module) {
            $this->command?->warn("No 'users' module found (slug='users') — skipping. Create the Modules row first.");
            return;
        }

        $actions = [
            ['key' => 'view',   'label' => 'View Users',   'sort_order' => 1],
            ['key' => 'create', 'label' => 'Create Users', 'sort_order' => 2],
            ['key' => 'edit',   'label' => 'Edit Users',   'sort_order' => 3],
            ['key' => 'delete', 'label' => 'Delete Users', 'sort_order' => 4],
        ];

        $createdActions = collect($actions)->map(
            fn (array $attrs) => ModuleAction::updateOrCreate(
                ['module_id' => $module->id, 'key' => $attrs['key']],
                ['label' => $attrs['label'], 'sort_order' => $attrs['sort_order']],
            )
        );

        // Example grant: 'manager' role gets full access to the Users
        // module. Adjust/remove this block, or add similar ones for
        // 'cashier' with a narrower subset (e.g. only 'view'), per your
        // actual access policy — this is a starting example, not a fixed
        // business rule.
        $managerRole = Role::where('name', 'manager')->first();

        if ($managerRole) {
            foreach ($createdActions as $action) {
                $action->roles()->syncWithoutDetaching([$managerRole->id]);
            }
            $this->command?->info("Granted manager role full access to 'users' module actions.");
        } else {
            $this->command?->warn("No 'manager' role found — skipped granting example permissions. Actions were still created.");
        }
    }
}