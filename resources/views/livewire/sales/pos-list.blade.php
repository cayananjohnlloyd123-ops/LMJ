<?php

use Livewire\Volt\Component;
use App\Models\Product;
use App\Models\Sale;
use App\Models\SaleItem;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;

new class extends Component {
    public string $search = '';

    // cart: [product_id => ['name' => ..., 'price' => ..., 'qty' => ..., 'stock' => ..., 'sku' => ...]]
    public array $cart = [];

    public string $cashReceived = '';

    public function with(): array
    {
        $products = Product::query()
            ->when($this->search !== '', function ($q) {
                $q->where(function ($q) {
                    $q->where('name', 'like', '%' . $this->search . '%')
                      ->orWhere('sku', 'like', '%' . $this->search . '%');
                });
            })
            ->orderBy('name')
            ->get();

        return [
            'products' => $products,
        ];
    }

    /**
     * Add a product to the cart (click row).
     */
    public function addToCart(int $productId): void
    {
        $product = Product::find($productId);

        if (! $product) {
            $this->dispatch('notify', message: 'Product not found.', type: 'error');
            return;
        }

        $currentQtyInCart = $this->cart[$productId]['qty'] ?? 0;

        if ($product->stock <= $currentQtyInCart) {
            $this->dispatch('notify', message: "Insufficient stock for {$product->name}.", type: 'error');
            return;
        }

        if (isset($this->cart[$productId])) {
            $this->cart[$productId]['qty']++;
        } else {
            $this->cart[$productId] = [
                'name'  => $product->name,
                'sku'   => $product->sku,
                'price' => (float) $product->selling_price,
                'qty'   => 1,
                'stock' => $product->stock,
            ];
        }

        $this->dispatch('notify', message: "{$product->name} added to cart.");
    }

    public function incrementQty(int $productId): void
    {
        if (! isset($this->cart[$productId])) {
            return;
        }

        $product = Product::find($productId);

        if (! $product || $this->cart[$productId]['qty'] >= $product->stock) {
            $this->dispatch('notify', message: 'Insufficient stock.', type: 'error');
            return;
        }

        $this->cart[$productId]['qty']++;
    }

    public function decrementQty(int $productId): void
    {
        if (! isset($this->cart[$productId])) {
            return;
        }

        $this->cart[$productId]['qty']--;

        if ($this->cart[$productId]['qty'] <= 0) {
            unset($this->cart[$productId]);
        }
    }

    public function updateQty(int $productId, $value): void
    {
        if (! isset($this->cart[$productId])) {
            return;
        }

        $qty = max(0, (int) $value);
        $product = Product::find($productId);

        if ($product && $qty > $product->stock) {
            $qty = $product->stock;
            $this->dispatch('notify', message: 'Quantity capped at available stock.', type: 'error');
        }

        if ($qty <= 0) {
            unset($this->cart[$productId]);
            return;
        }

        $this->cart[$productId]['qty'] = $qty;
    }

    public function removeFromCart(int $productId): void
    {
        unset($this->cart[$productId]);
    }

    public function clearCart(): void
    {
        $this->cart = [];
        $this->cashReceived = '';
    }

    public function getSubtotalProperty(): float
    {
        return collect($this->cart)->sum(fn ($item) => $item['price'] * $item['qty']);
    }

    public function getChangeProperty(): float
    {
        $cash = (float) ($this->cashReceived ?: 0);
        return max(0, $cash - $this->subtotal);
    }

    public function getItemCountProperty(): int
    {
        return collect($this->cart)->sum('qty');
    }

    /**
     * Finalize the sale.
     */
    public function checkout(): void
    {
        if (empty($this->cart)) {
            $this->dispatch('notify', message: 'Cart is empty.', type: 'error');
            return;
        }

        $cash = (float) ($this->cashReceived ?: 0);

        if ($cash < $this->subtotal) {
            $this->dispatch('notify', message: 'Insufficient cash received.', type: 'error');
            return;
        }

        try {
            DB::transaction(function () {
                // Lock and re-validate stock at checkout time
                $sale = Sale::create([
                    'invoice_no'   => 'INV-' . now()->format('Ymd') . '-' . strtoupper(Str::random(6)),
                    'total_amount' => $this->subtotal,
                    'user_id'      => Auth::id(),
                ]);

                foreach ($this->cart as $productId => $item) {
                    $product = Product::where('id', $productId)->lockForUpdate()->first();

                    if (! $product || $product->stock < $item['qty']) {
                        throw new \RuntimeException("Insufficient stock for {$item['name']}.");
                    }

                    SaleItem::create([
                        'sale_id'    => $sale->id,
                        'product_id' => $productId,
                        'qty'        => $item['qty'],
                        'price'      => $item['price'],
                        'subtotal'   => $item['price'] * $item['qty'],
                    ]);

                    $product->decrement('stock', $item['qty']);
                }

                $this->dispatch('notify', message: "Sale completed: {$sale->invoice_no}");
            });

            $this->cart = [];
            $this->cashReceived = '';
        } catch (\RuntimeException $e) {
            $this->dispatch('notify', message: $e->getMessage(), type: 'error');
        } catch (\Throwable $e) {
            report($e);
            $this->dispatch('notify', message: 'Checkout failed. Please try again.', type: 'error');
        }
    }
}; ?>

