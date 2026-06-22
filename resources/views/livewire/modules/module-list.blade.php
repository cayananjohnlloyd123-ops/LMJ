<?php

use App\Models\Modules;
use App\Models\ModuleAction;
use Illuminate\Support\Str;
use Livewire\Volt\Component;
use Livewire\WithPagination;

new class extends Component
{
    use WithPagination;

    public string $search = '';

    public bool $showModal = false;
    public bool $confirmingDelete = false;

    public ?int $editingId = null;
    public ?int $deletingId = null;

    public ?int $parent_id = null;
    public string $name = '';
    public string $slug = '';
    public ?string $icon = null;
    public ?string $route = null;
    public int $sort_order = 0;
    public bool $is_active = true;

    // --- Module Actions tab (only relevant once editing an existing
    // module — a brand-new module has no id to attach actions to yet) ---

    // Which tab is active inside the modal: 'details' or 'actions'.
    // Reset to 'details' whenever the modal (re)opens — see openCreateModal()
    // / openEditModal() / closeModal().
    public string $activeTab = 'details';

    // Actions belonging to $editingId, loaded fresh each time the Actions
    // tab's data is needed (openEditModal(), and after add/delete below).
    // Plain array (not a relation/collection prop) since it only needs to
    // feed the simple list UI — no need for full ModuleAction model state
    // to round-trip through Livewire.
    public array $moduleActions = [];

    // New-action mini-form fields, cleared after each successful add.
    public string $newActionKey = '';
    public string $newActionLabel = '';

    public function updatedName(string $value): void
    {
        if (! $this->editingId) {
            $this->slug = Str::slug($value);
        }
    }

    public function updatedSearch(): void
    {
        $this->resetPage();
    }

    public function openCreateModal(): void
    {
        $this->resetForm();
        $this->activeTab = 'details';
        $this->showModal = true;
    }

    public function openEditModal(int $id): void
    {
        $module = Modules::findOrFail($id);

        $this->editingId  = $module->id;
        $this->parent_id  = $module->parent_id;
        $this->name       = $module->name;
        $this->slug       = $module->slug;
        $this->icon       = $module->icon;
        $this->route      = $module->route;
        $this->sort_order = $module->sort_order;
        $this->is_active  = $module->is_active;

        $this->resetErrorBag();
        $this->activeTab = 'details';
        $this->loadModuleActions();
        $this->showModal = true;
    }

    /**
     * Switches the modal's internal tab. Guards against switching to
     * 'actions' on a not-yet-created module — see the @if in the Blade
     * markup for why that tab isn't even clickable in that state, this is
     * the server-side mirror of that same restriction.
     */
    public function setTab(string $tab): void
    {
        if ($tab === 'actions' && ! $this->editingId) {
            return;
        }

        $this->activeTab = $tab;
    }

    /**
     * Refreshes $moduleActions from the DB for the module currently being
     * edited. Called after opening the modal in edit mode, and after any
     * add/delete so the list reflects the latest state without a full
     * modal re-open.
     */
    protected function loadModuleActions(): void
    {
        if (! $this->editingId) {
            $this->moduleActions = [];
            return;
        }

        $this->moduleActions = ModuleAction::where('module_id', $this->editingId)
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get(['id', 'key', 'label', 'sort_order'])
            ->map(fn ($action) => $action->toArray())
            ->all();
    }

    /**
     * Adds one ModuleAction row under the module currently being edited.
     * Local-only concerns (key/label inputs) are cleared after a successful
     * add so the mini-form is ready for the next entry immediately.
     */
    public function addAction(): void
    {
        if (! $this->editingId) {
            return;
        }

        $validated = $this->validate([
            'newActionKey'   => [
                'required', 'string', 'max:255',
                'regex:/^[a-z0-9_\-]+$/',
                // Scoped-unique per module, mirroring the
                // module_actions(module_id, key) DB unique constraint —
                // the same key can exist under different modules.
                function ($attribute, $value, $fail) {
                    $exists = ModuleAction::where('module_id', $this->editingId)
                        ->where('key', $value)
                        ->exists();

                    if ($exists) {
                        $fail(__('An action with this key already exists for this module.'));
                    }
                },
            ],
            'newActionLabel' => ['required', 'string', 'max:255'],
        ], [
            'newActionKey.regex' => __('Key may only contain lowercase letters, numbers, hyphens, and underscores.'),
        ], [
            'newActionKey'   => 'key',
            'newActionLabel' => 'label',
        ]);

        $nextSortOrder = (ModuleAction::where('module_id', $this->editingId)->max('sort_order') ?? 0) + 1;

        ModuleAction::create([
            'module_id'  => $this->editingId,
            'key'        => $validated['newActionKey'],
            'label'      => $validated['newActionLabel'],
            'sort_order' => $nextSortOrder,
        ]);

        $this->reset(['newActionKey', 'newActionLabel']);
        $this->resetErrorBag(['newActionKey', 'newActionLabel']);
        $this->loadModuleActions();
    }

    /**
     * Deletes a single ModuleAction. Cascades at the DB level to any
     * module_permissions rows referencing it (see the migration's
     * cascadeOnDelete()), so removing an action also removes any role
     * grants for it — intentional, since a grant for a no-longer-existing
     * action would be meaningless.
     */
    public function deleteAction(int $actionId): void
    {
        if (! $this->editingId) {
            return;
        }

        ModuleAction::where('module_id', $this->editingId)
            ->where('id', $actionId)
            ->delete();

        $this->loadModuleActions();
    }

    public function save(): void
    {
        $validated = $this->validate([
            'parent_id'  => ['nullable', 'exists:modules,id'],
            'name'       => ['required', 'string', 'max:255'],
            'slug'       => ['required', 'string', 'max:255', 'unique:modules,slug,'.$this->editingId],
            'icon'       => ['nullable', 'string', 'max:255'],
            'route'      => ['nullable', 'string', 'max:255'],
            'sort_order' => ['required', 'integer', 'min:0'],
            'is_active'  => ['boolean'],
        ]);

        Modules::updateOrCreate(
            ['id' => $this->editingId],
            $validated
        );

        $this->resetForm();
        $this->showModal = false;
    }

    public function confirmDelete(int $id): void
    {
        $this->deletingId = $id;
        $this->confirmingDelete = true;
    }

    public function delete(): void
    {
        if ($this->deletingId) {
            Modules::find($this->deletingId)?->delete();
        }

        $this->deletingId = null;
        $this->confirmingDelete = false;
    }

    public function cancelDelete(): void
    {
        $this->deletingId = null;
        $this->confirmingDelete = false;
    }

    public function resetForm(): void
    {
        $this->reset([
            'editingId', 'parent_id', 'name', 'slug',
            'icon', 'route', 'sort_order', 'is_active',
            'moduleActions', 'newActionKey', 'newActionLabel',
        ]);
        $this->is_active = true;
        $this->sort_order = 0;
        $this->activeTab = 'details';
        $this->resetErrorBag();
    }

    public function closeModal(): void
    {
        $this->resetForm();
        $this->showModal = false;
    }

    public function with(): array
    {
        return [
            'modulesList' => Modules::query()
                ->when($this->search, function ($query) {
                    $query->where(function ($query) {
                        $query->where('name', 'like', '%'.$this->search.'%')
                            ->orWhere('slug', 'like', '%'.$this->search.'%')
                            ->orWhere('route', 'like', '%'.$this->search.'%');
                    });
                })
                ->orderBy('sort_order')
                ->paginate(10),
            'parentOptions' => Modules::query()
                ->whereNull('parent_id')
                ->orderBy('sort_order')
                ->get(),
        ];
    }
}; ?>

