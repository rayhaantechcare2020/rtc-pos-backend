<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class PurchaseOrderItem extends Model
{
    use HasFactory;

    protected $fillable = [
        'purchase_order_id',
        'product_id',
        'quantity',
        'received_quantity',
        'backordered_quantity',
        'unit_cost',
        'discount',
        'tax',
        'total',
        'expected_delivery_date',
        'notes'
    ];

    protected $casts = [
        'expected_delivery_date' => 'date',
        'unit_cost' => 'decimal:2',
        'discount' => 'decimal:2',
        'tax' => 'decimal:2',
        'total' => 'decimal:2'
    ];

    // Relationships
    public function purchaseOrder()
    {
        return $this->belongsTo(PurchaseOrder::class);
    }

    public function product()
    {
        return $this->belongsTo(Product::class);
    }

    // Boot method to auto-calculate total
    protected static function boot()
    {
        parent::boot();

        static::creating(function ($item) {
            $item->total = ($item->unit_cost * $item->quantity) - $item->discount + $item->tax;
        });

        static::updating(function ($item) {
            $item->total = ($item->unit_cost * $item->quantity) - $item->discount + $item->tax;
        });
    }
}