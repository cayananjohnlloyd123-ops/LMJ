<?php

use Livewire\Volt\Component;
use Livewire\Attributes\On;

new class extends Component {
    // --- Required props ---
    public string $model = '';   // e.g. App\Models\Sale

    // --- Optional props ---
    public string $event  = 'view-details'; // event name this modal listens for
    public string $title  = 'Details';      // modal header title

    // Header fields: [['key' => 'invoice_no', 'label' => 'Invoice No', 'format' => null], ...]
    // Reuses the same 'format' vocabulary as the data-table component
    // (currency, integer, date, datetime, boolean, profit, badge:key=label).
    public array $fields = [];

    // Optional related collection rendered as a sub-table, e.g. Sale->items
    // [
    //   'relation' => 'items',
    //   'label'    => 'Items',
    //   'columns'  => [
    //       ['key' => 'product.name', 'label' => 'Product'],
    //       ['key' => 'qty',          'label' => 'Qty',      'align' => 'center', 'format' => 'integer'],
    //       ['key' => 'price',        'label' => 'Price',    'align' => 'right',  'format' => 'currency'],
    //       ['key' => 'subtotal',     'label' => 'Subtotal', 'align' => 'right',  'format' => 'currency'],
    //   ],
    // ]
    public ?array $relationConfig = null;

    // --- Internal state ---
    public bool $show = false;
    public ?int $recordId = null;

    /**
     * Dynamically listen for the configured event name.
     * Volt's #[On] attribute needs a compile-time string, so we resolve
     * the actual listener binding via getListeners() instead.
     */
    public function getListeners(): array
    {
        return [
            $this->event => 'open',
        ];
    }

    public function open(int $id): void
    {
        $this->recordId = $id;
        $this->show = true;
    }

    public function close(): void
    {
        $this->show = false;
        $this->recordId = null;
    }

    public function getRecordProperty()
    {
        if (! $this->recordId) {
            return null;
        }

        $modelClass = $this->model;
        $query = $modelClass::query();

        if ($this->relationConfig) {
            $query->with($this->relationConfig['relation']);
        }

        return $query->find($this->recordId);
    }

    public function getItemsProperty()
    {
        if (! $this->relationConfig || ! $this->record) {
            return collect();
        }

        return data_get($this->record, $this->relationConfig['relation'], collect());
    }

    /**
     * Mirrors data-table's formatCell so both components render values
     * identically. Kept duplicated (not extracted to a shared trait) so
     * this modal stays a single drop-in file with no required base class.
     */
    public function formatValue(mixed $row, array $col): string
    {
        $value = data_get($row, $col['key']);
        $type  = $col['format'] ?? null;

        if (! $type) {
            return e($value ?? '');
        }

        if ($type === 'currency') {
            return e(number_format((float) $value, 2));
        }

        if ($type === 'integer') {
            return e(number_format((int) $value));
        }

        if ($type === 'date') {
            return $value ? e(\Carbon\Carbon::parse($value)->format('d M Y')) : '';
        }

        if ($type === 'datetime') {
            return $value ? e(\Carbon\Carbon::parse($value)->format('d M Y H:i')) : '';
        }

        if ($type === 'boolean') {
            return $value
                ? '<span class="inline-flex items-center rounded-full bg-green-100 px-2 py-0.5 text-xs font-medium text-green-700">Yes</span>'
                : '<span class="inline-flex items-center rounded-full bg-gray-100 px-2 py-0.5 text-xs font-medium text-gray-500">No</span>';
        }

        if ($type === 'profit') {
            $formatted = number_format((float) $value, 2);
            $class     = ((float) $value) >= 0 ? 'text-green-600' : 'text-red-600';
            return '<span class="' . $class . '">' . e($formatted) . '</span>';
        }

        if (str_starts_with($type, 'badge:')) {
            [$colorKey, $labelKey] = explode('=', substr($type, 6), 2);
            $colorValue = data_get($row, $colorKey);
            $label      = e(data_get($row, $labelKey) ?? $colorValue);

            $colorMap = [
                'active'    => 'bg-green-100 text-green-700',
                'inactive'  => 'bg-gray-100 text-gray-500',
                'pending'   => 'bg-yellow-100 text-yellow-700',
                'cancelled' => 'bg-red-100 text-red-600',
                'paid'      => 'bg-blue-100 text-blue-700',
                'unpaid'    => 'bg-orange-100 text-orange-600',
            ];
            $classes = $colorMap[strtolower((string) $colorValue)] ?? 'bg-gray-100 text-gray-600';

            return '<span class="inline-flex items-center rounded-full px-2 py-0.5 text-xs font-medium ' . $classes . '">' . $label . '</span>';
        }

        return e($value ?? '');
    }
}; ?>

