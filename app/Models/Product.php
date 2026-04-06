<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;

class Product extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'company_id',
        'category_id',
        'vendor_id',
        'name',
        'slug',
        'sku',
        'barcode',
        'description',
        'short_description',
        'price',
        'cost',
        'wholesale_price',
        'special_price',
        'special_price_from',
        'special_price_to',
        'stock_quantity',
        'low_stock_threshold',
        'track_inventory',
        'allow_backorders',
        'featured_image',
        'gallery_images',
        'status',
        'featured',
        'attributes',
        'variations',
        'meta_title',
        'meta_description',
        'meta_keywords'
    ];

    protected $casts = [
        'gallery_images' => 'array',
        'attributes' => 'array',
        'variations' => 'array',
        'special_price_from' => 'datetime',
        'special_price_to' => 'datetime',
        'featured' => 'boolean',
        'track_inventory' => 'boolean',
        'allow_backorders' => 'boolean',
        'price' => 'decimal:2',
        'cost' => 'decimal:2',
        'wholesale_price' => 'decimal:2',
        'special_price' => 'decimal:2'
    ];

    protected static function boot()
    {
        parent::boot();
        
        static::creating(function ($product) {
            if (empty($product->slug)) {
                $product->slug = Str::slug($product->name);
            }
        });
    }

    // Relationships
    public function company()
    {
        return $this->belongsTo(Company::class);
    }

    public function category()
    {
        return $this->belongsTo(Category::class);
    }

    public function vendor()
    {
        return $this->belongsTo(Vendor::class);
    }

    public function saleItems()
    {
        return $this->hasMany(SaleItem::class);
    }

    public function purchaseOrderItems()
    {
        return $this->hasMany(PurchaseOrderItem::class);
    }

    public function inventoryTransactions()
    {
        return $this->hasMany(InventoryTransaction::class);
    }

    // Scopes
    public function scopeForCompany($query, $companyId)
    {
        return $query->where('company_id', $companyId);
    }

    public function scopePublished($query)
    {
        return $query->where('status', 'published');
    }

    public function scopeInStock($query)
    {
        return $query->where('stock_quantity', '>', 0);
    }

    public function scopeLowStock($query)
    {
        return $query->whereColumn('stock_quantity', '<=', 'low_stock_threshold')
                     ->where('stock_quantity', '>', 0);
    }

    public function scopeOutOfStock($query)
    {
        return $query->where('stock_quantity', '<=', 0);
    }

    public function scopeFeatured($query)
    {
        return $query->where('featured', true);
    }

    public function scopeOnSale($query)
    {
        return $query->whereNotNull('special_price')
                     ->where(function($q) {
                         $q->whereNull('special_price_from')
                           ->orWhere('special_price_from', '<=', now());
                     })
                     ->where(function($q) {
                         $q->whereNull('special_price_to')
                           ->orWhere('special_price_to', '>=', now());
                     });
    }

    // Accessors
    public function getFinalPriceAttribute()
    {
        if ($this->special_price && 
            (!$this->special_price_from || $this->special_price_from <= now()) &&
            (!$this->special_price_to || $this->special_price_to >= now())) {
            return $this->special_price;
        }
        return $this->price;
    }

    public function getProfitMarginAttribute()
    {
        if ($this->cost > 0) {
            return (($this->price - $this->cost) / $this->price) * 100;
        }
        return 0;
    }

    public function getIsLowStockAttribute()
    {
        return $this->stock_quantity <= $this->low_stock_threshold;
    }

    public function getFeaturedImageUrlAttribute()
    {
        return $this->featured_image 
            ? asset('storage/' . $this->featured_image)
            : null;
    }
}