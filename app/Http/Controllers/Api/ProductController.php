<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Product;
use App\Models\Category;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;

class ProductController extends Controller
{
    public function index(Request $request)
    {
        $companyId = $request->user()->company_id;
        
        $products = Product::forCompany($companyId)
                    ->with(['category', 'vendor'])
                    ->when($request->category, function($query, $category) {
                        return $query->where('category_id', $category);
                    })
                    ->when($request->vendor, function($query, $vendor) {
                        return $query->where('vendor_id', $vendor);
                    })
                    ->when($request->status, function($query, $status) {
                        return $query->where('status', $status);
                    })
                    ->when($request->featured, function($query) {
                        return $query->featured();
                    })
                    ->when($request->on_sale, function($query) {
                        return $query->onSale();
                    })
                    ->when($request->stock_status, function($query, $status) {
                        if ($status === 'in_stock') {
                            return $query->inStock();
                        } elseif ($status === 'low_stock') {
                            return $query->lowStock();
                        } elseif ($status === 'out_of_stock') {
                            return $query->outOfStock();
                        }
                    })
                    ->when($request->search, function($query, $search) {
                        return $query->where(function($q) use ($search) {
                            $q->where('name', 'like', "%{$search}%")
                              ->orWhere('sku', 'like', "%{$search}%")
                              ->orWhere('barcode', 'like', "%{$search}%");
                        });
                    })
                    ->orderBy($request->sort_by ?? 'name', $request->sort_dir ?? 'asc')
                    ->paginate($request->per_page ?? 15);

        return response()->json([
            'success' => true,
            'data' => $products
        ]);
    }

    public function store(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'name' => 'required|string|max:255',
            'category_id' => 'nullable|exists:categories,id',
            'vendor_id' => 'nullable|exists:vendors,id',
            'sku' => 'nullable|string|unique:products,sku',
            'barcode' => 'nullable|string',
            'price' => 'required|numeric|min:0',
            'cost' => 'nullable|numeric|min:0',
            'stock_quantity' => 'nullable|integer|min:0',
            'description' => 'nullable|string',
            'status' => 'nullable|in:active,published,archived'
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'errors' => $validator->errors()
            ], 422);
        }

        $product = Product::create([
            'company_id' => $request->user()->company_id,
            'category_id' => $request->category_id,
            'vendor_id' => $request->vendor_id,
            'name' => $request->name,
            'slug' => Str::slug($request->name),
            'sku' => $request->sku,
            'barcode' => $request->barcode,
            'price' => $request->price,
            'cost' => $request->cost ?? 0,
            'stock_quantity' => $request->stock_quantity ?? 0,
            'description' => $request->description,
            'status' => $request->status ?? 'active'
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Product created successfully',
            'data' => $product->load(['category', 'vendor'])
        ], 201);
    }

    public function show(Request $request, $id)
    {
        $product = Product::forCompany($request->user()->company_id)
                    ->with(['category', 'vendor'])
                    ->findOrFail($id);

        return response()->json([
            'success' => true,
            'data' => $product
        ]);
    }

    public function update(Request $request, $id)
    {
        $product = Product::forCompany($request->user()->company_id)
                    ->findOrFail($id);

        $validator = Validator::make($request->all(), [
            'name' => 'sometimes|required|string|max:255',
            'category_id' => 'nullable|exists:categories,id',
            'vendor_id' => 'nullable|exists:vendors,id',
            'sku' => 'nullable|string|unique:products,sku,' . $product->id,
            'price' => 'sometimes|required|numeric|min:0',
            'status' => 'nullable|in:active,published,archived'
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'errors' => $validator->errors()
            ], 422);
        }

        $product->update($request->all());

        return response()->json([
            'success' => true,
            'message' => 'Product updated successfully',
            'data' => $product->load(['category', 'vendor'])
        ]);
    }

    public function destroy(Request $request, $id)
    {
        $product = Product::forCompany($request->user()->company_id)
                    ->findOrFail($id);

        // Check if product has sales
        if ($product->saleItems()->count() > 0) {
            return response()->json([
                'success' => false,
                'message' => 'Cannot delete product with sales history'
            ], 422);
        }

        $product->delete();

        return response()->json([
            'success' => true,
            'message' => 'Product deleted successfully'
        ]);
    }

    public function updateStock(Request $request, $id)
    {
        $product = Product::forCompany($request->user()->company_id)
                    ->findOrFail($id);

        $validator = Validator::make($request->all(), [
            'quantity' => 'required|integer',
            'type' => 'required|in:add,subtract,set'
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'errors' => $validator->errors()
            ], 422);
        }

        if ($request->type === 'add') {
            $product->stock_quantity += $request->quantity;
        } elseif ($request->type === 'subtract') {
            $product->stock_quantity -= $request->quantity;
        } else {
            $product->stock_quantity = $request->quantity;
        }

        $product->save();

        return response()->json([
            'success' => true,
            'message' => 'Stock updated successfully',
            'data' => ['stock_quantity' => $product->stock_quantity]
        ]);
    }
}