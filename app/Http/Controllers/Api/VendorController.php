<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Vendor;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class VendorController extends Controller
{
    /**
     * Display a listing of vendors for the authenticated user's company
     */
    public function index(Request $request)
    {
        $companyId = $request->user()->company_id;
        
        $vendors = Vendor::forCompany($companyId)
                        ->when($request->status, function($query, $status) {
                            return $query->where('status', $status);
                        })
                        ->when($request->search, function($query, $search) {
                            return $query->where(function($q) use ($search) {
                                $q->where('name', 'like', "%{$search}%")
                                  ->orWhere('email', 'like', "%{$search}%")
                                  ->orWhere('contact_person', 'like', "%{$search}%");
                            });
                        })
                        ->orderBy($request->sort_by ?? 'name', $request->sort_dir ?? 'asc')
                        ->paginate($request->per_page ?? 15);

        return response()->json([
            'success' => true,
            'data' => $vendors
        ]);
    }

    /**
     * Store a newly created vendor
     */
    public function store(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'name' => 'required|string|max:255',
            'contact_person' => 'nullable|string|max:255',
            'email' => 'nullable|email|max:255',
            'phone' => 'nullable|string|max:20',
            'address' => 'nullable|string',
            'tax_number' => 'nullable|string|max:50',
            'payment_terms' => 'nullable|string|max:50',
            'status' => 'nullable|in:active,inactive',
            'notes' => 'nullable|string'
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'errors' => $validator->errors()
            ], 422);
        }

        $vendor = Vendor::create([
            'company_id' => $request->user()->company_id,
            'name' => $request->name,
            'contact_person' => $request->contact_person,
            'email' => $request->email,
            'phone' => $request->phone,
            'address' => $request->address,
            'tax_number' => $request->tax_number,
            'payment_terms' => $request->payment_terms,
            'status' => $request->status ?? 'active',
            'notes' => $request->notes
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Vendor created successfully',
            'data' => $vendor
        ], 201);
    }

    /**
     * Display the specified vendor
     */
    public function show(Request $request, $id)
    {
        $vendor = Vendor::forCompany($request->user()->company_id)
                       ->findOrFail($id);

        return response()->json([
            'success' => true,
            'data' => $vendor
        ]);
    }

    /**
     * Update the specified vendor
     */
    public function update(Request $request, $id)
    {
        $vendor = Vendor::forCompany($request->user()->company_id)
                       ->findOrFail($id);

        $validator = Validator::make($request->all(), [
            'name' => 'sometimes|required|string|max:255',
            'contact_person' => 'nullable|string|max:255',
            'email' => 'nullable|email|max:255',
            'phone' => 'nullable|string|max:20',
            'address' => 'nullable|string',
            'tax_number' => 'nullable|string|max:50',
            'payment_terms' => 'nullable|string|max:50',
            'status' => 'nullable|in:active,inactive',
            'notes' => 'nullable|string'
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'errors' => $validator->errors()
            ], 422);
        }

        $vendor->update($request->all());

        return response()->json([
            'success' => true,
            'message' => 'Vendor updated successfully',
            'data' => $vendor
        ]);
    }

    /**
     * Remove the specified vendor
     */
    public function destroy(Request $request, $id)
    {
        $vendor = Vendor::forCompany($request->user()->company_id)
                       ->findOrFail($id);
        
        // Check if vendor has products
        if ($vendor->products()->count() > 0) {
            return response()->json([
                'success' => false,
                'message' => 'Cannot delete vendor with existing products'
            ], 422);
        }

        $vendor->delete();

        return response()->json([
            'success' => true,
            'message' => 'Vendor deleted successfully'
        ]);
    }

    /**
     * Toggle vendor status
     */
    public function toggleStatus(Request $request, $id)
    {
        $vendor = Vendor::forCompany($request->user()->company_id)
                       ->findOrFail($id);

        $vendor->status = $vendor->status === 'active' ? 'inactive' : 'active';
        $vendor->save();

        return response()->json([
            'success' => true,
            'message' => "Vendor {$vendor->status} successfully",
            'data' => $vendor
        ]);
    }

    /**
     * Get all active vendors (for dropdowns)
     */
    public function list(Request $request)
    {
        $vendors = Vendor::forCompany($request->user()->company_id)
                        ->active()
                        ->orderBy('name')
                        ->get(['id', 'name', 'payment_terms']);

        return response()->json([
            'success' => true,
            'data' => $vendors
        ]);
    }
}