<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class ExpenseCategory extends Model
{
    use HasFactory;

    protected $table = 'expense_categories';

    protected $fillable = [
        'name',
    ];

    /**
     * Expenses
     */
    public function expenses()
    {
        return $this->hasMany(Expense::class, 'expense_category_id');
    }
}