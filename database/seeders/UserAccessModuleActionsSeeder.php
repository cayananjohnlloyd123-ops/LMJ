<?php

namespace Database\Seeders;

use App\Models\ModuleAction;
use App\Models\Modules;
use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Role;

/**
 * Seeds the view/edit actions for the 'user-access' module (the Permissions
 * matrix page at resources/views/livewire/users/access.blade.php) and
 * grants both to the 'manager' role.
 *
 * Note: only 'view' and 'edit' are seeded here, not 'create'/'delete' —
 * the Permissions page doesn't have separate create/delete concepts, just
 * "can see the matrix" (view) and "can change it" (edit, which also gates
 * the Save button itself — see PermissionsPage::canEdit / save()).
 *
 * Run standalone with:
 *   php artisan db:seed --class=Database\Seeders\UserAccessModuleActionsSeeder
 * (single backslash on Windows CMD/PowerShell — see prior note on escaping)
 * or add a call to it from DatabaseSeeder::run().
 *
 * Idempotent: safe to re-run — uses updateOrCreate keyed on the unique
 * (module_id, key) / (role_id, module_action_id) pairs.
 */
class UserAccessModuleActionsSeeder extends Seeder
{
    public function run(): void
    {
        $module = Modules::where('slug', 'user-access')->first();

        if (! $module) {
            $this->command?->warn("No 'user-access' module found (slug='user-access') — skipping. Create the Modules row first.");
            return;
        }

        $actions = [
            ['key' => 'view', 'label' => 'View Permissions', 'sort_order' => 1],
            ['key' => 'edit', 'label' => 'Edit Permissions',  'sort_order' => 2],
        ];

        $createdActions = collect($actions)->map(
            fn (array $attrs) => ModuleAction::updateOrCreate(
                ['module_id' => $module->id, 'key' => $attrs['key']],
                ['label' => $attrs['label'], 'sort_order' => $attrs['sort_order']],
            )
        );

        $managerRole = Role::where('name', 'manager')->first();

        if ($managerRole) {
            foreach ($createdActions as $action) {
                $action->roles()->syncWithoutDetaching([$managerRole->id]);
            }
            $this->command?->info("Granted manager role view+edit access to 'user-access' module.");
        } else {
            $this->command?->warn("No 'manager' role found — skipped granting example permissions. Actions were still created.");
        }
    }
}