<div
    class="p-6"
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
        class="mb-4 rounded-lg border px-4 py-3 text-sm flex items-center gap-2"
    >
        <svg x-show="type !== 'error'" class="w-4 h-4 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7" /></svg>
        <svg x-show="type === 'error'" class="w-4 h-4 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" /></svg>
        <span x-text="message"></span>
    </div>

    <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">

        {{-- LEFT: Product List --}}
        <div class="lg:col-span-2 bg-white rounded-xl border border-gray-200 shadow-sm overflow-hidden">
            <div class="p-4 border-b border-gray-100 bg-gray-50/60">
                <div class="relative">
                    <svg class="w-4 h-4 absolute left-3 top-1/2 -translate-y-1/2 text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-4.35-4.35M17 11a6 6 0 11-12 0 6 6 0 0112 0z" /></svg>
                    <input
                        type="text"
                        wire:model.live.debounce.300ms="search"
                        placeholder="Search by product name or SKU..."
                        class="w-full pl-9 pr-3 py-2.5 rounded-lg border-gray-300 text-sm focus:border-indigo-500 focus:ring-indigo-500"
                    >
                </div>
            </div>

            <div class="overflow-x-auto max-h-[65vh] overflow-y-auto">
                <table class="min-w-full divide-y divide-gray-100">
                    <thead class="bg-gray-50 sticky top-0 z-10">
                        <tr>
                            <th class="px-4 py-3 text-left text-xs font-semibold text-gray-500 uppercase tracking-wide">SKU</th>
                            <th class="px-4 py-3 text-left text-xs font-semibold text-gray-500 uppercase tracking-wide">Product</th>
                            <th class="px-4 py-3 text-right text-xs font-semibold text-gray-500 uppercase tracking-wide">Price</th>
                            <th class="px-4 py-3 text-center text-xs font-semibold text-gray-500 uppercase tracking-wide">Stock</th>
                            <th class="px-4 py-3"></th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-50">
                        @forelse ($products as $product)
                            @php
                                $inCartQty = $cart[$product->id]['qty'] ?? 0;
                                $outOfStock = $product->stock <= $inCartQty;
                            @endphp
                            <tr
                                wire:key="product-{{ $product->id }}"
                                wire:click="addToCart({{ $product->id }})"
                                @class([
                                    'transition cursor-pointer',
                                    'hover:bg-indigo-50/60' => ! $outOfStock,
                                    'opacity-40 cursor-not-allowed' => $outOfStock,
                                ])
                            >
                                <td class="px-4 py-3 text-xs text-gray-500 font-mono">{{ $product->sku }}</td>
                                <td class="px-4 py-3 text-sm font-medium text-gray-800">
                                    {{ $product->name }}
                                    @if ($inCartQty > 0)
                                        <span class="ml-1.5 inline-flex items-center px-1.5 py-0.5 rounded-full text-[10px] font-semibold bg-indigo-100 text-indigo-700">
                                            {{ $inCartQty }} in cart
                                        </span>
                                    @endif
                                </td>
                                <td class="px-4 py-3 text-sm text-right text-gray-700">₱{{ number_format($product->selling_price, 2) }}</td>
                                <td class="px-4 py-3 text-center">
                                    <span @class([
                                        'inline-flex px-2 py-0.5 rounded-full text-xs font-medium',
                                        'bg-red-100 text-red-700' => $product->stock <= 5,
                                        'bg-amber-100 text-amber-700' => $product->stock > 5 && $product->stock <= 15,
                                        'bg-green-100 text-green-700' => $product->stock > 15,
                                    ])>
                                        {{ $product->stock }}
                                    </span>
                                </td>
                                <td class="px-4 py-3 text-right">
                                    <span class="text-indigo-600">
                                        <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v6m3-3H9m12 0a9 9 0 11-18 0 9 9 0 0118 0z" /></svg>
                                    </span>
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="5" class="px-4 py-10 text-center text-sm text-gray-400">
                                    No products found.
                                </td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>

        {{-- RIGHT: Cart --}}
        <div class="bg-white rounded-xl border border-gray-200 shadow-sm flex flex-col h-fit lg:sticky lg:top-6">
            <div class="p-4 border-b border-gray-100 flex items-center justify-between bg-gray-50/60 rounded-t-xl">
                <h3 class="font-semibold text-gray-800 flex items-center gap-2">
                    <svg class="w-5 h-5 text-indigo-600" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 3h2l.4 2M7 13h10l4-8H5.4M7 13L5.4 5M7 13l-2.293 2.293c-.63.63-.184 1.707.707 1.707H17m0 0a2 2 0 100 4 2 2 0 000-4zm-8 2a2 2 0 11-4 0 2 2 0 014 0z" /></svg>
                    Cart
                    @if ($this->itemCount > 0)
                        <span class="text-xs font-medium text-gray-400">({{ $this->itemCount }} items)</span>
                    @endif
                </h3>
                @if (count($cart) > 0)
                    <button wire:click="clearCart" class="text-xs text-red-500 hover:text-red-700 font-medium">
                        Clear
                    </button>
                @endif
            </div>

            <div class="p-4 space-y-3 max-h-[40vh] overflow-y-auto">
                @forelse ($cart as $productId => $item)
                    <div wire:key="cart-{{ $productId }}" class="flex items-start justify-between gap-2 pb-3 border-b border-gray-50 last:border-0 last:pb-0">
                        <div class="flex-1 min-w-0">
                            <p class="text-sm font-medium text-gray-800 truncate">{{ $item['name'] }}</p>
                            <p class="text-xs text-gray-400">₱{{ number_format($item['price'], 2) }} each</p>

                            <div class="flex items-center gap-1.5 mt-1.5">
                                <button
                                    wire:click="decrementQty({{ $productId }})"
                                    class="w-6 h-6 flex items-center justify-center rounded-md border border-gray-200 text-gray-500 hover:bg-gray-50 text-sm leading-none"
                                >−</button>
                                <input
                                    type="number"
                                    min="0"
                                    value="{{ $item['qty'] }}"
                                    wire:change="updateQty({{ $productId }}, $event.target.value)"
                                    class="w-12 text-center text-sm py-1 rounded-md border-gray-200"
                                >
                                <button
                                    wire:click="incrementQty({{ $productId }})"
                                    class="w-6 h-6 flex items-center justify-center rounded-md border border-gray-200 text-gray-500 hover:bg-gray-50 text-sm leading-none"
                                >+</button>
                            </div>
                        </div>

                        <div class="text-right shrink-0">
                            <p class="text-sm font-semibold text-gray-800">
                                ₱{{ number_format($item['price'] * $item['qty'], 2) }}
                            </p>
                            <button
                                wire:click="removeFromCart({{ $productId }})"
                                class="mt-1 text-gray-300 hover:text-red-500"
                            >
                                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16" /></svg>
                            </button>
                        </div>
                    </div>
                @empty
                    <div class="py-10 text-center">
                        <svg class="w-10 h-10 mx-auto text-gray-200 mb-2" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M3 3h2l.4 2M7 13h10l4-8H5.4M7 13L5.4 5M7 13l-2.293 2.293c-.63.63-.184 1.707.707 1.707H17m0 0a2 2 0 100 4 2 2 0 000-4zm-8 2a2 2 0 11-4 0 2 2 0 014 0z" /></svg>
                        <p class="text-sm text-gray-400">Cart is empty</p>
                        <p class="text-xs text-gray-300 mt-1">Click a product to add it</p>
                    </div>
                @endforelse
            </div>

            {{-- Totals & Checkout --}}
            <div class="p-4 border-t border-gray-100 bg-gray-50/60 rounded-b-xl space-y-3">
                <div class="flex justify-between text-sm">
                    <span class="text-gray-500">Subtotal</span>
                    <span class="font-medium text-gray-800">₱{{ number_format($this->subtotal, 2) }}</span>
                </div>
                <div class="flex justify-between text-base font-bold">
                    <span class="text-gray-800">Total</span>
                    <span class="text-indigo-600">₱{{ number_format($this->subtotal, 2) }}</span>
                </div>

                <div>
                    <label class="text-xs font-medium text-gray-500 mb-1 block">Cash Received</label>
                    <input
                        type="number"
                        step="0.01"
                        min="0"
                        wire:model.live="cashReceived"
                        placeholder="0.00"
                        class="w-full rounded-lg border-gray-300 text-sm focus:border-indigo-500 focus:ring-indigo-500"
                    >
                </div>

                <div class="flex justify-between text-sm">
                    <span class="text-gray-500">Change</span>
                    <span class="font-semibold text-gray-800">₱{{ number_format($this->change, 2) }}</span>
                </div>

                <button
                    wire:click="checkout"
                    wire:loading.attr="disabled"
                    wire:target="checkout"
                    @disabled(empty($cart))
                    class="w-full py-2.5 rounded-lg bg-indigo-600 text-white text-sm font-semibold hover:bg-indigo-700 transition disabled:opacity-40 disabled:cursor-not-allowed flex items-center justify-center gap-2"
                >
                    <span wire:loading.remove wire:target="checkout">Complete Sale</span>
                    <span wire:loading wire:target="checkout">Processing...</span>
                </button>
            </div>
        </div>
    </div>
</div>