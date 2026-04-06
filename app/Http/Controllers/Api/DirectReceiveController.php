<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\DirectReceive;
use App\Models\DirectReceiveItem;
use App\Models\Product;
use App\Models\InventoryTransaction;
use App\Models\Vendor;
//use App\Models\Company;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\DB;

class DirectReceiveController extends Controller
{
    public function index(Request $request)
    {
        $companyId = $request->user()->company_id;
        
        $receives = DirectReceive::forCompany($companyId)
            ->with(['vendor', 'user', 'items.product'])
            ->when($request->vendor, function($query, $vendor) {
                return $query->where('vendor_id', $vendor);
            })
            ->when($request->from_date, function($query, $date) {
                return $query->whereDate('receive_date', '>=', $date);
            })
            ->when($request->to_date, function($query, $date) {
                return $query->whereDate('receive_date', '<=', $date);
            })
            ->when($request->search, function($query, $search) {
                return $query->where(function($q) use ($search) {
                    $q->where('reference_number', 'like', "%{$search}%")
                      ->orWhere('vendor_name', 'like', "%{$search}%")
                      ->orWhere('waybill_number', 'like', "%{$search}%");
                });
            })
            ->orderBy($request->sort_by ?? 'receive_date', $request->sort_dir ?? 'desc')
            ->paginate($request->per_page ?? 15);

        return response()->json([
            'success' => true,
            'data' => $receives
        ]);
    }

    public function store(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'receive_date' => 'required|date',
            'vendor_id' => 'nullable|exists:vendors,id',
            'vendor_name' => 'required_without:vendor_id|string|max:255',
            'vendor_phone' => 'nullable|string|max:20',
            'waybill_number' => 'nullable|string|max:255',
            'truck_number' => 'nullable|string|max:255',
            'driver_name' => 'nullable|string|max:255',
            'driver_phone' => 'nullable|string|max:20',
            'items' => 'required|array|min:1',
            'items.*.product_id' => 'nullable|exists:products,id',
            'items.*.product_name' => 'required_without:items.*.product_id|string|max:255',
            'items.*.quantity' => 'required|integer|min:1',
            'items.*.unit_cost' => 'required|numeric|min:0',
            'items.*.expiry_date' => 'nullable|date',
            'payment_status' => 'nullable|in:pending,paid,partial',
            'payment_method' => 'nullable|string',
            'notes' => 'nullable|string'
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'errors' => $validator->errors()
            ], 422);
        }

        try {
            DB::beginTransaction();

            // Create or get vendor
            if ($request->vendor_id) {
                $vendor = Vendor::find($request->vendor_id);
                $vendorName = $vendor->name;
                $vendorPhone = $vendor->phone;
            } else {
                $vendorName = $request->vendor_name;
                $vendorPhone = $request->vendor_phone;
            }

            // Create direct receive
            $receive = DirectReceive::create([
                'company_id' => $request->user()->company_id,
                'vendor_id' => $request->vendor_id,
                'user_id' => $request->user()->id,
                'receive_date' => $request->receive_date,
                'vendor_name' => $vendorName,
                'vendor_phone' => $vendorPhone,
                'waybill_number' => $request->waybill_number,
                'truck_number' => $request->truck_number,
                'driver_name' => $request->driver_name,
                'driver_phone' => $request->driver_phone,
                'payment_status' => $request->payment_status ?? 'pending',
                'payment_method' => $request->payment_method,
                'notes' => $request->notes
            ]);

            $subtotal = 0;

            // Process items
            foreach ($request->items as $item) {
                // Find or create product
                if (isset($item['product_id'])) {
                    $product = Product::find($item['product_id']);
                } else {
                    // Create new product on the fly
                    $product = Product::create([
                        'company_id' => $request->user()->company_id,
                        'name' => $item['product_name'],
                        'sku' => $item['product_sku'] ?? 'IMP-' . strtoupper(uniqid()),
                        'price' => 0, // Will be set later
                        'cost' => $item['unit_cost'],
                        'stock_quantity' => 0,
                        'status' => 'draft'
                    ]);
                }

                $total = $item['quantity'] * $item['unit_cost'];
                $subtotal += $total;

                // Create receive item
                $receiveItem = DirectReceiveItem::create([
                    'direct_receive_id' => $receive->id,
                    'product_id' => $product->id,
                    'quantity' => $item['quantity'],
                    'unit_cost' => $item['unit_cost'],
                    'total' => $total,
                    'product_name' => $product->name,
                    'product_sku' => $product->sku,
                    'expiry_date' => $item['expiry_date'] ?? null,
                    'notes' => $item['notes'] ?? null
                ]);

                // Update product stock
                $oldStock = $product->stock_quantity;
                $product->stock_quantity += $item['quantity'];
                $product->save();

                // Create inventory transaction
                InventoryTransaction::create([
                    'company_id' => $receive->company_id,
                    'product_id' => $product->id,
                    'user_id' => $request->user()->id,
                    'type' => 'direct_receive',
                    'quantity' => $item['quantity'],
                    'before_quantity' => $oldStock,
                    'after_quantity' => $product->stock_quantity,
                    'reference_type' => 'direct_receive',
                    'reference_id' => $receive->id,
                    'notes' => "Received: {$item['quantity']} units"
                ]);
            }

            // Update totals
            $receive->subtotal = $subtotal;
            $receive->total = $subtotal + $receive->tax - $receive->discount;
            $receive->save();

            DB::commit();

            return response()->json([
                'success' => true,
                'message' => 'Items received successfully',
                'data' => $receive->load(['items.product', 'vendor'])
            ], 201);

        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json([
                'success' => false,
                'message' => 'Failed to receive items',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    public function show(Request $request, $id)
    {
        $receive = DirectReceive::forCompany($request->user()->company_id)
            ->with(['vendor', 'user', 'items.product'])
            ->findOrFail($id);

        return response()->json([
            'success' => true,
            'data' => $receive
        ]);
    }

    public function updatePayment(Request $request, $id)
    {
        $receive = DirectReceive::forCompany($request->user()->company_id)
            ->findOrFail($id);

        $validator = Validator::make($request->all(), [
            'payment_status' => 'required|in:pending,paid,partial',
            'payment_method' => 'nullable|string',
            'amount_paid' => 'nullable|numeric|min:0'
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'errors' => $validator->errors()
            ], 422);
        }

        $receive->update([
            'payment_status' => $request->payment_status,
            'payment_method' => $request->payment_method
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Payment status updated',
            'data' => $receive
        ]);
    }

    public function quickReceive(Request $request)
    {
        // Simplified version for very fast entry
        $validator = Validator::make($request->all(), [
            'items' => 'required|array|min:1',
            'items.*.product_id' => 'required|exists:products,id',
            'items.*.quantity' => 'required|integer|min:1',
            'items.*.unit_cost' => 'required|numeric|min:0',
            'vendor_name' => 'nullable|string'
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'errors' => $validator->errors()
            ], 422);
        }

        // Reuse the store method with defaults
        $request->merge([
            'receive_date' => now()->format('Y-m-d'),
            'vendor_name' => $request->vendor_name ?? 'Cash Purchase',
            'payment_status' => 'paid',
            'payment_method' => 'cash'
        ]);

        return $this->store($request);
    }
}  