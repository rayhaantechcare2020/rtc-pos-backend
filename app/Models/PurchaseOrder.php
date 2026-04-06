<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class PurchaseOrder extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'company_id',
        'vendor_id',
        'user_id',
        'po_number',
        'order_date',
        'expected_delivery_date',
        'delivery_date',
        'status',
        'payment_status',
        'subtotal',
        'tax',
        'discount',
        'shipping_cost',
        'total',
        'tracking_number',
        'waybill_number',
        'truck_number',
        'carrier',
        'notes',
        'terms'
    ];

    protected $casts = [
        'order_date' => 'date',
        'expected_delivery_date' => 'date',
        'delivery_date' => 'date',
        'subtotal' => 'decimal:2',
        'tax' => 'decimal:2',
        'discount' => 'decimal:2',
        'shipping_cost' => 'decimal:2',
        'total' => 'decimal:2'
    ];

    // Relationships
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
        return $this->hasMany(PurchaseOrderItem::class);
    }

    // Scopes
    public function scopeForCompany($query, $companyId)
    {
        return $query->where('company_id', $companyId);
    }

    public function scopeDraft($query)
    {
        return $query->where('status', 'draft');
    }

    public function scopePending($query)
    {
        return $query->where('status', 'sent');
    }

    public function scopeReceived($query)
    {
        return $query->where('status', 'received');
    }

    // Helper Methods
    public function calculateTotals()
    {
        $this->subtotal = $this->items->sum('total');
        $this->total = $this->subtotal + $this->tax + $this->shipping_cost - $this->discount;
        $this->save();
    }

    public function isFullyReceived()
    {
        foreach ($this->items as $item) {
            if ($item->received_quantity < $item->quantity) {
                return false;
            }
        }
        return true;
    }

    public function receiveItems($productId, $quantity)
    {
        $item = $this->items()->where('product_id', $productId)->first();
        
        if ($item) {
            $item->received_quantity += $quantity;
            $item->save();
            
            // Update product stock
            $product = Product::find($productId);
            $product->stock_quantity += $quantity;
            $product->save();
            
            // Create inventory transaction
            InventoryTransaction::create([
                'company_id' => $this->company_id,
                'product_id' => $productId,
                'user_id' => auth()->id(),
                'type' => 'purchase',
                'quantity' => $quantity,
                'before_quantity' => $product->stock_quantity - $quantity,
                'after_quantity' => $product->stock_quantity,
                'reference_type' => 'purchase_order',
                'reference_id' => $this->id,
                'notes' => "Received from PO #{$this->po_number}"
            ]);
        }
        
        if ($this->isFullyReceived()) {
            $this->status = 'received';
            $this->delivery_date = now();
            $this->save();
        }
    }
}