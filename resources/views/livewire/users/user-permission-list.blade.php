<?php

use Livewire\Volt\Component;
use App\Models\Modules;
use App\Models\ModuleAction;
use Spatie\Permission\Models\Role;
use Illuminate\Support\Facades\DB;

new class extends Component {
    // This page's own module slug — see HasModuleAccess on User. The 'edit'
    // action gates all mutation here (toggling cells, saving); viewing the
    // page at all is already gated by the 'module.access' route middleware
    // checking 'view' on this same slug.
    protected string $moduleSlug = 'user-access';

    // --- Matrix data, loaded once at mount() ---

    // Modules with their actions eager-loaded, ordered for display.
    // Shape consumed by the Blade markup: collection of Modules, each with
    // ->actions (collection of ModuleAction).
    public array $modulesWithActions = [];

    // Roles shown as matrix columns — every role EXCEPT 'admin', since admin
    // always bypasses permission checks (see HasModuleAccess::canAccess())
    // and would be confusingly "always checked" / meaningless to toggle here.
    public array $roles = [];

    // The matrix state itself: [module_action_id => [role_id => bool]].
    // Initialized from current module_permissions rows at mount(), then
    // mutated locally as the user clicks checkboxes — nothing is persisted
    // until they hit Save (see save() below), per the batched-save decision.
    public array $grants = [];

    // True once the user has changed at least one checkbox since the last
    // save/load — drives the Save button's enabled state and an unsaved-
    // changes indicator, so it's clear when there's anything to submit.
    public bool $isDirty = false;

    // Whether the current user may make changes here at all — checked
    // server-side in toggle()/toggleModuleRole()/save() (not just used to
    // hide the Save button), since those Livewire methods are directly
    // reachable regardless of what's rendered in the DOM.
    public bool $canEdit = false;

    public function mount(): void
    {
        $this->canEdit = auth()->user()->canAccess($this->moduleSlug, 'edit');

        // whereRaw + LOWER(): plain where('name', '!=', 'admin') is exact-
        // match and case-sensitive, so a role actually named 'Admin'
        // (capital A) would slip through and still show as a matrix
        // column — same root cause as the hasRole('admin') bug fixed in
        // HasModuleAccess::isAdmin(). Comparing lowercased avoids it here
        // too, without requiring the role to be renamed in the DB.
        $this->roles = Role::whereRaw('LOWER(name) != ?', ['admin'])
            ->orderBy('name')
            ->get(['id', 'name'])
            ->map(fn ($role) => ['id' => $role->id, 'name' => ucfirst($role->name)])
            ->all();

        $modules = Modules::with(['actions' => fn ($q) => $q->orderBy('sort_order')])
            ->orderBy('sort_order')
            ->get()
            ->filter(fn ($module) => $module->actions->isNotEmpty())
            ->values();

        $this->modulesWithActions = $modules->map(fn ($module) => [
            'id'      => $module->id,
            'name'    => $module->name,
            'actions' => $module->actions->map(fn ($action) => [
                'id'    => $action->id,
                'label' => $action->label,
            ])->all(),
        ])->all();

        // Pull every existing grant in one query rather than N+1-ing per
        // action — module_permissions rows directly tell us which
        // (module_action_id, role_id) pairs are currently granted.
        $existingGrants = DB::table('module_permissions')
            ->select('module_action_id', 'role_id')
            ->get();

        $roleIds = collect($this->roles)->pluck('id')->all();

        foreach ($this->modulesWithActions as $module) {
            foreach ($module['actions'] as $action) {
                $this->grants[$action['id']] = [];
                foreach ($roleIds as $roleId) {
                    $this->grants[$action['id']][$roleId] = $existingGrants
                        ->contains(fn ($g) => $g->module_action_id === $action['id'] && $g->role_id === $roleId);
                }
            }
        }
    }

    /**
     * Toggles a single cell in local state only. Nothing is persisted here
     * — see save() for the batched write, per the chosen "Save button"
     * flow rather than auto-save-per-click.
     */
    public function toggle(int $actionId, int $roleId): void
    {
        abort_unless($this->canEdit, 403);

        $this->grants[$actionId][$roleId] = ! ($this->grants[$actionId][$roleId] ?? false);
        $this->isDirty = true;
    }

    /**
     * Grants every action under a module to a role in one click (a "select
     * all" convenience per module+role pair) — still local-only until Save.
     */
    public function toggleModuleRole(int $moduleId, int $roleId): void
    {
        abort_unless($this->canEdit, 403);

        $module = collect($this->modulesWithActions)->firstWhere('id', $moduleId);

        if (! $module) {
            return;
        }

        $actionIds = collect($module['actions'])->pluck('id');

        // If every action in this module is already granted to this role,
        // toggling clears all of them; otherwise it grants all of them.
        // Mirrors the common "select all" checkbox UX.
        $allGranted = $actionIds->every(fn ($id) => $this->grants[$id][$roleId] ?? false);

        foreach ($actionIds as $actionId) {
            $this->grants[$actionId][$roleId] = ! $allGranted;
        }

        $this->isDirty = true;
    }

    /**
     * Writes the entire local $grants state to module_permissions in one
     * transaction: delete-then-reinsert is simplest and safe here since
     * the whole matrix is always submitted as a unit (no partial-row
     * concurrent-edit concern given this is an admin-only batch action).
     */
    public function save(): void
    {
        abort_unless($this->canEdit, 403);

        DB::transaction(function () {
            $rows = [];

            foreach ($this->grants as $actionId => $roleGrants) {
                foreach ($roleGrants as $roleId => $granted) {
                    if ($granted) {
                        $rows[] = [
                            'module_action_id' => $actionId,
                            'role_id'          => $roleId,
                            'created_at'       => now(),
                            'updated_at'       => now(),
                        ];
                    }
                }
            }

            DB::table('module_permissions')->delete();

            // chunk(): a large module/role matrix could exceed a single
            // INSERT's practical row limit — insert in batches of 500 to
            // stay safe regardless of matrix size.
            foreach (array_chunk($rows, 500) as $chunk) {
                DB::table('module_permissions')->insert($chunk);
            }
        });

        $this->isDirty = false;

        $this->dispatch('toast', type: 'success', message: 'Permissions updated.');
    }

    public function with(): array
    {
        return [];
    }
}; ?>

