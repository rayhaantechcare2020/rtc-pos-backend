<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Category extends Model
{
    use HasFactory;

    protected $fillable = [
        'company_id',
        'name',
        'slug',
        'description',
        'color',
        'sort_order',
        'status'
    ];

    protected $casts = [
        'sort_order' => 'integer'
    ];


    
    /**
     * Get the company that owns the category.
     */
    public function company()
    {
        return $this->belongsTo(Company::class);
    }

    /**
     * Get the products for this category.
     */
    public function products()
    {
        return $this->hasMany(Product::class);
    }

    /**
     * Scope a query to only include active categories.
     */
    public function scopeActive($query)
    {
        return $query->where('status', 'active');
    }

    /**
     * Scope a query to only include categories for a specific company.
     */
    public function scopeForCompany($query, $companyId)
    {
        return $query->where('company_id', $companyId);
    }

    /**
     * Get the product count attribute.
     */
    public function getProductsCountAttribute()
    {
        return $this->products()->count();
    }
}