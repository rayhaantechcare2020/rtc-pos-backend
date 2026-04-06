<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Sale extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'company_id',
        'user_id',
        'customer_id',
        'invoice_number',
        'sale_date',
        'sale_time',
        'item_count',
        'subtotal',
        'tax',
        'discount',
        'total',
        'amount_paid',
        'change_due',
        'payment_status',
        'status',
        'balance_due',
        'due_date',
        'notes',
        'bank_id',
        'transaction_reference',
        'deposit_slip',
        'paid_amount',
    ];

    protected $casts = [
        'sale_date' => 'date',
        'sale_time' => 'datetime',
        'due_date' => 'date',
        'subtotal' => 'decimal:2',
        'tax' => 'decimal:2',
        'discount' => 'decimal:2',
        'total' => 'decimal:2',
        'amount_paid' => 'decimal:2',
        'change_due' => 'decimal:2',
        'balance_due' => 'decimal:2'
    ];

    protected static function boot()
    {
        parent::boot();
        
        static::creating(function ($sale) {
            // Generate invoice number: INV-YYYYMMDD-XXXX
            $date = now()->format('Ymd');
            $lastSale = self::whereDate('created_at', today())->count();
            $sale->invoice_number = 'INV-' . $date . '-' . str_pad($lastSale + 1, 4, '0', STR_PAD_LEFT);
            $sale->sale_time = now();
        });
    }

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

    public function items()
    {
        return $this->hasMany(SaleItem::class);
    }

    public function payments()
    {
        return $this->hasMany(Payment::class);
    }

    public function bank()
    {
        return $this->belongsTo(Bank::class);
    }

    public function inventoryTransactions()
    {
        return $this->morphMany(InventoryTransaction::class, 'reference');
    }

    public function scopeForCompany($query, $companyId)
    {
        return $query->where('company_id', $companyId);
    }

    public function scopeToday($query)
    {
        return $query->whereDate('sale_date', today());
    }

    public function getProfitAttribute()
    {
        return $this->items->sum(function ($item) {
            return ($item->price - $item->cost) * $item->quantity;
        });
    }

    public function getBalanceDueAttribute($value)
    {
    // If the stored value is 0 but there's unpaid amount, calculate it
    if ($value == 0 && $this->total > $this->amount_paid) {
        return $this->total - $this->amount_paid;
    }
    return $value;
}
 public function getPaymentMethodDisplayAttribute()
    {
        if ($this->payment_method === 'cash') {
            return 'Cash';
        }
        return 'Bank Transfer';
    }

    // Accessor for bank details
    public function getBankDetailsAttribute()
    {
        if ($this->payment_method === 'bank' && $this->bank) {
            return [
                'name' => $this->bank->name,
                'account_name' => $this->bank->account_name,
                'account_number' => $this->bank->account_number
            ];
        }
        return null;
    }
    
    // Accessor to get payment method summary
    public function getPaymentMethodsAttribute()
    {
        return $this->payments->pluck('method')->unique()->implode(', ');
    }

    // Accessor to get bank payment details
    public function getBankPaymentsAttribute()
    {
        return $this->payments->where('method', 'bank')->load('bank');
    }
}