<div class="space-y-6">

    {{-- Page header --}}
    <div class="flex items-center justify-between">
        <div>
            <h1 class="text-xl font-semibold text-gray-900">Permissions</h1>
            <p class="mt-1 text-sm text-gray-500">
                Control which roles can access each module action.
                <span class="text-gray-400">("Admin" always has full access and isn't shown here.)</span>
            </p>
        </div>

        @if ($canEdit)
            <button
                type="button"
                wire:click="save"
                wire:loading.attr="disabled"
                wire:target="save"
                @disabled(! $isDirty)
                class="inline-flex items-center gap-2 rounded-lg bg-indigo-600 px-4 py-2 text-sm font-medium text-white hover:bg-indigo-700 disabled:opacity-40 disabled:cursor-not-allowed transition-opacity"
            >
                <svg wire:loading wire:target="save" class="w-4 h-4 animate-spin" fill="none" viewBox="0 0 24 24">
                    <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"/>
                    <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8v8H4z"/>
                </svg>
                <span wire:loading.remove wire:target="save">
                    {{ $isDirty ? 'Save Changes' : 'Saved' }}
                </span>
                <span wire:loading wire:target="save">Saving...</span>
            </button>
        @endif
    </div>

    @if (empty($roles))
        <div class="rounded-lg border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-700">
            No roles besides "admin" exist yet. Create a role on the Roles page first.
        </div>
    @elseif (empty($modulesWithActions))
        <div class="rounded-lg border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-700">
            No module actions are defined yet. Seed module_actions for at least one module first.
        </div>
    @else
        <div class="overflow-x-auto rounded-lg border border-gray-200 bg-white">
            <table class="min-w-full divide-y divide-gray-200">
                <thead class="bg-gray-50">
                    <tr>
                        <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                            Module / Action
                        </th>
                        @foreach ($roles as $role)
                            <th class="px-4 py-3 text-center text-xs font-medium text-gray-500 uppercase tracking-wider">
                                {{ $role['name'] }}
                            </th>
                        @endforeach
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-200">
                    @foreach ($modulesWithActions as $module)
                        {{-- Module group header row — also doubles as a
                             per-role "select all actions in this module"
                             toggle, since clicking it calls
                             toggleModuleRole(). --}}
                        <tr wire:key="module-{{ $module['id'] }}" class="bg-gray-50/70">
                            <td class="px-4 py-2 text-sm font-semibold text-gray-700">
                                {{ $module['name'] }}
                            </td>
                            @foreach ($roles as $role)
                                @php
                                    $actionIds = array_column($module['actions'], 'id');
                                    $allGranted = count($actionIds) > 0 && collect($actionIds)
                                        ->every(fn ($id) => $grants[$id][$role['id']] ?? false);
                                @endphp
                                <td class="px-4 py-2 text-center">
                                    <button
                                        type="button"
                                        wire:click="toggleModuleRole({{ $module['id'] }}, {{ $role['id'] }})"
                                        @disabled(! $canEdit)
                                        class="text-xs font-medium {{ $allGranted ? 'text-indigo-600' : 'text-gray-400' }} hover:text-indigo-700 disabled:cursor-not-allowed disabled:hover:text-gray-400"
                                        title="Toggle all {{ $module['name'] }} actions for {{ $role['name'] }}"
                                    >
                                        {{ $allGranted ? 'All' : 'None' }}
                                    </button>
                                </td>
                            @endforeach
                        </tr>

                        @foreach ($module['actions'] as $action)
                            <tr wire:key="action-{{ $action['id'] }}" class="hover:bg-gray-50">
                                <td class="px-4 py-3 pl-8 text-sm text-gray-600">
                                    {{ $action['label'] }}
                                </td>
                                @foreach ($roles as $role)
                                    <td class="px-4 py-3 text-center">
                                        <input
                                            type="checkbox"
                                            wire:click="toggle({{ $action['id'] }}, {{ $role['id'] }})"
                                            @checked($grants[$action['id']][$role['id']] ?? false)
                                            @disabled(! $canEdit)
                                            class="rounded border-gray-300 text-indigo-600 focus:ring-indigo-500 cursor-pointer disabled:cursor-not-allowed disabled:opacity-60"
                                        >
                                    </td>
                                @endforeach
                            </tr>
                        @endforeach
                    @endforeach
                </tbody>
            </table>
        </div>

        @if ($isDirty)
            <p class="text-sm text-amber-600">You have unsaved changes.</p>
        @endif
    @endif

</div>