<div>
    @if ($show)
        <div
            x-data
            x-trap.inert.noscroll="@js($show)"
            @keydown.escape.window="$wire.close()"
            class="fixed inset-0 z-[9998] flex items-center justify-center p-4"
        >
            {{-- Backdrop --}}
            <div
                wire:click="close"
                class="fixed inset-0 bg-gray-900/50 transition-opacity"
            ></div>

            {{-- Panel --}}
            <div
                x-show="true"
                x-transition:enter="ease-out duration-200"
                x-transition:enter-start="opacity-0 scale-95"
                x-transition:enter-end="opacity-100 scale-100"
                class="relative z-10 w-full max-w-2xl max-h-[85vh] overflow-y-auto rounded-xl bg-white shadow-xl"
            >
                {{-- Header --}}
                <div class="flex items-center justify-between border-b border-gray-100 px-6 py-4 sticky top-0 bg-white">
                    <h3 class="text-base font-semibold text-gray-800">{{ $title }}</h3>
                    <button
                        type="button"
                        wire:click="close"
                        class="rounded-full p-1.5 text-gray-400 hover:bg-gray-100 hover:text-gray-600"
                    >
                        <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" />
                        </svg>
                    </button>
                </div>

                <div class="px-6 py-5 space-y-6">
                    @if (! $this->record)
                        <p class="text-sm text-gray-400 text-center py-8">Record not found.</p>
                    @else
                        {{-- Header fields --}}
                        @if (count($fields))
                            <dl class="grid grid-cols-1 sm:grid-cols-2 gap-x-6 gap-y-4">
                                @foreach ($fields as $field)
                                    <div>
                                        <dt class="text-xs font-medium text-gray-400 uppercase tracking-wide">
                                            {{ $field['label'] }}
                                        </dt>
                                        <dd class="mt-1 text-sm text-gray-800">
                                            {!! $this->formatValue($this->record, $field) !!}
                                        </dd>
                                    </div>
                                @endforeach
                            </dl>
                        @endif

                        {{-- Related items sub-table --}}
                        @if ($relationConfig && count($relationConfig['columns'] ?? []))
                            <div>
                                <h4 class="text-xs font-medium text-gray-400 uppercase tracking-wide mb-2">
                                    {{ $relationConfig['label'] ?? 'Items' }}
                                </h4>
                                <div class="overflow-x-auto rounded-lg border border-gray-200">
                                    <table class="min-w-full divide-y divide-gray-200">
                                        <thead class="bg-gray-50">
                                            <tr>
                                                @foreach ($relationConfig['columns'] as $col)
                                                    <th class="px-3 py-2 text-{{ $col['align'] ?? 'left' }} text-xs font-medium text-gray-500 uppercase tracking-wide">
                                                        {{ $col['label'] }}
                                                    </th>
                                                @endforeach
                                            </tr>
                                        </thead>
                                        <tbody class="bg-white divide-y divide-gray-100">
                                            @forelse ($this->items as $item)
                                                <tr>
                                                    @foreach ($relationConfig['columns'] as $col)
                                                        <td class="px-3 py-2 text-sm text-gray-700 text-{{ $col['align'] ?? 'left' }} whitespace-nowrap">
                                                            {!! $this->formatValue($item, $col) !!}
                                                        </td>
                                                    @endforeach
                                                </tr>
                                            @empty
                                                <tr>
                                                    <td colspan="{{ count($relationConfig['columns']) }}" class="px-3 py-6 text-center text-sm text-gray-400">
                                                        No items.
                                                    </td>
                                                </tr>
                                            @endforelse
                                        </tbody>
                                    </table>
                                </div>
                            </div>
                        @endif
                    @endif
                </div>

                {{-- Footer --}}
                <div class="flex justify-end gap-2 border-t border-gray-100 px-6 py-4 sticky bottom-0 bg-white">
                    <button
                        type="button"
                        wire:click="close"
                        class="rounded-lg border border-gray-300 px-4 py-2 text-sm font-medium text-gray-700 hover:bg-gray-50"
                    >
                        Close
                    </button>
                </div>
            </div>
        </div>
    @endif
</div>