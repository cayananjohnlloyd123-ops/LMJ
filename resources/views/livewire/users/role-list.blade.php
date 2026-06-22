<?php

use Livewire\Volt\Component;
use Livewire\Attributes\On;
use App\Models\Role;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use App\Support\Authorization\EnforcesModuleAccess;
use App\Support\Authorization\RequiresAccess;

new class extends Component {
    use EnforcesModuleAccess;

    public ?int $editingId = null;

    // Module slug for this page — see EnforcesModuleAccess. IMPORTANT: this
    // assumes a 'roles' row exists in the modules table with matching
    // module_actions (create/edit/delete) seeded — same setup as 'users'.
    // Without that row + seeded actions, canAccess() returns false for
    // every non-admin role and only admin will be able to use this page.
    protected string $moduleSlug = 'roles';

    // Field definitions consumed by <livewire:modal-form />.
    // Built in mount() / fieldsFor() so the uniqueness rule's "ignore" target
    // can differ between create (no id to ignore) and edit (ignore the role
    // being edited). Like in users.blade.php, only plain strings/arrays go
    // in here — never Illuminate\Validation\Rule objects — since this array
    // is a public Livewire property and Rule objects aren't wire-serializable
    // (causes "Property type not supported in Livewire for property: [{}]").
    public array $fields = [];

    public function mount(): void
    {
        $this->fields = $this->fieldsFor();
    }

    protected function fieldsFor(?int $roleId = null): array
    {
        return [
            [
                'key'         => 'name',
                'label'       => 'Role Name',
                'type'        => 'text',
                'placeholder' => 'e.g. manager',
                'required'    => true,
                'span'        => 'full',
                'rules'       => ['required', 'string', 'max:255'],
                // Declarative uniqueness — resolved into a real Rule::unique()
                // locally inside ModalForm::save(), never stored as a property.
                'unique'      => [
                    'table'  => 'roles',
                    'column' => 'name',
                    'ignore' => $roleId,
                ],
            ],
        ];
    }

    // --- Table config consumed by <livewire:data-tables /> ---

    public function columns(): array
    {
        return [
            ['key' => 'name', 'label' => 'Role', 'sortable' => true],
            ['key' => 'users_count', 'label' => 'Users', 'format' => 'integer'],
            ['key' => 'created_at', 'label' => 'Created', 'format' => 'date', 'sortable' => true],
        ];
    }

    // Listens for the table's "edit-role" row action.
    #[On('edit-role')]
    #[RequiresAccess('edit')]
    public function editRole(int $id): void
    {
        $this->guard(__FUNCTION__);

        $role = Role::findOrFail($id);

        $this->editingId = $id;
        $this->fields    = $this->fieldsFor($id);

        // See users.blade.php's editUser() for why fields/title are passed
        // explicitly here rather than relied on via the :fields prop binding.
        $this->dispatch('modal-open', data: [
            'name' => $role->name,
        ], fields: $this->fields, title: 'Edit Role')->to('modal-form');
    }

    // Listens for the table's "delete-role" row action.
    #[On('delete-role')]
    #[RequiresAccess('delete')]
    public function deleteRole(int $id): void
    {
        $this->guard(__FUNCTION__);

        $role = Role::withCount('users')->findOrFail($id);

        if ($role->users_count > 0) {
            $this->dispatch('toast', type: 'error', message: "Can't delete \"{$role->name}\" — {$role->users_count} user(s) are still assigned to it.");
            return;
        }

        $role->delete();
        $this->dispatch('table-refresh')->to('data-tables');
        $this->dispatch('toast', type: 'success', message: 'Role deleted.');
    }

    // Listens for modal-form's "New" button click, which resets edit state
    // so a fresh submit creates instead of updates. Without this, clicking
    // New right after an Edit session would silently update that role.
    #[On('modal-reset')]
    public function resetEditing(): void
    {
        $this->editingId = null;
        $this->fields     = $this->fieldsFor();
    }

    // Listens for: $this->dispatch('save-role', data: [...]) from modal-form.
    // Not using #[RequiresAccess] here for the same reason as saveUser() in
    // users.blade.php — the required action depends on $editingId at
    // runtime (create vs. edit), so can() is called directly instead.
    #[On('save-role')]
    public function saveRole(array $data): void
    {
        $isEditing = (bool) $this->editingId;

        abort_unless($this->can($isEditing ? 'edit' : 'create'), 403);

        try {
            if ($this->editingId) {
                $role = Role::findOrFail($this->editingId);
                $role->update(['name' => $data['name']]);
            } else {
                Role::create([
                    'name'       => $data['name'],
                    'guard_name' => 'web',
                ]);
            }

            $this->editingId = null;
            $this->fields     = $this->fieldsFor();

            $this->dispatch('modal-saved')->to('modal-form');
            $this->dispatch('table-refresh')->to('data-tables');
            $this->dispatch('toast', type: 'success', message: $this->editingId ? 'Role updated.' : 'Role created.');
        } catch (ValidationException $e) {
            $this->dispatch('modal-error', message: $e->getMessage() ?: collect($e->errors())->flatten()->first())->to('modal-form');
        } catch (\Throwable $e) {
            report($e);
            $this->dispatch('modal-error', message: 'Something went wrong. Please try again.')->to('modal-form');
        }
    }

    public function rowActions(): array
    {
        $actions = [];

        if ($this->can('edit')) {
            $actions[] = [
                'label' => 'Edit',
                'event' => 'edit-role',
                'icon'  => '<svg class="h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z" /></svg>',
            ];
        }

        if ($this->can('delete')) {
            $actions[] = [
                'label'  => 'Delete',
                'event'  => 'delete-role',
                'danger' => true,
                'icon'   => '<svg class="h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16" /></svg>',
            ];
        }

        return $actions;
    }

    public function with(): array
    {
        return [];
    }
}; ?>

<div class="space-y-6">

    {{-- Page header — "Add Role" sits here next to the title, not inside
         the table's toolbar slot, so it never gets pushed below the table
         on narrower layouts. --}}
    <div class="flex items-center justify-between">
        <div>
            <h1 class="text-xl font-semibold text-gray-900">Roles</h1>
            <p class="mt-1 text-sm text-gray-500">Manage access roles assignable to users.</p>
        </div>

        @if ($this->can('create'))
            <livewire:modal-form
                wire:key="role-modal"
                title="Add Role"
                :fields="$fields"
                save-event="save-role"
                button-label="Add Role"
            />
        @endif
    </div>

    <livewire:data-tables
        wire:key="roles-table"
        model="App\Models\Role"
        :columns="$this->columns()"
        :searchable="['name']"
        :row-actions="$this->rowActions()"
        :per-page="10"
    />

</div>