<div x-data>

    <div class="flex justify-between items-center mb-6 gap-4">
        <h3 class="text-lg font-semibold whitespace-nowrap">{{ __('All Modules') }}</h3>

        <div class="relative flex-1 max-w-xs">
            <input type="text" wire:model.live.debounce.400ms="search" placeholder="{{ __('Search name, slug, or route...') }}"
                class="block w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-400 focus:ring-indigo-400 text-sm pe-8">
            <svg wire:loading wire:target="search" class="animate-spin absolute right-2 top-1/2 -translate-y-1/2 h-4 w-4 text-gray-400" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24">
                <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z"></path>
            </svg>
        </div>

        <button wire:click="openCreateModal" wire:loading.attr="disabled" wire:target="openCreateModal" type="button"
            class="inline-flex items-center px-4 py-2 bg-gray-800 border border-transparent rounded-md font-semibold text-xs text-white uppercase tracking-widest hover:bg-gray-700 focus:outline-none focus:ring-2 focus:ring-gray-300 transition disabled:opacity-50 disabled:cursor-not-allowed">
            <svg wire:loading wire:target="openCreateModal" class="animate-spin -ml-1 me-2 h-4 w-4 text-white" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24">
                <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z"></path>
            </svg>
            <span wire:loading.remove wire:target="openCreateModal">{{ __('Create Module') }}</span>
            <span wire:loading wire:target="openCreateModal">{{ __('Loading...') }}</span>
        </button>
    </div>

    <div class="overflow-x-auto">
        <table class="min-w-full divide-y divide-gray-200">
            <thead>
                <tr>
                    <th class="px-4 py-2 text-left text-xs font-medium text-gray-500 uppercase">{{ __('Name') }}</th>
                    <th class="px-4 py-2 text-left text-xs font-medium text-gray-500 uppercase">{{ __('Slug') }}</th>
                    <th class="px-4 py-2 text-left text-xs font-medium text-gray-500 uppercase">{{ __('Parent') }}</th>
                    <th class="px-4 py-2 text-left text-xs font-medium text-gray-500 uppercase">{{ __('Route') }}</th>
                    <th class="px-4 py-2 text-left text-xs font-medium text-gray-500 uppercase">{{ __('Order') }}</th>
                    <th class="px-4 py-2 text-left text-xs font-medium text-gray-500 uppercase">{{ __('Active') }}</th>
                    <th class="px-4 py-2 text-right text-xs font-medium text-gray-500 uppercase">{{ __('Actions') }}</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-gray-100">
                @forelse ($modulesList as $module)
                    <tr wire:key="module-{{ $module->id }}">
                        <td class="px-4 py-2 text-sm text-gray-900">{{ $module->name }}</td>
                        <td class="px-4 py-2 text-sm text-gray-500">{{ $module->slug }}</td>
                        <td class="px-4 py-2 text-sm text-gray-500">{{ $module->parent?->name ?? '—' }}</td>
                        <td class="px-4 py-2 text-sm text-gray-500">{{ $module->route ?? '—' }}</td>
                        <td class="px-4 py-2 text-sm text-gray-500">{{ $module->sort_order }}</td>
                        <td class="px-4 py-2 text-sm">
                            @if ($module->is_active)
                                <span class="inline-flex px-2 text-xs font-semibold leading-5 rounded-full bg-green-100 text-green-800">{{ __('Active') }}</span>
                            @else
                                <span class="inline-flex px-2 text-xs font-semibold leading-5 rounded-full bg-gray-100 text-gray-600">{{ __('Inactive') }}</span>
                            @endif
                        </td>
                        <td class="px-4 py-2 text-sm text-right">
                            <div x-data="{ rowOpen: false }" class="relative inline-block text-left" @click.away="rowOpen = false">
                                <button @click="rowOpen = ! rowOpen" type="button" class="p-1 rounded-full text-gray-400 hover:text-gray-600 hover:bg-gray-100 focus:outline-none">
                                    <svg class="h-5 w-5" fill="currentColor" viewBox="0 0 20 20">
                                        <path d="M10 6a2 2 0 110-4 2 2 0 010 4zM10 12a2 2 0 110-4 2 2 0 010 4zM10 18a2 2 0 110-4 2 2 0 010 4z" />
                                    </svg>
                                </button>

                                <div x-show="rowOpen" x-transition @click="rowOpen = false" class="absolute right-0 z-10 mt-2 w-32 rounded-md shadow-lg bg-white ring-1 ring-black ring-opacity-5" style="display: none;">
                                    <div class="py-1">
                                        <button wire:click="openEditModal({{ $module->id }})" type="button" class="block w-full text-left px-4 py-2 text-sm text-gray-700 hover:bg-gray-100">
                                            {{ __('Edit') }}
                                        </button>
                                        <button wire:click="confirmDelete({{ $module->id }})" type="button" class="block w-full text-left px-4 py-2 text-sm text-red-600 hover:bg-red-50">
                                            {{ __('Delete') }}
                                        </button>
                                    </div>
                                </div>
                            </div>
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="7" class="px-4 py-6 text-sm text-center text-gray-500">{{ __('No modules found.') }}</td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>

    <div class="mt-4">
        {{ $modulesList->links() }}
    </div>

    <!-- Create / Edit Modal -->
    <div x-show="$wire.showModal" x-cloak class="fixed top-0 left-0 right-0 bottom-0 z-50 flex items-center justify-center p-4">
        <div class="fixed inset-0 bg-black bg-opacity-50" wire:click="closeModal"></div>

        <div class="relative bg-white rounded-lg shadow-xl w-full max-w-lg p-6 max-h-[90vh] overflow-y-auto">
            <h3 class="text-lg font-semibold mb-4">
                {{ $editingId ? __('Edit Module') : __('Create Module') }}
            </h3>

            {{-- Tabs — "Actions" only shown once a module exists (editing),
                 since module_actions rows need a real module_id to attach
                 to. A brand-new, not-yet-saved module has none yet. --}}
            @if ($editingId)
                <div class="flex gap-1 mb-4 border-b border-gray-200">
                    <button type="button" wire:click="setTab('details')"
                        class="px-3 py-2 text-sm font-medium border-b-2 -mb-px {{ $activeTab === 'details' ? 'border-gray-800 text-gray-900' : 'border-transparent text-gray-500 hover:text-gray-700' }}">
                        {{ __('Details') }}
                    </button>
                    <button type="button" wire:click="setTab('actions')"
                        class="px-3 py-2 text-sm font-medium border-b-2 -mb-px {{ $activeTab === 'actions' ? 'border-gray-800 text-gray-900' : 'border-transparent text-gray-500 hover:text-gray-700' }}">
                        {{ __('Actions') }}
                        @if (count($moduleActions))
                            <span class="ms-1 inline-flex items-center justify-center px-1.5 py-0.5 text-xs rounded-full bg-gray-100 text-gray-600">{{ count($moduleActions) }}</span>
                        @endif
                    </button>
                </div>
            @endif

            {{-- Details tab — the original create/edit form, untouched
                 aside from being wrapped in this @if so it hides when the
                 Actions tab is active. --}}
            <form wire:submit="save" class="space-y-4 {{ $editingId && $activeTab !== 'details' ? 'hidden' : '' }}">

                <div>
                    <label class="block text-sm font-medium text-gray-700">{{ __('Parent Module') }}</label>
                    <select wire:model="parent_id" class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-400 focus:ring-indigo-400 cursor-pointer">
                        <option value="">{{ __('— None (Top Level) —') }}</option>
                        @foreach ($parentOptions as $option)
                            @if ($option->id !== $editingId)
                                <option value="{{ $option->id }}">{{ $option->name }}</option>
                            @endif
                        @endforeach
                    </select>
                    @error('parent_id') <span class="text-sm text-red-600">{{ $message }}</span> @enderror
                </div>

                <div>
                    <label class="block text-sm font-medium text-gray-700">{{ __('Name') }}</label>
                    <input type="text" wire:model.live="name" class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-400 focus:ring-indigo-400">
                    @error('name') <span class="text-sm text-red-600">{{ $message }}</span> @enderror
                </div>

                <div>
                    <label class="block text-sm font-medium text-gray-700">{{ __('Slug') }}</label>
                    <input type="text" wire:model="slug" class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-400 focus:ring-indigo-400">
                    @error('slug') <span class="text-sm text-red-600">{{ $message }}</span> @enderror
                </div>

                <div>
                    <label class="block text-sm font-medium text-gray-700">{{ __('Icon') }}</label>
                    <input type="text" wire:model="icon" placeholder="e.g. home, shopping-cart" class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-400 focus:ring-indigo-400">
                    @error('icon') <span class="text-sm text-red-600">{{ $message }}</span> @enderror
                </div>

                <div>
                    <label class="block text-sm font-medium text-gray-700">{{ __('Route Name') }}</label>
                    <input type="text" wire:model="route" placeholder="e.g. sales.pos" class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-400 focus:ring-indigo-400">
                    @error('route') <span class="text-sm text-red-600">{{ $message }}</span> @enderror
                </div>

                <div>
                    <label class="block text-sm font-medium text-gray-700">{{ __('Sort Order') }}</label>
                    <input type="number" wire:model="sort_order" class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-400 focus:ring-indigo-400">
                    @error('sort_order') <span class="text-sm text-red-600">{{ $message }}</span> @enderror
                </div>

                <div class="flex items-center">
                    <input type="checkbox" wire:model="is_active" id="is_active" class="rounded border-gray-300 text-indigo-600 shadow-sm focus:ring-indigo-400">
                    <label for="is_active" class="ms-2 text-sm text-gray-700">{{ __('Active') }}</label>
                </div>

                <div class="flex justify-end gap-2 pt-4">
                    <button type="button" wire:click="closeModal" wire:loading.attr="disabled" wire:target="closeModal" class="px-4 py-2 text-sm font-medium text-gray-600 hover:text-gray-800 disabled:opacity-50 disabled:cursor-not-allowed inline-flex items-center">
                        <svg wire:loading wire:target="closeModal" class="animate-spin -ml-1 me-2 h-4 w-4 text-gray-600" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24">
                            <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                            <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z"></path>
                        </svg>
                        <span wire:loading.remove wire:target="closeModal">{{ __('Cancel') }}</span>
                        <span wire:loading wire:target="closeModal">{{ __('Closing...') }}</span>
                    </button>
                    <button type="submit" wire:loading.attr="disabled" wire:target="save" class="inline-flex items-center px-4 py-2 bg-gray-800 border border-transparent rounded-md font-semibold text-xs text-white uppercase tracking-widest hover:bg-gray-700 focus:outline-none focus:ring-2 focus:ring-gray-300 transition disabled:opacity-50 disabled:cursor-not-allowed">
                        <svg wire:loading wire:target="save" class="animate-spin -ml-1 me-2 h-4 w-4 text-white" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24">
                            <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                            <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z"></path>
                        </svg>
                        <span wire:loading.remove wire:target="save">{{ __('Save') }}</span>
                        <span wire:loading wire:target="save">{{ __('Saving...') }}</span>
                    </button>
                </div>

            </form>

            {{-- Actions tab — manage module_actions rows for $editingId.
                 Simple add-form + delete-per-row list, per the chosen UI. --}}
            @if ($editingId && $activeTab === 'actions')
                <div class="space-y-4">

                    <p class="text-sm text-gray-500">
                        {{ __('Define the actions (e.g. view, create, edit, delete, export) that can be granted to roles for this module on the Permissions page.') }}
                    </p>

                    {{-- Add-action mini form --}}
                    <div class="flex items-start gap-2">
                        <div class="flex-1">
                            <input type="text" wire:model="newActionKey" wire:keydown.enter.prevent="addAction"
                                placeholder="{{ __('key, e.g. export') }}"
                                class="block w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-400 focus:ring-indigo-400 text-sm">
                            @error('newActionKey') <span class="text-xs text-red-600">{{ $message }}</span> @enderror
                        </div>
                        <div class="flex-1">
                            <input type="text" wire:model="newActionLabel" wire:keydown.enter.prevent="addAction"
                                placeholder="{{ __('Label, e.g. Export Report') }}"
                                class="block w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-400 focus:ring-indigo-400 text-sm">
                            @error('newActionLabel') <span class="text-xs text-red-600">{{ $message }}</span> @enderror
                        </div>
                        <button type="button" wire:click="addAction" wire:loading.attr="disabled" wire:target="addAction"
                            class="inline-flex items-center px-3 py-2 bg-gray-800 border border-transparent rounded-md font-semibold text-xs text-white uppercase tracking-widest hover:bg-gray-700 focus:outline-none focus:ring-2 focus:ring-gray-300 transition disabled:opacity-50 disabled:cursor-not-allowed whitespace-nowrap">
                            <svg wire:loading wire:target="addAction" class="animate-spin -ml-1 me-1.5 h-4 w-4 text-white" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24">
                                <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                                <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z"></path>
                            </svg>
                            <span wire:loading.remove wire:target="addAction">{{ __('Add') }}</span>
                        </button>
                    </div>

                    {{-- Existing actions list --}}
                    <div class="border border-gray-200 rounded-md divide-y divide-gray-100">
                        @forelse ($moduleActions as $action)
                            <div wire:key="module-action-{{ $action['id'] }}" class="flex items-center justify-between px-3 py-2">
                                <div class="min-w-0">
                                    <p class="text-sm text-gray-900 truncate">{{ $action['label'] }}</p>
                                    <p class="text-xs text-gray-400 font-mono">{{ $action['key'] }}</p>
                                </div>
                                <button type="button" wire:click="deleteAction({{ $action['id'] }})"
                                    wire:loading.attr="disabled" wire:target="deleteAction({{ $action['id'] }})"
                                    wire:confirm="{{ __('Remove this action? Any role permissions granting it will also be removed.') }}"
                                    class="shrink-0 ms-3 p-1.5 rounded-full text-gray-400 hover:text-red-600 hover:bg-red-50 focus:outline-none disabled:opacity-50 disabled:cursor-not-allowed">
                                    <svg class="h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16" />
                                    </svg>
                                </button>
                            </div>
                        @empty
                            <p class="px-3 py-6 text-sm text-center text-gray-500">{{ __('No actions defined yet.') }}</p>
                        @endforelse
                    </div>

                    <div class="flex justify-end pt-2">
                        <button type="button" wire:click="closeModal" wire:loading.attr="disabled" wire:target="closeModal"
                            class="px-4 py-2 text-sm font-medium text-gray-600 hover:text-gray-800 disabled:opacity-50 disabled:cursor-not-allowed">
                            {{ __('Done') }}
                        </button>
                    </div>

                </div>
            @endif

        </div>
    </div>

    <!-- Delete Confirmation Modal -->
    <div x-show="$wire.confirmingDelete" x-cloak class="fixed top-0 left-0 right-0 bottom-0 z-50 flex items-center justify-center p-4">
        <div class="fixed inset-0 bg-black bg-opacity-50" wire:click="cancelDelete"></div>

        <div class="relative bg-white rounded-lg shadow-xl w-full max-w-md p-6">
            <h3 class="text-lg font-semibold mb-2">{{ __('Delete Module') }}</h3>
            <p class="text-sm text-gray-600 mb-6">
                {{ __('Are you sure you want to delete this module? This action cannot be undone, and any child modules linked to it will also be affected.') }}
            </p>

            <div class="flex justify-end gap-2">
                <button type="button" wire:click="cancelDelete" wire:loading.attr="disabled" wire:target="cancelDelete" class="px-4 py-2 text-sm font-medium text-gray-600 hover:text-gray-800 disabled:opacity-50 disabled:cursor-not-allowed">
                    {{ __('Cancel') }}
                </button>
                <button type="button" wire:click="delete" wire:loading.attr="disabled" wire:target="delete" class="inline-flex items-center px-4 py-2 bg-red-600 border border-transparent rounded-md font-semibold text-xs text-white uppercase tracking-widest hover:bg-red-700 focus:outline-none focus:ring-2 focus:ring-red-300 transition disabled:opacity-50 disabled:cursor-not-allowed">
                    <svg wire:loading wire:target="delete" class="animate-spin -ml-1 me-2 h-4 w-4 text-white" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24">
                        <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                        <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z"></path>
                    </svg>
                    <span wire:loading.remove wire:target="delete">{{ __('Delete') }}</span>
                    <span wire:loading wire:target="delete">{{ __('Deleting...') }}</span>
                </button>
            </div>
        </div>
    </div>

</div>