<?php

use Livewire\Volt\Component;
use Livewire\WithPagination;
use Livewire\Attributes\Url;
use Livewire\Attributes\On;

new class extends Component {
    use WithPagination;

    // --- Required props ---
    public string $model      = '';   // e.g. App\Models\Product
    public array  $columns    = [];   // column definitions

    // --- Optional props ---
    public array  $searchable  = [];
    public array  $rowActions  = [];
    public bool   $bulkActions = false;
    public int    $perPage     = 10;

    // --- Internal state ---
    #[Url]
    public string $search = '';

    public string $sortColumn    = '';
    public string $sortDirection = 'asc';
    public array  $selected      = [];
    public bool   $selectAll     = false;

    // Dropdown open state now lives in Livewire (server-authoritative) rather
    // than purely in Alpine, so it survives wire:click round-trips / morphs
    // without desyncing from a teleported node. See triggerAction()/toggleDropdown().
    public ?int   $openDropdownId = null;

    /*
    |--------------------------------------------------------------------------
    | Supported format types:
    |
    |   'currency'        → number_format(value, 2)
    |   'integer'         → number_format(value, 0)
    |   'date'            → d M Y
    |   'datetime'        → d M Y H:i
    |   'badge:key=label' → colored pill; key is the row attribute that drives
    |                       color, label is the attribute to display
    |                       e.g. 'badge:status=status_label'
    |   'profit'          → green if >= 0, red if < 0 (currency)
    |   'boolean'         → Yes / No pill
    |--------------------------------------------------------------------------
    */
    public function formatCell(mixed $row, array $col): string
    {
        $value = data_get($row, $col['key']);
        $type  = $col['format'] ?? null;

        if (! $type) {
            return e($value ?? '');
        }

        // --- currency ---
        if ($type === 'currency') {
            return e(number_format((float) $value, 2));
        }

        // --- integer ---
        if ($type === 'integer') {
            return e(number_format((int) $value));
        }

        // --- date ---
        if ($type === 'date') {
            return $value
                ? e(\Carbon\Carbon::parse($value)->format('d M Y'))
                : '';
        }

        // --- datetime ---
        if ($type === 'datetime') {
            return $value
                ? e(\Carbon\Carbon::parse($value)->format('d M Y H:i'))
                : '';
        }

        // --- boolean ---
        if ($type === 'boolean') {
            return $value
                ? '<span class="inline-flex items-center rounded-full bg-green-100 px-2 py-0.5 text-xs font-medium text-green-700">Yes</span>'
                : '<span class="inline-flex items-center rounded-full bg-gray-100 px-2 py-0.5 text-xs font-medium text-gray-500">No</span>';
        }

        // --- profit ---
        if ($type === 'profit') {
            $formatted = number_format((float) $value, 2);
            $class     = ((float) $value) >= 0 ? 'text-green-600' : 'text-red-600';
            return '<span class="' . $class . '">' . e($formatted) . '</span>';
        }

        // --- badge:colorKey=labelKey ---
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

    // --- Lifecycle ---

    public function mount(): void
    {
        foreach ($this->columns as $col) {
            if ($col['sortable'] ?? false) {
                $this->sortColumn = $col['key'];
                break;
            }
        }
    }

    public function updatingSearch(): void
    {
        $this->resetPage();
        $this->selected      = [];
        $this->selectAll     = false;
        $this->openDropdownId = null;
    }

    public function sort(string $column): void
    {
        if ($this->sortColumn === $column) {
            $this->sortDirection = $this->sortDirection === 'asc' ? 'desc' : 'asc';
        } else {
            $this->sortColumn    = $column;
            $this->sortDirection = 'asc';
        }

        $this->openDropdownId = null;
        $this->resetPage();
    }

    public function toggleSelectAll(): void
    {
        $this->selected = $this->selectAll
            ? $this->rows->pluck('id')->map(fn($id) => (string) $id)->toArray()
            : [];
    }

    /**
     * Toggle a row's action dropdown. Server-authoritative: closes any other
     * open dropdown automatically since only one id is ever stored.
     */
    public function toggleDropdown(int $id): void
    {
        $this->openDropdownId = $this->openDropdownId === $id ? null : $id;
    }

    public function closeDropdown(): void
    {
        $this->openDropdownId = null;
    }

    public function triggerAction(string $event, int $rowId): void
    {
        $this->openDropdownId = null;
        $this->dispatch($event, id: $rowId);
    }

    public function triggerBulkAction(string $event): void
    {
        $this->dispatch($event, ids: $this->selected);
        $this->selected  = [];
        $this->selectAll = false;
    }

    public function nextPage($pageName = 'page'): void
    {
        $this->openDropdownId = null;
        $this->resetPage($pageName);
        parent::nextPage($pageName);
    }

    public function previousPage($pageName = 'page'): void
    {
        $this->openDropdownId = null;
        parent::previousPage($pageName);
    }

    public function gotoPage($page, $pageName = 'page'): void
    {
        $this->openDropdownId = null;
        parent::gotoPage($page, $pageName);
    }

    // Listens for: $this->dispatch('table-refresh'); (or ->to('data-tables'))
    // No body needed — just being called forces Livewire to re-run with()
    // and re-render the component with fresh data from the DB.
    #[On('table-refresh')]
    public function refreshTable(): void
    {
        //
    }

    public function with(): array
    {
        $modelClass = $this->model;

        $rows = $modelClass::query()
            ->when($this->search && count($this->searchable), function ($query) {
                $query->where(function ($q) {
                    foreach ($this->searchable as $i => $col) {
                        $method = $i === 0 ? 'where' : 'orWhere';
                        $q->{$method}($col, 'like', '%' . $this->search . '%');
                    }
                });
            })
            ->when($this->sortColumn, fn($q) => $q->orderBy($this->sortColumn, $this->sortDirection))
            ->paginate($this->perPage);

        return ['rows' => $rows];
    }
}; ?>

<div
    x-data="{ dropdownX: 0, dropdownY: 0 }"
    @keydown.escape.window="$wire.closeDropdown()"
    @scroll.window="$wire.closeDropdown()"
    class="space-y-4"
>
    {{-- Toolbar --}}
    <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-3">

        @if (count($searchable))
            <div class="relative w-full sm:w-64">
                <svg class="absolute left-3 top-1/2 -translate-y-1/2 w-4 h-4 text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-4.35-4.35M17 11a6 6 0 11-12 0 6 6 0 0112 0z" />
                </svg>
                <input
                    type="text"
                    wire:model.live.debounce.400ms="search"
                    placeholder="Search..."
                    class="w-full rounded-lg border-gray-300 pl-9 pr-3 py-2 text-sm focus:border-indigo-500 focus:ring-indigo-500"
                >
            </div>
        @endif

        @if ($bulkActions && count($selected))
            <div class="flex items-center gap-2">
                <span class="text-sm text-gray-500">{{ count($selected) }} selected</span>
                {{ $slot ?? '' }}
            </div>
        @else
            <div>{{ $slot ?? '' }}</div>
        @endif

    </div>

    {{-- Table --}}
    <div class="overflow-x-auto rounded-lg border border-gray-200">
        <table class="min-w-full divide-y divide-gray-200">
            <thead class="bg-gray-50">
                <tr>
                    @if ($bulkActions)
                        <th class="px-4 py-3 w-8">
                            <input
                                type="checkbox"
                                wire:model.live="selectAll"
                                wire:change="toggleSelectAll"
                                class="rounded border-gray-300 text-indigo-600 focus:ring-indigo-500"
                            >
                        </th>
                    @endif

                    @foreach ($columns as $col)
                        <th class="px-4 py-3 text-{{ $col['align'] ?? 'left' }} text-xs font-medium text-gray-500 uppercase tracking-wider">
                            @if ($col['sortable'] ?? false)
                                <button
                                    type="button"
                                    wire:click="sort('{{ $col['key'] }}')"
                                    class="inline-flex items-center gap-1 hover:text-gray-700"
                                >
                                    {{ $col['label'] }}
                                    <span class="text-gray-400">
                                        @if ($sortColumn === $col['key'])
                                            @if ($sortDirection === 'asc')
                                                <svg class="w-3 h-3" fill="currentColor" viewBox="0 0 20 20"><path fill-rule="evenodd" d="M5.293 7.293a1 1 0 011.414 0L10 10.586l3.293-3.293a1 1 0 111.414 1.414l-4 4a1 1 0 01-1.414 0l-4-4a1 1 0 010-1.414z" clip-rule="evenodd"/></svg>
                                            @else
                                                <svg class="w-3 h-3" fill="currentColor" viewBox="0 0 20 20"><path fill-rule="evenodd" d="M14.707 12.707a1 1 0 01-1.414 0L10 9.414l-3.293 3.293a1 1 0 01-1.414-1.414l4-4a1 1 0 011.414 0l4 4a1 1 0 010 1.414z" clip-rule="evenodd"/></svg>
                                            @endif
                                        @else
                                            <svg class="w-3 h-3 opacity-30" fill="currentColor" viewBox="0 0 20 20"><path fill-rule="evenodd" d="M5.293 7.293a1 1 0 011.414 0L10 10.586l3.293-3.293a1 1 0 111.414 1.414l-4 4a1 1 0 01-1.414 0l-4-4a1 1 0 010-1.414z" clip-rule="evenodd"/></svg>
                                        @endif
                                    </span>
                                </button>
                            @else
                                {{ $col['label'] }}
                            @endif
                        </th>
                    @endforeach

                    @if (count($rowActions))
                        <th class="px-4 py-3 w-12"></th>
                    @endif
                </tr>
            </thead>
            <tbody class="bg-white divide-y divide-gray-200">
                @forelse ($rows as $row)
                    <tr wire:key="row-{{ $row->id }}" class="hover:bg-gray-50">

                        @if ($bulkActions)
                            <td class="px-4 py-3">
                                <input
                                    type="checkbox"
                                    wire:model.live="selected"
                                    value="{{ $row->id }}"
                                    class="rounded border-gray-300 text-indigo-600 focus:ring-indigo-500"
                                >
                            </td>
                        @endif

                        @foreach ($columns as $col)
                            <td class="px-4 py-3 text-sm text-gray-700 text-{{ $col['align'] ?? 'left' }} whitespace-nowrap">
                                {!! $this->formatCell($row, $col) !!}
                            </td>
                        @endforeach

                        @if (count($rowActions))
                            <td class="px-4 py-3 text-right" wire:key="actions-cell-{{ $row->id }}">
                                <button
                                    type="button"
                                    x-ref="trigger-{{ $row->id }}"
                                    @click.stop="
                                        const rect = $refs['trigger-{{ $row->id }}'].getBoundingClientRect();
                                        dropdownX = rect.right - 144 + window.scrollX;
                                        dropdownY = rect.bottom + 4 + window.scrollY;
                                        $wire.toggleDropdown({{ $row->id }});
                                    "
                                    class="inline-flex items-center justify-center w-8 h-8 rounded-full text-gray-500 hover:bg-gray-100"
                                >
                                    <svg class="w-5 h-5" fill="currentColor" viewBox="0 0 20 20">
                                        <path d="M10 6a2 2 0 110-4 2 2 0 010 4zM10 12a2 2 0 110-4 2 2 0 010 4zM10 18a2 2 0 110-4 2 2 0 010 4z" />
                                    </svg>
                                </button>

                                @if ($openDropdownId === $row->id)
                                    {{-- Teleported dropdown: renders at <body> level so it's never
                                         clipped by the table's overflow or buried by sibling stacking
                                         contexts. Wrapped in a plain div per Livewire/Alpine guidance
                                         for x-teleport + wire:click morph stability, and gated by a
                                         server-side @if (not just x-show) so it never lingers as an
                                         orphaned node after sort/page/search re-renders. --}}
                                    <template x-teleport="body">
                                        <div
                                            wire:key="dropdown-{{ $row->id }}"
                                            x-data
                                            x-init="$nextTick(() => {})"
                                            @click.stop
                                            @click.away="$wire.closeDropdown()"
                                            :style="`position:absolute; left:${dropdownX}px; top:${dropdownY}px;`"
                                            class="z-[9999] w-36 rounded-lg bg-white shadow-lg ring-1 ring-black/5 py-1"
                                        >
                                            @foreach ($rowActions as $action)
                                                <button
                                                    type="button"
                                                    wire:click="triggerAction('{{ $action['event'] }}', {{ $row->id }})"
                                                    class="flex w-full items-center gap-2 px-3 py-2 text-sm {{ ($action['danger'] ?? false) ? 'text-red-600 hover:bg-red-50' : 'text-gray-700 hover:bg-gray-50' }}"
                                                >
                                                    @if (isset($action['icon']))
                                                        {!! $action['icon'] !!}
                                                    @endif
                                                    {{ $action['label'] }}
                                                </button>
                                            @endforeach
                                        </div>
                                    </template>
                                @endif
                            </td>
                        @endif

                    </tr>
                @empty
                    <tr>
                        <td
                            colspan="{{ count($columns) + ($bulkActions ? 1 : 0) + (count($rowActions) ? 1 : 0) }}"
                            class="px-4 py-10 text-center text-sm text-gray-500"
                        >
                            No records found.
                        </td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>

    {{-- Pagination --}}
    <div>
        {{ $rows->links() }}
    </div>
</div>