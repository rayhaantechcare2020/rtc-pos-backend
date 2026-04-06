<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class DirectReceiveItem extends Model
{
    use HasFactory;

    protected $fillable = [
        'direct_receive_id',
        'product_id',
        'quantity',
        'unit_cost',
        'discount',
        'tax',
        'total',
        'product_name',
        'product_sku',
        'expiry_date',
        'notes'
    ];

    protected $casts = [
        'expiry_date' => 'date',
        'unit_cost' => 'decimal:2',
        'discount' => 'decimal:2',
        'tax' => 'decimal:2',
        'total' => 'decimal:2'
    ];

    public function directReceive()
    {
        return $this->belongsTo(DirectReceive::class);
    }

    public function product()
    {
        return $this->belongsTo(Product::class);
    }
}