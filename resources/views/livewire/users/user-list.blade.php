<?php

use Livewire\Volt\Component;
use Livewire\Attributes\On;
use App\Models\User;
use Spatie\Permission\Models\Role;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use App\Support\Authorization\EnforcesModuleAccess;
use App\Support\Authorization\RequiresAccess;

new class extends Component {
    use EnforcesModuleAccess;

    public ?int $editingId = null;

    // Module slug this page corresponds to, in the modules table — used by
    // EnforcesModuleAccess's guard()/can() helpers (and the #[RequiresAccess]
    // attributes below) as the default module for permission checks.
    protected string $moduleSlug = 'users';

    // Field definitions consumed by <livewire:modal-form />.
    // Built in mount() because the 'options' for the role select are dynamic
    // (pulled from the roles table) and the password rule changes between
    // create (required) and edit (optional) — see fieldsFor().
    //
    // IMPORTANT: every value here must be wire-serializable (strings, arrays,
    // scalars). Do NOT put Illuminate\Validation\Rule objects (e.g.
    // Rule::unique(), Rule::exists()) into this array — Livewire can't
    // serialize them as part of a public property and will fail with
    // "Property type not supported in Livewire for property: [{}]".
    // Dynamic rules like uniqueness-with-ignore are described declaratively
    // here (see the 'unique' key on the email field) and resolved into real
    // Rule objects inside ModalForm::save(), which never stores them as a
    // public property — only uses them locally during validate().
    public array $fields = [];

    public function mount(): void
    {
        $this->fields = $this->fieldsFor();
    }

    protected function fieldsFor(?int $userId = null): array
    {
        $roles = Role::orderBy('name')->pluck('name')->map(fn ($name) => [
            'value' => $name,
            'label' => ucfirst($name),
        ])->all();

        return [
            [
                'key'         => 'name',
                'label'       => 'Full Name',
                'type'        => 'text',
                'placeholder' => 'Juan Dela Cruz',
                'required'    => true,
                'span'        => 'full',
                'rules'       => ['required', 'string', 'max:255'],
            ],
            [
                'key'         => 'email',
                'label'       => 'Email',
                'type'        => 'email',
                'placeholder' => 'juan@example.com',
                'required'    => true,
                'span'        => 'full',
                // Plain string rules only — no Rule::unique() object here.
                // The uniqueness check (with "ignore this user" on edit) is
                // described as plain data and resolved inside ModalForm at
                // validate() time. See ModalForm::save().
                'rules'       => ['required', 'email'],
                'unique'      => [
                    'table'  => 'users',
                    'column' => 'email',
                    'ignore' => $userId,
                ],
            ],
            [
                'key'         => 'password',
                'label'       => $userId ? 'New Password' : 'Password',
                'type'        => 'text',
                'placeholder' => $userId ? 'Leave blank to keep current password' : 'Minimum 8 characters',
                'required'    => ! $userId,
                'span'        => 'full',
                'rules'       => $userId
                    ? ['nullable', 'string', 'min:8']
                    : ['required', 'string', 'min:8'],
            ],
            [
                'key'      => 'role',
                'label'    => 'Role',
                'type'     => 'select',
                'options'  => $roles,
                'required' => true,
                'span'     => 'full',
                // String form of the exists rule — no Rule::exists() object
                // needed since there's nothing dynamic (no ignore()) here.
                'rules'    => ['required', 'exists:roles,name'],
            ],
        ];
    }

    // --- Table config consumed by <livewire:data-tables /> ---

    public function columns(): array
    {
        return [
            ['key' => 'name', 'label' => 'Name', 'sortable' => true],
            ['key' => 'email', 'label' => 'Email', 'sortable' => true],
            ['key' => 'role_label', 'label' => 'Role', 'format' => 'badge:role_color=role_label'],
            ['key' => 'created_at', 'label' => 'Joined', 'format' => 'date', 'sortable' => true],
        ];
    }

    // Listens for the table's "edit-user" row action.
    #[On('edit-user')]
    #[RequiresAccess('edit')]
    public function editUser(int $id): void
    {
        $this->guard(__FUNCTION__);

        $user = User::findOrFail($id);

        $this->editingId = $id;
        $this->fields    = $this->fieldsFor($id);

        // fields + title are passed explicitly in the payload (not just
        // relied on via the :fields="$fields" prop binding) because
        // Livewire only reads a child component's props at its initial
        // mount — re-rendering this parent with a new $this->fields does
        // NOT push through to the already-mounted <livewire:modal-form>
        // child. See ModalForm::openModal() for how these are applied.
        $this->dispatch('modal-open', data: [
            'name'  => $user->name,
            'email' => $user->email,
            'role'  => $user->getRoleNames()->first(),
            // password intentionally omitted — left blank means "keep current"
        ], fields: $this->fields, title: 'Edit User')->to('modal-form');
    }

    // Listens for the table's "delete-user" row action.
    #[On('delete-user')]
    #[RequiresAccess('delete')]
    public function deleteUser(int $id): void
    {
        $this->guard(__FUNCTION__);

        if ($id === auth()->id()) {
            $this->dispatch('toast', type: 'error', message: "You can't delete your own account.");
            return;
        }

        User::findOrFail($id)->delete();
        $this->dispatch('table-refresh')->to('data-tables');
        $this->dispatch('toast', type: 'success', message: 'User deleted.');
    }

    // Listens for modal-form's "New" button click, which resets edit state
    // so a fresh submit creates instead of updates. Without this, clicking
    // New right after an Edit session would silently update that user.
    #[On('modal-reset')]
    public function resetEditing(): void
    {
        $this->editingId = null;
        $this->fields     = $this->fieldsFor();
    }

    // Listens for: $this->dispatch('save-user', data: [...]) from modal-form.
    // Not using #[RequiresAccess] here since the required action is only
    // known at runtime (create vs. edit, based on $editingId) — the
    // attribute pattern fits methods with one fixed action; this one uses
    // the can() helper directly instead, for the same reason.
    #[On('save-user')]
    public function saveUser(array $data): void
    {
        $isEditing = (bool) $this->editingId;

        abort_unless($this->can($isEditing ? 'edit' : 'create'), 403);

        try {
            if ($this->editingId) {
                $user = User::findOrFail($this->editingId);

                // Guard: don't let an admin strip their own admin role and
                // lock themselves out of this very page.
                if ($user->id === auth()->id() && $data['role'] !== $user->getRoleNames()->first()) {
                    throw ValidationException::withMessages([
                        'form' => "You can't change your own role.",
                    ]);
                }

                $user->fill([
                    'name'  => $data['name'],
                    'email' => $data['email'],
                ]);

                if (! empty($data['password'])) {
                    $user->password = Hash::make($data['password']);
                }

                $user->save();
            } else {
                $user = User::create([
                    'name'              => $data['name'],
                    'email'             => $data['email'],
                    'password'          => Hash::make($data['password']),
                    'email_verified_at' => now(),
                ]);
            }

            $user->syncRoles([$data['role']]);

            $this->editingId = null;
            $this->fields     = $this->fieldsFor();

            $this->dispatch('modal-saved')->to('modal-form');
            $this->dispatch('table-refresh')->to('data-tables');
            $this->dispatch('toast', type: 'success', message: $this->editingId ? 'User updated.' : 'User created.');
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
                'event' => 'edit-user',
                'icon'  => '<svg class="h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z" /></svg>',
            ];
        }

        if ($this->can('delete')) {
            $actions[] = [
                'label'  => 'Delete',
                'event'  => 'delete-user',
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

    {{-- Page header — "Add User" lives here, not inside the table's toolbar
         slot, since it's a page-level action rather than a table-level one.
         (Previously it sat in the data-tables slot alongside the search box;
         on narrower layouts the toolbar's flex-col stacking pushed it below
         the table entirely instead of next to the title.) --}}
    <div class="flex items-center justify-between">
        <div>
            <h1 class="text-xl font-semibold text-gray-900">Users</h1>
            <p class="mt-1 text-sm text-gray-500">Manage staff accounts and their access roles.</p>
        </div>

        @if ($this->can('create'))
            <livewire:modal-form
                wire:key="user-modal"
                title="Add User"
                :fields="$fields"
                save-event="save-user"
                button-label="Add User"
            />
        @endif
    </div>

    <livewire:data-tables
        wire:key="users-table"
        model="App\Models\User"
        :columns="$this->columns()"
        :searchable="['name', 'email']"
        :row-actions="$this->rowActions()"
        :per-page="10"
    />

</div>