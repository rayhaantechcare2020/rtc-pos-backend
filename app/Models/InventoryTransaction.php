<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class InventoryTransaction extends Model
{
    protected $fillable = [
        'company_id',
        'product_id',
        'user_id',
        'type',
        'quantity',
        'before_quantity',
        'after_quantity',
        'reference_type',
        'reference_id',
        'notes',
    ];

    /**
     * If using polymorphic reference relations, you can define it like this:
     */
    public function reference()
    {
        return $this->morphTo();
    }
}
