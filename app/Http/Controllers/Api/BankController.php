<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Bank;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class BankController extends Controller
{
    public function index()
    {
        $banks = Bank::active()->ordered()->get();
        return response()->json(['data' => $banks]);
    }

    public function store(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'name' => 'required|string|max:255|unique:banks',
            'account_name' => 'required|string|max:255',
            'account_number' => 'required|string|max:50',
            'bank_code' => 'nullable|string|max:50',
            'branch' => 'nullable|string|max:255',
            'sort_order' => 'nullable|integer'
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        $bank = Bank::create($request->all());

        return response()->json([
            'success' => true,
            'message' => 'Bank added successfully',
            'data' => $bank
        ], 201);
    }

    public function update(Request $request, $id)
    {
        $bank = Bank::findOrFail($id);

        $validator = Validator::make($request->all(), [
            'name' => 'sometimes|string|max:255|unique:banks,name,' . $id,
            'account_name' => 'sometimes|string|max:255',
            'account_number' => 'sometimes|string|max:50',
            'bank_code' => 'nullable|string|max:50',
            'branch' => 'nullable|string|max:255',
            'is_active' => 'sometimes|boolean',
            'sort_order' => 'nullable|integer'
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        $bank->update($request->all());

        return response()->json([
            'success' => true,
            'message' => 'Bank updated successfully',
            'data' => $bank
        ]);
    }

    public function destroy($id)
    {
        $bank = Bank::findOrFail($id);
        
        // Check if bank has sales
        if ($bank->sales()->count() > 0) {
            return response()->json([
                'success' => false,
                'message' => 'Cannot delete bank with existing sales records'
            ], 400);
        }

        $bank->delete();

        return response()->json([
            'success' => true,
            'message' => 'Bank deleted successfully'
        ]);
    }
}