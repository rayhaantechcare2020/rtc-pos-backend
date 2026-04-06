<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Customer;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class CustomerController extends Controller
{
    /**
     * Display a listing of customers for the authenticated user's company.
     */
    public function index(Request $request)
    {
        try {
            $companyId = $request->user()->company_id;
            
            $customers = Customer::where('company_id', $companyId)
                ->when($request->search, function($query, $search) {
                    return $query->where(function($q) use ($search) {
                        $q->where('name', 'like', "%{$search}%")
                          ->orWhere('email', 'like', "%{$search}%")
                          ->orWhere('phone', 'like', "%{$search}%");
                    });
                })
                ->when($request->status, function($query, $status) {
                    return $query->where('status', $status);
                })
                ->when($request->has_balance, function($query) {
                    return $query->where('current_balance', '>', 0);
                })
                ->orderBy($request->sort_by ?? 'name', $request->sort_dir ?? 'asc')
                ->paginate($request->per_page ?? 15);

            return response()->json([
                'success' => true,
                'data' => $customers
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to fetch customers',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Store a newly created customer.
     */
    public function store(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'name' => 'required|string|max:255',
            'email' => 'nullable|email|max:255|unique:customers,email,NULL,id,company_id,' . $request->user()->company_id,
            'phone' => 'nullable|string|max:20',
            'address' => 'nullable|string',
            'tax_number' => 'nullable|string|max:50',
            'credit_limit' => 'nullable|numeric|min:0',
            'notes' => 'nullable|string',
            'status' => 'nullable|in:active,inactive'
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'errors' => $validator->errors()
            ], 422);
        }

        try {
            // Check if customer with same email exists for this company
            if ($request->email) {
                $existingCustomer = Customer::where('company_id', $request->user()->company_id)
                    ->where('email', $request->email)
                    ->first();

                if ($existingCustomer) {
                    return response()->json([
                        'success' => false,
                        'message' => 'A customer with this email already exists'
                    ], 422);
                }
            }

            $customer = Customer::create([
                'company_id' => $request->user()->company_id,
                'name' => $request->name,
                'email' => $request->email,
                'phone' => $request->phone,
                'address' => $request->address,
                'tax_number' => $request->tax_number,
                'credit_limit' => $request->credit_limit,
                'current_balance' => 0,
                'notes' => $request->notes,
                'status' => $request->status ?? 'active'
            ]);

            return response()->json([
                'success' => true,
                'message' => 'Customer created successfully',
                'data' => $customer
            ], 201);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to create customer',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Display the specified customer.
     */
    public function show(Request $request, $id)
    {
        try {
            $customer = Customer::where('company_id', $request->user()->company_id)
                ->with(['sales' => function($query) {
                    $query->orderBy('created_at', 'desc')->limit(10);
                }])
                ->findOrFail($id);

            return response()->json([
                'success' => true,
                'data' => $customer
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Customer not found'
            ], 404);
        }
    }

    /**
     * Update the specified customer.
     */
    public function update(Request $request, $id)
    {
        $validator = Validator::make($request->all(), [
            'name' => 'sometimes|required|string|max:255',
            'email' => 'nullable|email|max:255',
            'phone' => 'nullable|string|max:20',
            'address' => 'nullable|string',
            'tax_number' => 'nullable|string|max:50',
            'credit_limit' => 'nullable|numeric|min:0',
            'notes' => 'nullable|string',
            'status' => 'nullable|in:active,inactive'
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'errors' => $validator->errors()
            ], 422);
        }

        try {
            $customer = Customer::where('company_id', $request->user()->company_id)
                ->findOrFail($id);

            // Check email uniqueness if being updated
            if ($request->has('email') && $request->email !== $customer->email) {
                $existingCustomer = Customer::where('company_id', $request->user()->company_id)
                    ->where('email', $request->email)
                    ->where('id', '!=', $id)
                    ->first();

                if ($existingCustomer) {
                    return response()->json([
                        'success' => false,
                        'message' => 'A customer with this email already exists'
                    ], 422);
                }
            }

            $customer->update($request->all());

            return response()->json([
                'success' => true,
                'message' => 'Customer updated successfully',
                'data' => $customer
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to update customer',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Remove the specified customer.
     */
    public function destroy(Request $request, $id)
    {
        try {
            $customer = Customer::where('company_id', $request->user()->company_id)
                ->findOrFail($id);

            // Check if customer has sales
            if ($customer->sales()->count() > 0) {
                return response()->json([
                    'success' => false,
                    'message' => 'Cannot delete customer with sales history'
                ], 422);
            }

            // Check if customer has balance
            if ($customer->current_balance > 0) {
                return response()->json([
                    'success' => false,
                    'message' => 'Cannot delete customer with outstanding balance'
                ], 422);
            }

            $customer->delete();

            return response()->json([
                'success' => true,
                'message' => 'Customer deleted successfully'
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to delete customer',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Get customers list for dropdown (simplified).
     */
    public function list(Request $request)
    {
        try {
            $customers = Customer::where('company_id', $request->user()->company_id)
                ->where('status', 'active')
                ->orderBy('name')
                ->get(['id', 'name', 'email', 'phone', 'current_balance', 'credit_limit']);

            return response()->json([
                'success' => true,
                'data' => $customers
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to fetch customers list',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Update customer balance.
     */
    public function updateBalance(Request $request, $id)
    {
        $validator = Validator::make($request->all(), [
            'amount' => 'required|numeric',
            'type' => 'required|in:add,subtract,set'
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'errors' => $validator->errors()
            ], 422);
        }

        try {
            $customer = Customer::where('company_id', $request->user()->company_id)
                ->findOrFail($id);

            $oldBalance = $customer->current_balance;

            switch ($request->type) {
                case 'add':
                    $customer->current_balance += $request->amount;
                    break;
                case 'subtract':
                    $customer->current_balance -= $request->amount;
                    break;
                case 'set':
                    $customer->current_balance = $request->amount;
                    break;
            }

            // Ensure balance doesn't go below 0
            if ($customer->current_balance < 0) {
                return response()->json([
                    'success' => false,
                    'message' => 'Balance cannot be negative'
                ], 422);
            }

            // Check credit limit if applicable
            if ($customer->credit_limit && $customer->current_balance > $customer->credit_limit) {
                return response()->json([
                    'success' => false,
                    'message' => 'Balance exceeds credit limit'
                ], 422);
            }

            $customer->save();

            return response()->json([
                'success' => true,
                'message' => 'Balance updated successfully',
                'data' => [
                    'old_balance' => $oldBalance,
                    'new_balance' => $customer->current_balance
                ]
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to update balance',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Get customers with outstanding balance.
     */
    public function outstandingBalance(Request $request)
    {
        try {
            $customers = Customer::where('company_id', $request->user()->company_id)
                ->where('current_balance', '>', 0)
                ->orderBy('current_balance', 'desc')
                ->get(['id', 'name', 'email', 'phone', 'current_balance', 'credit_limit']);

            $totalOutstanding = $customers->sum('current_balance');

            return response()->json([
                'success' => true,
                'data' => [
                    'total_outstanding' => $totalOutstanding,
                    'customers' => $customers
                ]
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to fetch outstanding balances',
                'error' => $e->getMessage()
            ], 500);
        }
    }
}