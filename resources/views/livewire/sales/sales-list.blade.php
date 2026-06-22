<?php

use Livewire\Volt\Component;

new class extends Component {
    //
}; ?>

<div>
    <livewire:data-tables
        model="App\Models\Sale"
        :columns="[
            ['key' => 'invoice_no',   'label' => 'Invoice No',  'sortable' => true],
            ['key' => 'cashier_name', 'label' => 'Cashier',     'sortable' => true],
            ['key' => 'items_count',  'label' => 'Items',       'sortable' => true, 'align' => 'center', 'format' => 'integer'],
            ['key' => 'total_amount', 'label' => 'Total',       'sortable' => true, 'align' => 'right',  'format' => 'currency'],
            ['key' => 'created_at',   'label' => 'Date',        'sortable' => true, 'format' => 'datetime'],
        ]"
        :searchable="['invoice_no', 'cashier_name']"
        :row-actions="[
            ['label' => 'View Details', 'event' => 'view-sale-details', 'icon' => '<svg class=\'w-4 h-4\' fill=\'none\' stroke=\'currentColor\' viewBox=\'0 0 24 24\'><path stroke-linecap=\'round\' stroke-linejoin=\'round\' stroke-width=\'2\' d=\'M15 12a3 3 0 11-6 0 3 3 0 016 0z\'/><path stroke-linecap=\'round\' stroke-linejoin=\'round\' stroke-width=\'2\' d=\'M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z\'/></svg>'],
        ]"
        :per-page="15"
    />

    <livewire:view-modal
        model="App\Models\Sale"
        event="view-sale-details"
        title="Sale Details"
        :fields="[
            ['key' => 'invoice_no',   'label' => 'Invoice No'],
            ['key' => 'cashier_name', 'label' => 'Cashier'],
            ['key' => 'created_at',   'label' => 'Date',  'format' => 'datetime'],
            ['key' => 'total_amount', 'label' => 'Total', 'format' => 'currency'],
        ]"
        :relation-config="[
            'relation' => 'items',
            'label'    => 'Items Purchased',
            'columns'  => [
                ['key' => 'product.name', 'label' => 'Product'],
                ['key' => 'qty',          'label' => 'Qty',      'align' => 'center', 'format' => 'integer'],
                ['key' => 'price',        'label' => 'Price',    'align' => 'right',  'format' => 'currency'],
                ['key' => 'subtotal',     'label' => 'Subtotal', 'align' => 'right',  'format' => 'currency'],
            ],
        ]"
    />
</div>