<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Company extends Model
{
    use HasFactory;

    protected $fillable = [
        'name',
        'email',
        'phone',
        'address',
        'logo',
        'website',
        'tax_number',
        'registration_number',
        'currency',
        'currency_code',
        'timezone',
        'date_format',
        'settings',
        'status'
    ];

    protected $casts = [
        'settings' => 'array'
    ];

    /**
     * Get all users belonging to this company
     */
    public function users()
    {
        return $this->hasMany(User::class);
    }

    /**
     * Get all products for this company
     */
    public function products()
    {
        return $this->hasMany(Product::class);
    }

    /**
     * Get all categories for this company
     */
    public function categories()
    {
        return $this->hasMany(Category::class);
    }

    /**
     * Get all customers for this company
     */
    public function customers()
    {
        return $this->hasMany(Customer::class);
    }

    /**
     * Get all vendors for this company
     */
    public function vendors()
    {
        return $this->hasMany(Vendor::class);
    }

    /**
     * Get all sales for this company
     */
    public function sales()
    {
        return $this->hasMany(Sale::class);
    }

    /**
     * Get all purchase orders for this company
     */
    public function purchaseOrders()
    {
        return $this->hasMany(PurchaseOrder::class);
    }

    /**
     * Get all inventory transactions for this company
     */
    public function inventoryTransactions()
    {
        return $this->hasMany(InventoryTransaction::class);
    }

    /**
     * Check if company is active
     */
    public function isActive()
    {
        return $this->status === 'active';
    }

    /**
     * Scope a query to only include active companies
     */
    public function scopeActive($query)
    {
        return $query->where('status', 'active');
    }

    /**
     * Get all direct receive for this company
     */
    public function directReceives()
    {
        return $this->hasMany(DirectReceive::class);
    }

}