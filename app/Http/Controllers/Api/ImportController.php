<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Product;
use App\Models\Category;
use App\Models\Vendor;
use App\Models\Customer;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class ImportController extends Controller
{
    /**
     * Import products from CSV/Excel
     */
    public function importProducts(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'file' => 'required|file|mimes:csv,xlsx,xls|max:5120',
            'mode' => 'required|in:append,replace,update'
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'errors' => $validator->errors()
            ], 422);
        }

        try {
            $companyId = $request->user()->company_id;
            $mode = $request->mode;
            
            // Read CSV file
            $file = $request->file('file');
            $handle = fopen($file->getPathname(), 'r');
            
            // Get headers
            $headers = fgetcsv($handle);
            
            $imported = 0;
            $updated = 0;
            $skipped = 0;
            $errors = [];
            
            // Process each row
            $rowNumber = 1;
            while (($row = fgetcsv($handle)) !== false) {
                $rowNumber++;
                $data = array_combine($headers, $row);
                
                // Validate required fields
                if (empty($data['name'])) {
                    $errors[] = "Row $rowNumber: Product name is required";
                    $skipped++;
                    continue;
                }
                
                // Check if product exists
                $existingProduct = Product::where('company_id', $companyId)
                    ->where(function($q) use ($data) {
                        if (!empty($data['sku'])) {
                            $q->where('sku', $data['sku']);
                        } else {
                            $q->where('name', $data['name']);
                        }
                    })
                    ->first();
                
                // Handle category
                $categoryId = null;
                if (!empty($data['category'])) {
                    $category = Category::firstOrCreate(
                        ['company_id' => $companyId, 'name' => $data['category']],
                        ['slug' => Str::slug($data['category']), 'status' => 'active']
                    );
                    $categoryId = $category->id;
                }
                
                // Handle vendor
                $vendorId = null;
                if (!empty($data['vendor'])) {
                    $vendor = Vendor::firstOrCreate(
                        ['company_id' => $companyId, 'name' => $data['vendor']],
                        ['status' => 'active']
                    );
                    $vendorId = $vendor->id;
                }
                
                if ($existingProduct) {
                    if ($mode === 'update' || $mode === 'append') {
                        // Update existing product
                        $existingProduct->update([
                            'name' => $data['name'],
                            'sku' => $data['sku'] ?? $existingProduct->sku,
                            'price' => $data['price'] ?? $existingProduct->price,
                            'cost' => $data['cost'] ?? $existingProduct->cost,
                            'stock_quantity' => $data['stock'] ?? $existingProduct->stock_quantity,
                            'category_id' => $categoryId ?? $existingProduct->category_id,
                            'vendor_id' => $vendorId ?? $existingProduct->vendor_id,
                            'description' => $data['description'] ?? $existingProduct->description,
                        ]);
                        $updated++;
                    } else {
                        $skipped++;
                        $errors[] = "Row $rowNumber: Product '{$data['name']}' already exists (skipped)";
                    }
                } else {
                    // Create new product
                    Product::create([
                        'company_id' => $companyId,
                        'name' => $data['name'],
                        'sku' => $data['sku'] ?? null,
                        'price' => $data['price'] ?? 0,
                        'cost' => $data['cost'] ?? 0,
                        'stock_quantity' => $data['stock'] ?? 0,
                        'category_id' => $categoryId,
                        'vendor_id' => $vendorId,
                        'description' => $data['description'] ?? null,
                        'status' => 'active'
                    ]);
                    $imported++;
                }
            }
            
            fclose($handle);
            
            return response()->json([
                'success' => true,
                'message' => "Import completed: {$imported} added, {$updated} updated, {$skipped} skipped",
                'data' => [
                    'imported' => $imported,
                    'updated' => $updated,
                    'skipped' => $skipped,
                    'errors' => $errors
                ]
            ]);
            
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Import failed: ' . $e->getMessage()
            ], 500);
        }
    }
    
    /**
     * Export products to CSV template
     */
    public function exportTemplate(Request $request)
    {
        $headers = [
            'name', 'sku', 'price', 'cost', 'stock', 
            'category', 'vendor', 'description', 'status'
        ];
        
        $filename = 'product_import_template.csv';
        $handle = fopen('php://memory', 'w');
        
        // Add headers
        fputcsv($handle, $headers);
        
        // Add sample data
        fputcsv($handle, [
            'Sample Product',
            'SKU001',
            '1000',
            '800',
            '50',
            'Electronics',
            'Tech Supplier',
            'This is a sample product',
            'active'
        ]);
        
        fputcsv($handle, [
            'Another Product',
            'SKU002',
            '2000',
            '1500',
            '30',
            'Clothing',
            'Fashion Vendor',
            'Sample description',
            'active'
        ]);
        
        fseek($handle, 0);
        
        return response()->stream(function() use ($handle) {
            fpassthru($handle);
            fclose($handle);
        }, 200, [
            'Content-Type' => 'text/csv',
            'Content-Disposition' => 'attachment; filename="' . $filename . '"',
        ]);
    }
    
    /**
     * Import customers from CSV
     */
    public function importCustomers(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'file' => 'required|file|mimes:csv,xlsx,xls|max:5120',
            'mode' => 'required|in:append,replace,update'
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'errors' => $validator->errors()
            ], 422);
        }

        try {
            $companyId = $request->user()->company_id;
            $mode = $request->mode;
            
            $file = $request->file('file');
            $handle = fopen($file->getPathname(), 'r');
            $headers = fgetcsv($handle);
            
            $imported = 0;
            $updated = 0;
            $skipped = 0;
            
            while (($row = fgetcsv($handle)) !== false) {
                $data = array_combine($headers, $row);
                
                if (empty($data['name'])) {
                    $skipped++;
                    continue;
                }
                
                $existingCustomer = Customer::where('company_id', $companyId)
                    ->where('email', $data['email'] ?? null)
                    ->orWhere('phone', $data['phone'] ?? null)
                    ->first();
                
                if ($existingCustomer) {
                    if ($mode === 'update' || $mode === 'append') {
                        $existingCustomer->update([
                            'name' => $data['name'],
                            'email' => $data['email'] ?? $existingCustomer->email,
                            'phone' => $data['phone'] ?? $existingCustomer->phone,
                            'address' => $data['address'] ?? $existingCustomer->address,
                            'credit_limit' => $data['credit_limit'] ?? $existingCustomer->credit_limit,
                        ]);
                        $updated++;
                    } else {
                        $skipped++;
                    }
                } else {
                    Customer::create([
                        'company_id' => $companyId,
                        'name' => $data['name'],
                        'email' => $data['email'] ?? null,
                        'phone' => $data['phone'] ?? null,
                        'address' => $data['address'] ?? null,
                        'credit_limit' => $data['credit_limit'] ?? null,
                        'status' => 'active',
                        'current_balance' => 0
                    ]);
                    $imported++;
                }
            }
            
            fclose($handle);
            
            return response()->json([
                'success' => true,
                'message' => "Import completed: {$imported} added, {$updated} updated, {$skipped} skipped"
            ]);
            
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Import failed: ' . $e->getMessage()
            ], 500);
        }
    }
}