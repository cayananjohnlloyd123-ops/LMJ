<?php

namespace App\Models\Scopes;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletingScope;
use Illuminate\Database\Eloquent\Scope;
use Illuminate\Support\Facades\DB;

/**
 * Adds two read-only "virtual" columns to every Sale query:
 *
 *   - cashier_name : users.name of the sale's cashier (via LEFT JOIN)
 *   - items_count  : count of related sale_items (via correlated subquery)
 *
 * This lets the generic <livewire:data-table> component treat them as
 * plain columns for display, sorting, and search — without needing to
 * know about relationships.
 */
class SaleListScope implements Scope
{
    public function apply(Builder $builder, Model $model): void
    {
        $builder
            ->select('sales.*')
            ->leftJoin('users', 'users.id', '=', 'sales.user_id')
            ->addSelect('users.name as cashier_name')
            ->addSelect([
                'items_count' => DB::table('sale_items')
                    ->selectRaw('count(*)')
                    ->whereColumn('sale_items.sale_id', 'sales.id'),
            ]);
    }
}