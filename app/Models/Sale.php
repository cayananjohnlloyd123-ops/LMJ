<?php

namespace App\Models;

use App\Models\Scopes\SaleListScope;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class Sale extends Model
{
    use HasFactory;

    protected $table = 'sales';

    protected $fillable = [
        'invoice_no',
        'total_amount',
        'user_id',
    ];

    protected $casts = [
        'total_amount' => 'decimal:2',
        'user_id'      => 'integer',
    ];

    protected static function booted(): void
    {
        static::addGlobalScope(new SaleListScope);
    }

    /**
     * Sale Items
     */
    public function items()
    {
        return $this->hasMany(SaleItem::class, 'sale_id');
    }

    /**
     * Cashier/User
     */
    public function user()
    {
        return $this->belongsTo(User::class);
    }
}