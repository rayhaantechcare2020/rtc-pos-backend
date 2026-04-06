<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class HoldSale extends Model
{
    protected $table = 'hold_sales';
    
    protected $fillable = [
        'company_id',
        'user_id',
        'customer_id',
        'hold_reference',
        'customer_name',
        'customer_phone',
        'cart_items',
        'subtotal',
        'discount',
        'total',
        'notes',
        'held_at',
        'expires_at',
        'status'
    ];

    protected $casts = [
        'cart_items' => 'array',
        'subtotal' => 'decimal:2',
        'discount' => 'decimal:2',
        'total' => 'decimal:2',
        'held_at' => 'datetime',
        'expires_at' => 'datetime'
    ];

    public function company()
    {
        return $this->belongsTo(Company::class);
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function customer()
    {
        return $this->belongsTo(Customer::class);
    }

    // Scope for active holds
    public function scopeActive($query)
    {
        return $query->where('status', 'active')
                     ->where(function($q) {
                         $q->whereNull('expires_at')
                           ->orWhere('expires_at', '>', now());
                     });
    }

    // Scope for expired holds
    public function scopeExpired($query)
    {
        return $query->where('status', 'active')
                     ->where('expires_at', '<=', now());
    }

    // Generate unique hold reference
    public static function generateHoldReference()
    {
        $prefix = 'HOLD';
        $date = now()->format('Ymd');
        $random = strtoupper(substr(uniqid(), -6));
        $reference = $prefix . '-' . $date . '-' . $random;
        
        // Ensure uniqueness
        while (self::where('hold_reference', $reference)->exists()) {
            $random = strtoupper(substr(uniqid(), -6));
            $reference = $prefix . '-' . $date . '-' . $random;
        }
        
        return $reference;
    }
}