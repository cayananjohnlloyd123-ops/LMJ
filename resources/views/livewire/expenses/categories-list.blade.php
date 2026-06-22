<?php

use Livewire\Volt\Component;
use Livewire\Attributes\On;
use App\Models\ExpenseCategory;

new class extends Component {
    public ?int $editingId = null;
    public ?int $confirmingDeleteId = null;
    public ?string $deleteBlockedReason = null;

    /**
     * Field definitions for the generic modal-form. Single text field —
     * unique validation excludes the current record when editing.
     */
    public function getFormFieldsProperty(): array
    {
        return [
            [
                'key'         => 'name',
                'label'       => 'Category Name',
                'type'        => 'text',
                'placeholder' => 'e.g. Utilities, Office Supplies, Transportation...',
                'required'    => true,
                'rules'       => [
                    'required',
                    'string',
                    'max:255',
                    'unique:expense_categories,name' . ($this->editingId ? ',' . $this->editingId : ''),
                ],
                'span' => 'full',
            ],
        ];
    }

    /**
     * Listens for modal-form's modal-reset event — fired whenever the modal
     * is closed (Cancel/X) or reopened fresh via the "New" button. Without
     * this, $editingId would stay set from a previous Edit session, which
     * causes two bugs: (1) the next "New" submission would silently UPDATE
     * the old record instead of creating a new one, and (2) the unique
     * name validation above would wrongly exclude the old record's ID.
     */
    #[On('modal-reset')]
    public function resetEditingState(): void
    {
        $this->editingId = null;
    }

    #[On('edit-category')]
    public function editCategory(int $id): void
    {
        $category = ExpenseCategory::findOrFail($id);
        $this->editingId = $id;

        $this->dispatch('modal-open', data: [
            'name' => $category->name,
        ])->to('modal-form');
    }

    #[On('delete-category')]
    public function confirmDelete(int $id): void
    {
        $category = ExpenseCategory::withCount('expenses')->find($id);

        if (! $category) {
            return;
        }

        if ($category->expenses_count > 0) {
            $this->deleteBlockedReason = "This category has {$category->expenses_count} linked expense(s) and cannot be deleted. Reassign or remove those expenses first.";
            $this->confirmingDeleteId = null;
            return;
        }

        $this->deleteBlockedReason = null;
        $this->confirmingDeleteId = $id;
    }

    public function cancelDelete(): void
    {
        $this->confirmingDeleteId = null;
        $this->deleteBlockedReason = null;
    }

    public function destroy(): void
    {
        if (! $this->confirmingDeleteId) {
            return;
        }

        // Re-check at delete time too — guards against a race where an
        // expense gets linked between the confirm click and this request.
        $category = ExpenseCategory::withCount('expenses')->find($this->confirmingDeleteId);

        if (! $category) {
            $this->confirmingDeleteId = null;
            return;
        }

        if ($category->expenses_count > 0) {
            $this->confirmingDeleteId = null;
            $this->dispatch('notify', message: 'Cannot delete: this category still has linked expenses.', type: 'error');
            return;
        }

        $category->delete();
        $this->confirmingDeleteId = null;
        $this->dispatch('notify', message: 'Category deleted.');
        $this->dispatch('table-refresh');
    }

    #[On('save-category')]
    public function saveCategory(array $data): void
    {
        try {
            if ($this->editingId) {
                ExpenseCategory::where('id', $this->editingId)->update($data);
                $message = 'Category updated successfully.';
            } else {
                ExpenseCategory::create($data);
                $message = 'Category created successfully.';
            }

            $this->editingId = null;
            $this->dispatch('modal-saved')->to('modal-form');
            $this->dispatch('notify', message: $message);
            $this->dispatch('table-refresh');
        } catch (\Throwable $e) {
            report($e);
            $this->dispatch('modal-error', message: 'Something went wrong while saving. Please try again.')->to('modal-form');
        }
    }
}; ?>

<div
    x-data="{ show: false, message: '', type: 'success' }"
    x-on:notify.window="
        message = $event.detail.message;
        type = $event.detail.type ?? 'success';
        show = true;
        setTimeout(() => show = false, 3000)
    "
