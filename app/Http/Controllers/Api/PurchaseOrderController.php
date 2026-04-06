<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\PurchaseOrder;
use App\Models\PurchaseOrderItem;
use App\Models\Product;
use App\Models\InventoryTransaction;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\DB;

class PurchaseOrderController extends Controller
{
    public function index(Request $request)
    {
        $companyId = $request->user()->company_id;

        $product = Product::all(); //Not Used
        $inventory = InventoryTransaction::all(); //Not Used 

        $purchaseOrders = PurchaseOrder::forCompany($companyId)
            ->with(['vendor', 'user', 'items.product'])
            ->when($request->vendor, function($query, $vendor) {
                return $query->where('vendor_id', $vendor);
            })
            ->when($request->status, function($query, $status) {
                return $query->where('status', $status);
            })
            ->when($request->from_date, function($query, $date) {
                return $query->whereDate('order_date', '>=', $date);
            })
            ->when($request->to_date, function($query, $date) {
                return $query->whereDate('order_date', '<=', $date);
            })
            ->when($request->search, function($query, $search) {
                return $query->where(function($q) use ($search) {
                    $q->where('po_number', 'like', "%{$search}%")
                      ->orWhereHas('vendor', function($v) use ($search) {
                          $v->where('name', 'like', "%{$search}%");
                      });
                });
            })
            ->orderBy($request->sort_by ?? 'order_date', $request->sort_dir ?? 'desc')
            ->paginate($request->per_page ?? 15);

        return response()->json([
            'success' => true,
            'data' => $purchaseOrders
        ]);
    }

