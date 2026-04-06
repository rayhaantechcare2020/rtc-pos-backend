<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class DirectReceive extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'company_id',
        'vendor_id',
        'user_id',
        'reference_number',
        'receive_date',
        'vendor_name',
        'vendor_phone',
        'waybill_number',
        'truck_number',
        'driver_name',
        'driver_phone',
        'subtotal',
        'tax',
        'discount',
        'total',
        'payment_status',
        'payment_method',
        'notes'
    ];

    protected $casts = [
        'receive_date' => 'date',
        'subtotal' => 'decimal:2',
        'tax' => 'decimal:2',
        'discount' => 'decimal:2',
        'total' => 'decimal:2'
    ];

    /**
     * Scope a query to only include records for a specific company
     */
    public function scopeForCompany($query, $companyId)
    {
        return $query->where('company_id', $companyId);
    }

    public function company()
    {
        return $this->belongsTo(Company::class);
    }

    public function vendor()
    {
        return $this->belongsTo(Vendor::class);
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function items()
    {
        return $this->hasMany(DirectReceiveItem::class);
    }

    public function inventoryTransactions()
    {
        return $this->morphMany(InventoryTransaction::class, 'reference');
    }

    protected static function boot()
    {
        parent::boot();
        
        static::creating(function ($receive) {
            // Generate reference number: DR-YYYYMMDD-XXXX
            $date = now()->format('Ymd');
            $lastReceive = self::whereDate('created_at', today())->count();
            $receive->reference_number = 'DR-' . $date . '-' . str_pad($lastReceive + 1, 4, '0', STR_PAD_LEFT);
        });
    }
}