>
    {{-- Flash message --}}
    <div
        x-show="show"
        x-transition
        x-cloak
        :class="type === 'error'
            ? 'bg-red-50 border-red-200 text-red-700'
            : 'bg-green-50 border-green-200 text-green-700'"
        class="mb-4 rounded-lg border px-4 py-3 text-sm"
    >
        <span x-text="message"></span>
    </div>

    {{-- Header / Create button (generic modal-form's own trigger) --}}
    <div class="flex items-center justify-between mb-4">
        <h3 class="text-lg font-semibold text-gray-800">Expense Categories</h3>
        <livewire:modal-form
            title="New Category"
            button-label="New Category"
            save-event="save-category"
            :fields="$this->formFields"
            wire:key="modal-form-category"
        />
    </div>

    <livewire:data-tables
        model="App\Models\ExpenseCategory"
        :columns="[
            ['key' => 'name',       'label' => 'Name',       'sortable' => true],
            ['key' => 'created_at', 'label' => 'Created',    'sortable' => true, 'format' => 'date'],
        ]"
        :searchable="['name']"
        :row-actions="[
            ['label' => 'View Details', 'event' => 'view-category-details'],
            ['label' => 'Edit',         'event' => 'edit-category'],
            ['label' => 'Delete',       'event' => 'delete-category', 'danger' => true],
        ]"
        :per-page="15"
    />

    {{-- View details modal (generic, reused) --}}
    <livewire:view-modal
        model="App\Models\ExpenseCategory"
        event="view-category-details"
        title="Category Details"
        :fields="[
            ['key' => 'name',       'label' => 'Name'],
            ['key' => 'created_at', 'label' => 'Created', 'format' => 'datetime'],
            ['key' => 'updated_at', 'label' => 'Last Updated', 'format' => 'datetime'],
        ]"
    />

    {{-- Delete blocked notice --}}
    @if ($deleteBlockedReason)
        <div x-data class="fixed inset-0 z-[9998] flex items-center justify-center p-4" @keydown.escape.window="$wire.cancelDelete()">
            <div wire:click="cancelDelete" class="fixed inset-0 bg-gray-900/50"></div>
            <div class="relative z-10 w-full max-w-sm rounded-xl bg-white shadow-xl p-6">
                <div class="flex items-start gap-3 mb-4">
                    <div class="shrink-0 flex h-10 w-10 items-center justify-center rounded-full bg-amber-50">
                        <svg class="w-5 h-5 text-amber-500" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01M5.07 19h13.86c1.54 0 2.5-1.67 1.73-3L13.73 4c-.77-1.33-2.69-1.33-3.46 0L3.34 16c-.77 1.33.19 3 1.73 3z" />
                        </svg>
                    </div>
                    <div>
                        <h3 class="text-base font-semibold text-gray-800">Cannot Delete Category</h3>
                        <p class="mt-1 text-sm text-gray-500">{{ $deleteBlockedReason }}</p>
                    </div>
                </div>
                <div class="flex justify-end">
                    <button wire:click="cancelDelete" class="rounded-lg bg-gray-800 px-4 py-2 text-sm font-medium text-white hover:bg-gray-900">
                        Got it
                    </button>
                </div>
            </div>
        </div>
    @endif

    {{-- Delete confirmation --}}
    @if ($confirmingDeleteId)
        <div x-data class="fixed inset-0 z-[9998] flex items-center justify-center p-4" @keydown.escape.window="$wire.cancelDelete()">
            <div wire:click="cancelDelete" class="fixed inset-0 bg-gray-900/50"></div>
            <div class="relative z-10 w-full max-w-sm rounded-xl bg-white shadow-xl p-6">
                <h3 class="text-base font-semibold text-gray-800 mb-2">Delete Category?</h3>
                <p class="text-sm text-gray-500 mb-5">
                    This action cannot be undone.
                </p>
                <div class="flex justify-end gap-2">
                    <button wire:click="cancelDelete" class="rounded-lg border border-gray-300 px-4 py-2 text-sm font-medium text-gray-700 hover:bg-gray-50">
                        Cancel
                    </button>
                    <button wire:click="destroy" class="rounded-lg bg-red-600 px-4 py-2 text-sm font-medium text-white hover:bg-red-700">
                        Delete
                    </button>
                </div>
            </div>
        </div>
    @endif
</div>