    public function store(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'vendor_id' => 'required|exists:vendors,id',
            'order_date' => 'required|date',
            'expected_delivery_date' => 'nullable|date|after_or_equal:order_date',
            'items' => 'required|array|min:1',
             'waybill_number' => 'nullable|string|max:255',        // ADD THIS
             'truck_number' => 'nullable|string|max:255',          // ADD THIS
            'items.*.product_id' => 'required|exists:products,id',
            'items.*.quantity' => 'required|integer|min:1',
            'items.*.unit_cost' => 'required|numeric|min:0',
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

            // Generate PO number
            $poNumber = 'PO-' . date('Ymd') . '-' . str_pad(PurchaseOrder::count() + 1, 4, '0', STR_PAD_LEFT);

            // Create purchase order
            $purchaseOrder = PurchaseOrder::create([
                'company_id' => $request->user()->company_id,
                'vendor_id' => $request->vendor_id,
                'user_id' => $request->user()->id,
                'po_number' => $poNumber,
                'waybill_number'=>$request->waybill_number,
                'truck_number'=>$request->truck_number,
                'order_date' => $request->order_date,
                'expected_delivery_date' => $request->expected_delivery_date,
                'status' => 'draft',
                'notes' => $request->notes
            ]);

            // Create items
            foreach ($request->items as $item) {
                PurchaseOrderItem::create([
                    'purchase_order_id' => $purchaseOrder->id,
                    'product_id' => $item['product_id'],
                    'quantity' => $item['quantity'],
                    'unit_cost' => $item['unit_cost'],
                    'total' => $item['quantity'] * $item['unit_cost']
                ]);
            }

            // Calculate totals
            $purchaseOrder->calculateTotals();

            DB::commit();

            return response()->json([
                'success' => true,
                'message' => 'Purchase order created successfully',
                'data' => $purchaseOrder->load(['vendor', 'items.product'])
            ], 201);

        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json([
                'success' => false,
                'message' => 'Failed to create purchase order',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    public function show(Request $request, $id)
    {
        $purchaseOrder = PurchaseOrder::forCompany($request->user()->company_id)
            ->with(['vendor', 'user', 'items.product', 'items.product.category'])
            ->findOrFail($id);

        return response()->json([
            'success' => true,
            'data' => $purchaseOrder
        ]);
    }

    public function update(Request $request, $id)
    {
        $purchaseOrder = PurchaseOrder::forCompany($request->user()->company_id)
            ->findOrFail($id);

        // Can only update draft orders
        if ($purchaseOrder->status !== 'draft') {
            return response()->json([
                'success' => false,
                'message' => 'Only draft orders can be updated'
            ], 422);
        }

        $validator = Validator::make($request->all(), [
            'vendor_id' => 'sometimes|required|exists:vendors,id',
            'expected_delivery_date' => 'nullable|date',
            'items' => 'sometimes|required|array|min:1',
            'items.*.product_id' => 'required|exists:products,id',
            'items.*.quantity' => 'required|integer|min:1',
             'waybill_number' => 'nullable|string|max:255',   
            'truck_number' => 'nullable|string|max:255', 
            'items.*.unit_cost' => 'required|numeric|min:0',
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

            $purchaseOrder->update($request->only([
                'vendor_id', 'expected_delivery_date','waybill_number','truck_number', 'notes'
            ]));

            // Update items if provided
            if ($request->has('items')) {
                // Delete old items
                $purchaseOrder->items()->delete();

                // Create new items
                foreach ($request->items as $item) {
                    PurchaseOrderItem::create([
                        'purchase_order_id' => $purchaseOrder->id,
                        'product_id' => $item['product_id'],
                        'quantity' => $item['quantity'],
                        'unit_cost' => $item['unit_cost'],
                        'total' => $item['quantity'] * $item['unit_cost']
                    ]);
                }

                // Recalculate totals
                $purchaseOrder->calculateTotals();
            }

            DB::commit();

            return response()->json([
                'success' => true,
                'message' => 'Purchase order updated successfully',
                'data' => $purchaseOrder->load(['items.product'])
            ]);

        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json([
                'success' => false,
                'message' => 'Failed to update purchase order',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    public function destroy(Request $request, $id)
    {
        $purchaseOrder = PurchaseOrder::forCompany($request->user()->company_id)
            ->findOrFail($id);

        // Can only delete draft orders
        if ($purchaseOrder->status !== 'draft') {
            return response()->json([
                'success' => false,
                'message' => 'Only draft orders can be deleted'
            ], 422);
        }

        $purchaseOrder->delete();

        return response()->json([
            'success' => true,
            'message' => 'Purchase order deleted successfully'
        ]);
    }

    public function send(Request $request, $id)
    {
        $purchaseOrder = PurchaseOrder::forCompany($request->user()->company_id)
            ->findOrFail($id);

        if ($purchaseOrder->status !== 'draft') {
            return response()->json([
                'success' => false,
                'message' => 'Order already sent'
            ], 422);
        }

        $purchaseOrder->status = 'sent';
        $purchaseOrder->save();

        // TODO: Send email to vendor

        return response()->json([
            'success' => true,
            'message' => 'Purchase order sent to vendor',
            'data' => $purchaseOrder
        ]);
    }

    public function receive(Request $request, $id)
    {
        $purchaseOrder = PurchaseOrder::forCompany($request->user()->company_id)
            ->findOrFail($id);

        $validator = Validator::make($request->all(), [
            'items' => 'required|array',
            'items.*.product_id' => 'required|exists:products,id',
            'items.*.quantity' => 'required|integer|min:0'
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'errors' => $validator->errors()
            ], 422);
        }

        try {
            DB::beginTransaction();

            foreach ($request->items as $receiveItem) {
                $purchaseOrder->receiveItems($receiveItem['product_id'], $receiveItem['quantity']);
            }

            DB::commit();

            return response()->json([
                'success' => true,
                'message' => 'Items received successfully',
                'data' => $purchaseOrder->fresh(['items.product'])
            ]);

        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json([
                'success' => false,
                'message' => 'Failed to receive items',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    public function updateStatus(Request $request, $id)
    {
        $purchaseOrder = PurchaseOrder::forCompany($request->user()->company_id)
            ->findOrFail($id);

        $validator = Validator::make($request->all(), [
            'status' => 'required|in:draft,sent,confirmed,received,cancelled,completed'
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'errors' => $validator->errors()
            ], 422);
        }

        $purchaseOrder->status = $request->status;
        $purchaseOrder->save();

        return response()->json([
            'success' => true,
            'message' => 'Status updated successfully',
            'data' => $purchaseOrder
        ]);
    }

    /**
 * Update tracking information
 */
public function updateTracking(Request $request, $id)
{
    $purchaseOrder = PurchaseOrder::forCompany($request->user()->company_id)
        ->findOrFail($id);

    $validator = Validator::make($request->all(), [
        'waybill_number' => 'nullable|string|max:255',
        'truck_number' => 'nullable|string|max:255',
        'carrier' => 'nullable|string|max:255',
        'tracking_number' => 'nullable|string|max:255'
    ]);

    if ($validator->fails()) {
        return response()->json([
            'success' => false,
            'errors' => $validator->errors()
        ], 422);
    }

    $purchaseOrder->update($request->only([
        'waybill_number',
        'truck_number',
        'carrier',
        'tracking_number'
    ]));

    return response()->json([
        'success' => true,
        'message' => 'Tracking information updated',
        'data' => $purchaseOrder
    ]);
}
}