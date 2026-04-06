<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Payment extends Model
{
    use HasFactory;

    protected $fillable = [
        'company_id',
        'user_id',
        'customer_id',
        'sale_id',          
        'amount',
        'bank_id',
        'transaction_reference',
        'processed_by',
        'method',
        'reference',
        'payment_date',
        'notes',
        'status'
    ];

    protected $casts = [
        'amount' => 'decimal:2',
        'payment_date' => 'date'
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

    public function sale()
    {
        return $this->belongsTo(Sale::class);  // Added relationship
    }

    // Add a helper to get customer name even if relationship fails
    public function getCustomerNameAttribute()
    {
    if ($this->customer) {
        return $this->customer->name;
    }
    
    if ($this->sale && $this->sale->customer) {
        return $this->sale->customer->name;
    }
    
    return 'Unknown Customer';
}
    public function bank()
    {
        return $this->belongsTo(Bank::class, 'bank_id');
    }

// Scope for bank payments
    public function scopeBank($query)
    {
        return $query->where('method', 'bank');
    }

    // Scope for cash payments
    public function scopeCash($query)
    {
        return $query->where('method', 'cash');
    }

    // Scope for POS payments
    public function scopePos($query)
    {
        return $query->where('method', 'pos');
    }
}