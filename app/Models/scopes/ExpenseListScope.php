<?php

namespace App\Models\Scopes;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Scope;

/**
 * Adds two read-only "virtual" columns to every Expense query:
 *
 *   - category_name : expense_categories.name (via LEFT JOIN)
 *   - creator_name   : users.name of the user who logged the expense (via LEFT JOIN)
 *
 * This lets the generic <livewire:data-table> component treat them as
 * plain columns for display, sorting, and search — without needing to
 * know about relationships. Mirrors App\Models\Scopes\SaleListScope.
 */
class ExpenseListScope implements Scope
{
    public function apply(Builder $builder, Model $model): void
    {
        $builder
            ->select('expenses.*')
            ->leftJoin('expense_categories', 'expense_categories.id', '=', 'expenses.expense_category_id')
            ->addSelect('expense_categories.name as category_name')
            ->leftJoin('users', 'users.id', '=', 'expenses.created_by')
            ->addSelect('users.name as creator_name');
    }
}