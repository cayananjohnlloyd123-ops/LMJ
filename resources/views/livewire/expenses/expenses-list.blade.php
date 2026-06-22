<?php

use Livewire\Volt\Component;
use Livewire\Attributes\On;
use App\Models\Expense;
use App\Models\ExpenseCategory;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;

new class extends Component {
    public ?int $editingId = null;
    public ?int $confirmingDeleteId = null;

    /**
     * Field definitions for the generic modal-form. 'options' for the
     * category select is built fresh each render so newly-added categories
     * always show up without needing a page reload.
     */
    public function getFormFieldsProperty(): array
    {
        return [
            [
                'key'      => 'expense_category_id',
                'label'    => 'Category',
                'type'     => 'select',
                'required' => true,
                'options'  => ExpenseCategory::orderBy('name')->get()
                    ->map(fn ($c) => ['value' => $c->id, 'label' => $c->name])
                    ->toArray(),
                'rules'    => ['required', 'exists:expense_categories,id'],
                'span'     => 'half',
            ],
            [
                'key'      => 'expense_date',
                'label'    => 'Expense Date',
                'type'     => 'date',
                'required' => true,
                'rules'    => ['required', 'date'],
                'span'     => 'half',
                'default'  => now()->format('Y-m-d'),
            ],
            [
                'key'         => 'description',
                'label'       => 'Description',
                'type'        => 'text',
                'placeholder' => 'e.g. Office supplies, Electricity bill...',
                'required'    => true,
                'rules'       => ['required', 'string', 'max:255'],
                'span'        => 'full',
            ],
            [
                'key'      => 'amount',
                'label'    => 'Amount',
                'type'     => 'number',
                'step'     => '0.01',
                'min'      => '0',
                'required' => true,
                'rules'    => ['required', 'numeric', 'min:0.01'],
                'span'     => 'half',
            ],
            [
                'key'        => 'attachment',
                'label'      => 'Attachment (Receipt)',
                'type'       => 'file',
                'accept'     => 'image/*',
                'maxSizeKb'  => 4096,
                'disk'       => 'public',
                'directory'  => 'expenses/attachments',
                'required'   => false,
                'span'       => 'full',
            ],
        ];
    }

    /**
     * Trigger Add — generic modal-form's own button already opens it
     * client-side blank, so nothing to do here except make sure we're
     * not carrying over a stale editingId from a previous Edit click.
     */
    public function mount(): void
    {
        $this->editingId = null;
    }

    /**
     * Listens for modal-form's modal-reset event — fired whenever the modal
     * is closed (Cancel/X) or reopened fresh via the "New" button. Without
     * this, $editingId would stay set from a previous Edit session, causing
     * the next "New" submission to silently UPDATE that old record instead
     * of creating a new one.
     */
    #[On('modal-reset')]
    public function resetEditingState(): void
    {
        $this->editingId = null;
    }

    #[On('edit-expense')]
    public function editExpense(int $id): void
    {
        $expense = Expense::findOrFail($id);
        $this->editingId = $id;

        $this->dispatch('modal-open', data: [
            'expense_category_id' => $expense->expense_category_id,
            'expense_date'        => $expense->expense_date->format('Y-m-d'),
            'description'         => $expense->description,
            'amount'              => (string) $expense->amount,
            'attachment'          => $expense->attachment,
        ])->to('modal-form');
    }

    #[On('delete-expense')]
    public function confirmDelete(int $id): void
    {
        $this->confirmingDeleteId = $id;
    }

    public function cancelDelete(): void
    {
        $this->confirmingDeleteId = null;
    }

    public function destroy(): void
    {
        if (! $this->confirmingDeleteId) {
            return;
        }

        $expense = Expense::find($this->confirmingDeleteId);

        if ($expense) {
            if ($expense->attachment) {
                Storage::disk('public')->delete($expense->attachment);
            }
            $expense->delete();
            $this->dispatch('notify', message: 'Expense deleted.');
            $this->dispatch('table-refresh');
        }

        $this->confirmingDeleteId = null;
    }

    /**
     * Handles the generic modal-form's save dispatch. $data already contains
     * the uploaded attachment's storage path (string|null) — modal-form
     * does the actual file storage before dispatching this event.
     */
    #[On('save-expense')]
    public function saveExpense(array $data): void
    {
        try {
            if ($this->editingId) {
                Expense::where('id', $this->editingId)->update($data);
                $message = 'Expense updated successfully.';
            } else {
                $data['created_by'] = Auth::id();
                Expense::create($data);
                $message = 'Expense recorded successfully.';
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
    x-data="{ show: false, message: '' }"
    x-on:notify.window="message = $event.detail.message; show = true; setTimeout(() => show = false, 3000)"
>
    {{-- Flash message --}}
    <div
        x-show="show"
        x-transition
        x-cloak
        class="mb-4 rounded-lg bg-green-50 border border-green-200 px-4 py-3 text-sm text-green-700"
    >
        <span x-text="message"></span>
    </div>

    {{-- Header / Create button (generic modal-form's own trigger) --}}
    <div class="flex items-center justify-between mb-4">
        <h3 class="text-lg font-semibold text-gray-800">Expenses</h3>
        <livewire:modal-form
            title="New Expense"
            button-label="New Expense"
            save-event="save-expense"
            :fields="$this->formFields"
            wire:key="modal-form-expense"
        />
    </div>

    <livewire:data-tables
        model="App\Models\Expense"
        :columns="[
            ['key' => 'expense_date',   'label' => 'Date',        'sortable' => true, 'format' => 'date'],
            ['key' => 'category_name',  'label' => 'Category',    'sortable' => true],
            ['key' => 'description',    'label' => 'Description', 'sortable' => true],
            ['key' => 'amount',         'label' => 'Amount',      'sortable' => true, 'align' => 'right', 'format' => 'currency'],
            ['key' => 'creator_name',   'label' => 'Recorded By', 'sortable' => true],
        ]"
        :searchable="['description', 'category_name', 'creator_name']"
        :row-actions="[
            ['label' => 'View Details', 'event' => 'view-expense-details'],
            ['label' => 'Edit',         'event' => 'edit-expense'],
            ['label' => 'Delete',       'event' => 'delete-expense', 'danger' => true],
        ]"
        :per-page="15"
    />

    {{-- View details modal (generic, reused from sales) --}}
    <livewire:view-modal
        model="App\Models\Expense"
        event="view-expense-details"
        title="Expense Details"
        :fields="[
            ['key' => 'expense_date',  'label' => 'Date',     'format' => 'date'],
            ['key' => 'category_name', 'label' => 'Category'],
            ['key' => 'description',   'label' => 'Description'],
            ['key' => 'amount',        'label' => 'Amount',   'format' => 'currency'],
            ['key' => 'creator_name',  'label' => 'Recorded By'],
        ]"
    />

    {{-- Delete confirmation --}}
    @if ($confirmingDeleteId)
        <div x-data class="fixed inset-0 z-[9998] flex items-center justify-center p-4" @keydown.escape.window="$wire.cancelDelete()">
            <div wire:click="cancelDelete" class="fixed inset-0 bg-gray-900/50"></div>
            <div class="relative z-10 w-full max-w-sm rounded-xl bg-white shadow-xl p-6">
                <h3 class="text-base font-semibold text-gray-800 mb-2">Delete Expense?</h3>
                <p class="text-sm text-gray-500 mb-5">
                    This action cannot be undone. The expense record and its attachment (if any) will be permanently removed.
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