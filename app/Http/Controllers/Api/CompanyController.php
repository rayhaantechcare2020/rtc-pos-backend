<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Company;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class CompanyController extends Controller
{
    /**
     * Get company details (for logged-in user's company)
     */
    public function show(Request $request)
    {
        $company = $request->user()->company;
        
        if (!$company) {
            return response()->json([
                'success' => false,
                'message' => 'Company not found'
            ], 404);
        }

        return response()->json([
            'success' => true,
            'data' => $company
        ]);
    }

    /**
     * Update company details
     */
    public function update(Request $request)
    {
        $company = $request->user()->company;
        
        if (!$company) {
            return response()->json([
                'success' => false,
                'message' => 'Company not found'
            ], 404);
        }

        // Only admin can update company
        if (!$request->user()->isAdmin()) {
            return response()->json([
                'success' => false,
                'message' => 'Unauthorized. Only admin can update company.'
            ], 403);
        }

        $validator = Validator::make($request->all(), [
            'name' => 'sometimes|string|max:255',
            'email' => 'sometimes|email|unique:companies,email,' . $company->id,
            'phone' => 'nullable|string|max:20',
            'address' => 'nullable|string',
            'logo' => 'nullable|string',
            'website' => 'nullable|url',
            'tax_number' => 'nullable|string|max:50',
            'registration_number' => 'nullable|string|max:50',
            'currency' => 'nullable|string|max:10',
            'currency_code' => 'nullable|string|max:3',
            'timezone' => 'nullable|string',
            'date_format' => 'nullable|string',
            'settings' => 'nullable|array',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'errors' => $validator->errors()
            ], 422);
        }

        $company->update($request->all());

        return response()->json([
            'success' => true,
            'message' => 'Company updated successfully',
            'data' => $company
        ]);
    }

    /**
     * Upload company logo
     */
    public function uploadLogo(Request $request)
    {
        $company = $request->user()->company;

        $validator = Validator::make($request->all(), [
            'logo' => 'required|image|mimes:jpeg,png,jpg,gif|max:2048'
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'errors' => $validator->errors()
            ], 422);
        }

        if ($request->hasFile('logo')) {
            $path = $request->file('logo')->store('company-logos', 'public');
            $company->logo = $path;
            $company->save();

            return response()->json([
                'success' => true,
                'message' => 'Logo uploaded successfully',
                'data' => ['logo_url' => asset('storage/' . $path)]
            ]);
        }

        return response()->json([
            'success' => false,
            'message' => 'No logo file provided'
        ], 400);
    }

    /**
     * Get company settings
     */
    public function getSettings(Request $request)
    {
        $company = $request->user()->company;
        
        return response()->json([
            'success' => true,
            'data' => [
                'company' => $company,
                'settings' => $company->settings ?? []
            ]
        ]);
    }

    /**
     * Update company settings
     */
    public function updateSettings(Request $request)
    {
        $company = $request->user()->company;

        $validator = Validator::make($request->all(), [
            'settings' => 'required|array'
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'errors' => $validator->errors()
            ], 422);
        }

        $company->settings = array_merge($company->settings ?? [], $request->settings);
        $company->save();

        return response()->json([
            'success' => true,
            'message' => 'Settings updated successfully',
            'data' => $company->settings
        ]);
    }

    /**
 * Get printer settings
 */
public function getPrinterSettings(Request $request)
{
    try {
        $company = $request->user()->company;
        
        // Get printer settings from company settings JSON
        $settings = $company->settings ?? [];
        $printerSettings = $settings['printer'] ?? [
            'default_printer_type' => 'thermal', // thermal, a4, both
            'thermal_width' => '80mm', // 58mm, 80mm
            'auto_print' => true,
            'print_copies' => 1,
            'paper_size' => 'A4',
            'print_logo' => true,
            'print_barcode' => true
        ];

        return response()->json([
            'success' => true,
            'data' => $printerSettings
        ]);

    } catch (\Exception $e) {
        return response()->json([
            'success' => false,
            'message' => 'Failed to fetch printer settings',
            'error' => $e->getMessage()
        ], 500);
    }
}

/**
 * Update printer settings
 */
public function updatePrinterSettings(Request $request)
{
    $validator = Validator::make($request->all(), [
        'default_printer_type' => 'required|in:thermal,a4,both',
        'thermal_width' => 'required|in:58mm,80mm',
        'auto_print' => 'boolean',
        'print_copies' => 'integer|min:1|max:5',
        'paper_size' => 'string|in:A4,A5,Letter',
        'print_logo' => 'boolean',
        'print_barcode' => 'boolean'
    ]);

    if ($validator->fails()) {
        return response()->json([
            'success' => false,
            'errors' => $validator->errors()
        ], 422);
    }

    try {
        $company = $request->user()->company;
        
        // Get current settings or initialize
        $settings = $company->settings ?? [];
        $settings['printer'] = $request->all();
        
        $company->settings = $settings;
        $company->save();

        return response()->json([
            'success' => true,
            'message' => 'Printer settings updated successfully',
            'data' => $settings['printer']
        ]);

    } catch (\Exception $e) {
        return response()->json([
            'success' => false,
            'message' => 'Failed to update printer settings',
            'error' => $e->getMessage()
        ], 500);
    }
}
}