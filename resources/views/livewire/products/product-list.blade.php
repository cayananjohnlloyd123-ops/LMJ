<?php

use App\Models\Product;
use Livewire\Volt\Component;

new class extends Component {

    // --- DataTable config ---

    public string $tableModel = Product::class;

    public array $tableColumns = [
        ['key' => 'sku',           'label' => 'SKU',           'sortable' => true],
        ['key' => 'name',          'label' => 'Name',          'sortable' => true],
        ['key' => 'cost_price',    'label' => 'Cost Price',    'sortable' => true, 'align' => 'right', 'format' => 'currency'],
        ['key' => 'selling_price', 'label' => 'Selling Price', 'sortable' => true, 'align' => 'right', 'format' => 'currency'],
        ['key' => 'profit',        'label' => 'Profit',        'align' => 'right', 'format' => 'profit'],
        ['key' => 'stock',         'label' => 'Stock',         'sortable' => true, 'align' => 'right', 'format' => 'integer'],
    ];

    public array $tableSearchable = ['name', 'sku'];

    public array $tableRowActions = [
        [
            'label' => 'Edit',
            'event' => 'edit-product',
            'icon'  => '<svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z"/></svg>',
        ],
    ];

    // --- ModalForm config ---

    public array $modalFields = [
        ['key' => 'sku',           'label' => 'SKU',          'type' => 'text',   'required' => true,  'span' => 'full', 'rules' => ['required', 'string', 'max:100']],
        ['key' => 'name',          'label' => 'Name',         'type' => 'text',   'required' => true,  'span' => 'full', 'rules' => ['required', 'string', 'max:255']],
        ['key' => 'cost_price',    'label' => 'Cost Price',   'type' => 'number', 'required' => true,  'span' => 'half', 'step' => '0.01', 'min' => '0', 'rules' => ['required', 'numeric', 'min:0']],
        ['key' => 'selling_price', 'label' => 'Selling Price','type' => 'number', 'required' => true,  'span' => 'half', 'step' => '0.01', 'min' => '0', 'rules' => ['required', 'numeric', 'min:0']],
        ['key' => 'stock',         'label' => 'Stock',        'type' => 'number', 'required' => true,  'span' => 'full', 'min'  => '0',    'rules' => ['required', 'integer', 'min:0']],
    ];

    // --- Internal state ---
    public ?int $editingId = null;

    // Triggered by modal-form component on submit — validation already done in modal-form
    public function saveProduct(array $data): void
    {
        // sku uniqueness check (excludes current record when editing)
        $skuRule = $this->editingId
            ? 'unique:products,sku,' . $this->editingId
            : 'unique:products,sku';

        $validator = \Illuminate\Support\Facades\Validator::make(
            $data,
            ['sku' => ['required', $skuRule]]
        );

        if ($validator->fails()) {
            $this->dispatch('modal-error', message: 'That SKU is already in use.')->to('modal-form');
            return;
        }

        if ($this->editingId) {
            Product::findOrFail($this->editingId)->update($data);
            $this->dispatch('notify', message: 'Product updated successfully.');
        } else {
            Product::create($data);
            $this->dispatch('notify', message: 'Product created successfully.');
        }

        $this->editingId = null;

        // Tell modal-form the save succeeded so it can close itself
        // and clear its loading state. modal-form listens for this
        // via #[On('modal-saved')] on its onSaved() method.
        $this->dispatch('modal-saved')->to('modal-form');

        // Tell the data-tables component to re-query and re-render
        // with the latest DB data. It listens via #[On('table-refresh')]
        // on its refreshTable() method.
        $this->dispatch('table-refresh');
    }

    // Triggered by the DataTable row action
    public function handleEditProduct(int $id): void
    {
        $product = Product::findOrFail($id);
        $this->editingId = $product->id;

        // modal-form listens for this via #[On('modal-open')] on openModal()
        $this->dispatch('modal-open', data: $product->only([
            'sku', 'name', 'cost_price', 'selling_price', 'stock',
        ]))->to('modal-form');
    }
}; ?>

<div class="p-6">

    {{-- Flash message --}}
    <div
        x-data="{ show: false, message: '' }"
        x-on:notify.window="message = $event.detail.message; show = true; setTimeout(() => show = false, 3000)"
        x-show="show"
        x-transition
        x-cloak
        class="mb-4 rounded-lg bg-green-50 border border-green-200 px-4 py-3 text-sm text-green-700"
    >
        <span x-text="message"></span>
    </div>

    {{-- Header --}}
    <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4 mb-6">
        <div>
            <h1 class="text-xl font-semibold text-gray-900">Products - List</h1>
            <p class="text-sm text-gray-500">Manage your product catalog.</p>
        </div>

        {{-- Trigger button is built into modal-form --}}
        <livewire:modal-form
            title="Product"
            :fields="$modalFields"
            save-event="save-product"
            button-label="Add Product"
            @save-product="saveProduct($event.detail.data)"
        />
    </div>

    {{-- DataTable --}}
    <livewire:data-tables
        :model="$tableModel"
        :columns="$tableColumns"
        :searchable="$tableSearchable"
        :row-actions="$tableRowActions"
        :bulk-actions="true"
        :per-page="10"
        @edit-product="handleEditProduct($event.detail.id)"
    />

</div>