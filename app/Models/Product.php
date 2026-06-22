<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class Product extends Model
{
    use HasFactory, SoftDeletes;

    protected $table = 'products';

    protected $fillable = [
        'sku',
        'name',
        'cost_price',
        'selling_price',
        'stock',
    ];

    protected $casts = [
        'cost_price'   => 'decimal:2',
        'selling_price'=> 'decimal:2',
        'stock'        => 'integer',
    ];

    /**
     * Sale Items
     */
    public function saleItems()
    {
        return $this->hasMany(SaleItem::class, 'product_id');
    }

    /**
     * Current Profit Per Unit
     */
    public function getProfitAttribute()
    {
        return $this->selling_price - $this->cost_price;
    }
}