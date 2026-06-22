<?php

namespace App\Models;

use App\Models\Scopes\ExpenseListScope;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class Expense extends Model
{
    use HasFactory;

    protected $table = 'expenses';

    protected $fillable = [
        'expense_category_id',
        'expense_date',
        'description',
        'amount',
        'attachment',
        'created_by',
    ];

    protected $casts = [
        'expense_date' => 'date',
        'amount'       => 'decimal:2',
        'created_by'   => 'integer',
    ];

    protected static function booted(): void
    {
        static::addGlobalScope(new ExpenseListScope);
    }

    /**
     * Category
     */
    public function category()
    {
        return $this->belongsTo(
            ExpenseCategory::class,
            'expense_category_id'
        );
    }

    /**
     * Creator
     */
    public function creator()
    {
        return $this->belongsTo(
            User::class,
            'created_by'
        );
    }

    /**
     * Scope: Today
     */
    public function scopeToday($query)
    {
        return $query->whereDate('expense_date', today());
    }

    /**
     * Scope: This Month
     */
    public function scopeThisMonth($query)
    {
        return $query->whereMonth('expense_date', now()->month)
            ->whereYear('expense_date', now()->year);
    }
}