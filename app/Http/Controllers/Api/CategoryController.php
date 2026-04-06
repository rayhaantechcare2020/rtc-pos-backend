<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Category;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;

class CategoryController extends Controller
{
    /**
     * Display a listing of categories for the authenticated user's company.
     */
    public function index(Request $request)
    {
        try {
            $categories = Category::where('company_id', $request->user()->company_id)
                        ->withCount('products') // Get product count for each category
                        ->orderBy('sort_order')
                        ->orderBy('name')
                        ->get();

            return response()->json([
                'success' => true,
                'data' => $categories
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to fetch categories',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Store a newly created category.
     */
     public function store(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'name' => 'required|string|max:255',
            'description' => 'nullable|string',
            'color' => 'nullable|string|max:7',
            'sort_order' => 'nullable|integer'
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'errors' => $validator->errors()
            ], 422);
        }

        try {
            $companyId = $request->user()->company_id;
            
            // Check if category with same name exists for this company
            $existingName = Category::where('company_id', $companyId)
                ->where('name', $request->name)
                ->first();

            if ($existingName) {
                return response()->json([
                    'success' => false,
                    'message' => 'A category with this name already exists for your company'
                ], 422);
            }

            // Generate slug from name
            $slug = Str::slug($request->name);
            
            // Check if slug exists for this company
            $existingSlug = Category::where('company_id', $companyId)
                ->where('slug', $slug)
                ->first();

            // If slug exists, append a number
            if ($existingSlug) {
                $counter = 1;
                $newSlug = $slug . '-' . $counter;
                
                while (Category::where('company_id', $companyId)
                    ->where('slug', $newSlug)
                    ->exists()) {
                    $counter++;
                    $newSlug = $slug . '-' . $counter;
                }
                
                $slug = $newSlug;
            }

            $category = Category::create([
                'company_id' => $companyId,
                'name' => $request->name,
                'slug' => $slug,
                'description' => $request->description,
                'color' => $request->color ?? '#3B82F6',
                'sort_order' => $request->sort_order ?? 0,
                'status' => 'active'
            ]);

            return response()->json([
                'success' => true,
                'message' => 'Category created successfully',
                'data' => $category
            ], 201);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to create category',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Display the specified category.
     */
    public function show(Request $request, $id)
    {
        try {
            $category = Category::where('company_id', $request->user()->company_id)
                        ->with('products')
                        ->findOrFail($id);

            return response()->json([
                'success' => true,
                'data' => $category
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Category not found'
            ], 404);
        }
    }

    /**
     * Update the specified category.
     */
  
    public function update(Request $request, $id)
    {
        $validator = Validator::make($request->all(), [
            'name' => 'sometimes|required|string|max:255',
            'description' => 'nullable|string',
            'color' => 'nullable|string|max:7',
            'sort_order' => 'nullable|integer',
            'status' => 'nullable|in:active,inactive'
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'errors' => $validator->errors()
            ], 422);
        }

        try {
            $companyId = $request->user()->company_id;
            
            $category = Category::where('company_id', $companyId)
                ->findOrFail($id);

            $updateData = [];
            
            // Handle name update and duplicate check
            if ($request->has('name') && $request->name !== $category->name) {
                // Check if new name already exists for this company (excluding current category)
                $existingName = Category::where('company_id', $companyId)
                    ->where('name', $request->name)
                    ->where('id', '!=', $id)
                    ->first();

                if ($existingName) {
                    return response()->json([
                        'success' => false,
                        'message' => 'A category with this name already exists for your company'
                    ], 422);
                }

                $updateData['name'] = $request->name;
                
                // Generate new slug from updated name
                $slug = Str::slug($request->name);
                
                // Check if new slug already exists for other categories in this company
                $existingSlug = Category::where('company_id', $companyId)
                    ->where('slug', $slug)
                    ->where('id', '!=', $id)
                    ->first();

                // If slug exists, append a number
                if ($existingSlug) {
                    $counter = 1;
                    $newSlug = $slug . '-' . $counter;
                    
                    while (Category::where('company_id', $companyId)
                        ->where('slug', $newSlug)
                        ->where('id', '!=', $id)
                        ->exists()) {
                        $counter++;
                        $newSlug = $slug . '-' . $counter;
                    }
                    
                    $slug = $newSlug;
                }
                
                $updateData['slug'] = $slug;
            }
            
            // Handle other fields
            if ($request->has('description')) {
                $updateData['description'] = $request->description;
            }
            
            if ($request->has('color')) {
                $updateData['color'] = $request->color;
            }
            
            if ($request->has('sort_order')) {
                $updateData['sort_order'] = $request->sort_order;
            }
            
            if ($request->has('status')) {
                $updateData['status'] = $request->status;
            }

            // Only update if there are changes
            if (!empty($updateData)) {
                $category->update($updateData);
            }

            return response()->json([
                'success' => true,
                'message' => 'Category updated successfully',
                'data' => $category->fresh()
            ]);

        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Category not found'
            ], 404);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to update category',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Remove the specified category.
     */
    public function destroy(Request $request, $id)
    {
        try {
            $category = Category::where('company_id', $request->user()->company_id)
                        ->findOrFail($id);

            // Check if category has products
            if ($category->products()->count() > 0) {
                return response()->json([
                    'success' => false,
                    'message' => 'Cannot delete category that has products. Please reassign or delete the products first.'
                ], 422);
            }

            $category->delete();

            return response()->json([
                'success' => true,
                'message' => 'Category deleted successfully'
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to delete category',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Get categories for dropdown (simplified list).
     */
    public function list(Request $request)
    {
        try {
            $categories = Category::where('company_id', $request->user()->company_id)
                        ->where('status', 'active')
                        ->orderBy('name')
                        ->get(['id', 'name', 'color']);

            return response()->json([
                'success' => true,
                'data' => $categories
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to fetch categories list'
            ], 500);
        }
    }

        public function checkAvailability(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'name' => 'required|string|max:255',
            'exclude_id' => 'nullable|integer|exists:categories,id'
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'errors' => $validator->errors()
            ], 422);
        }

        try {
            $companyId = $request->user()->company_id;
            $excludeId = $request->exclude_id;
            
            // Check name availability
            $nameQuery = Category::where('company_id', $companyId)
                ->where('name', $request->name);
            
            if ($excludeId) {
                $nameQuery->where('id', '!=', $excludeId);
            }
            
            $nameExists = $nameQuery->exists();
            
            // Generate and check slug availability
            $slug = Str::slug($request->name);
            $slugExists = Category::where('company_id', $companyId)
                ->where('slug', $slug)
                ->when($excludeId, function($query, $excludeId) {
                    return $query->where('id', '!=', $excludeId);
                })
                ->exists();

            return response()->json([
                'success' => true,
                'data' => [
                    'name_available' => !$nameExists,
                    'slug_available' => !$slugExists,
                    'suggested_slug' => $slugExists ? null : $slug
                ]
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to check availability',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Bulk update sort order.
     */
    public function updateOrder(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'categories' => 'required|array',
            'categories.*.id' => 'required|exists:categories,id',
            'categories.*.sort_order' => 'required|integer'
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'errors' => $validator->errors()
            ], 422);
        }

        try {
            foreach ($request->categories as $item) {
                Category::where('id', $item['id'])
                    ->where('company_id', $request->user()->company_id)
                    ->update(['sort_order' => $item['sort_order']]);
            }

            return response()->json([
                'success' => true,
                'message' => 'Category order updated successfully'
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to update category order',
                'error' => $e->getMessage()
            ], 500);
        }
    }
}