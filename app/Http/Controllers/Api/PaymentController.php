<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Payment;
use App\Models\Customer;
use App\Models\Sale;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\DB;

class PaymentController extends Controller
{
    /**
     * Get all payments for a specific customer
     */
    public function customerPayments(Request $request, $customerId)
    {
        try {
            $companyId = $request->user()->company_id;
            
            // Verify customer belongs to company
            $customer = Customer::where('company_id', $companyId)
                ->findOrFail($customerId);

            $payments = Payment::where('company_id', $companyId)
                ->where('customer_id', $customerId)
                ->with(['user', 'sale'])
                ->with('user')
                ->orderBy('payment_date', 'desc')
                ->orderBy('created_at', 'desc')
                ->get();

            return response()->json([
                'success' => true,
                'data' => $payments
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to fetch payments',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Get all payments (with optional filters)
     */
    public function index(Request $request)
{
    try {
        $companyId = $request->user()->company_id;
        
        $payments = Payment::where('company_id', $companyId)
            ->with(['customer', 'user', 'sale.customer']) // Load sale.customer too!
            ->when($request->customer_id, function($query, $id) {
                return $query->where('customer_id', $id);
            })
            ->when($request->from, function($query, $date) {
                return $query->whereDate('payment_date', '>=', $date);
            })
            ->when($request->to, function($query, $date) {
                return $query->whereDate('payment_date', '<=', $date);
            })
            ->when($request->method, function($query, $method) {
                return $query->where('payment_method', $method);
            })
            ->orderBy('payment_date', 'desc')
            ->orderBy('created_at', 'desc')
            ->paginate($request->per_page ?? 15);

        // Transform the data to ensure customer info is available
        $payments->getCollection()->transform(function ($payment) {
            // If customer is not directly loaded, try to get from sale
            if (!$payment->customer && $payment->sale && $payment->sale->customer) {
                $payment->customer = $payment->sale->customer;
            }
            return $payment;
        });

        return response()->json([
            'success' => true,
            'data' => $payments
        ]);

    } catch (\Exception $e) {
        return response()->json([
            'success' => false,
            'message' => 'Failed to fetch payments',
            'error' => $e->getMessage()
        ], 500);
    }
}

/* /* *
 * Update sale payment status
 */
private function updateSalePaymentStatus($saleId)
{
    $sale = Sale::find($saleId);
    if (!$sale) return;
    
    $totalPaid = Payment::where('sale_id', $saleId)
        ->where('status', 'completed')
        ->sum('amount');
    
    if ($totalPaid >= $sale->total) {
        $sale->payment_status = 'paid';
        $sale->balance_due = 0;
    } else if ($totalPaid > 0) {
        $sale->payment_status = 'partial';
        $sale->balance_due = $sale->total - $totalPaid;
    }
    
    $sale->amount_paid = $totalPaid;
    $sale->save();
} 

    /**
     * Create a new payment
     */
 
   public function store(Request $request)
{
        // Log the incoming request
    \Log::info('Payment creation attempt', [
        'request_data' => $request->all(),
        'user_id' => $request->user()->id,
        'company_id' => $request->user()->company_id
    ]);
    
    $validator = Validator::make($request->all(), [
        'customer_id' => 'required|exists:customers,id',
        'amount' => 'required|numeric|min:0.01',
        'method' => 'required|in:cash,transfer,pos,cheque,credit',
        'reference' => 'nullable|string|max:255',
        'date' => 'required|date',
        'notes' => 'nullable|string',
        'sale_id' => 'nullable|exists:sales,id'
    ]);

    if ($validator->fails()) {
        return response()->json([
            'success' => false,
            'errors' => $validator->errors()
        ], 422);
    }

    try {
        DB::beginTransaction();

        // Define companyId here - it's available in this scope
        $companyId = $request->user()->company_id;
        
        if (!$companyId) {
            return response()->json([
                'success' => false,
                'message' => 'User not associated with a company'
            ], 400);
        }

        // Verify customer belongs to company
        $customer = Customer::where('company_id', $companyId)
            ->where('id', $request->customer_id)
            ->first();
        
        if (!$customer) {
            return response()->json([
                'success' => false,
                'message' => 'Customer not found or does not belong to your company'
            ], 404);
        }

        // Create payment
        $payment = Payment::create([
            'company_id' => $companyId,
            'user_id' => $request->user()->id,
            'customer_id' => $request->customer_id,
            'amount' => $request->amount,
            'payment_method' => $request->method,
                'sale_id' => $request->sale_id ?? null, // Handle nullable sale_id
            'reference' => $request->reference,
            'payment_date' => $request->date, // Use the date from request
            'notes' => $request->notes,
            'status' => 'completed'
        ]);

        
        // Update customer balance (decrease since they're paying)
        $customer->current_balance -= $request->amount;
        $customer->save();

        // If payment is for a specific sale, update that sale's payment status
        if ($request->sale_id) {
            $this->updateSalePaymentStatus($request->sale_id);
        }

        DB::commit();

        return response()->json([
            'success' => true,
            'message' => 'Payment recorded successfully',
            'data' => $payment->load(['customer', 'user', 'sale'])
        ], 201);

    } catch (\Illuminate\Database\QueryException $e) {
        DB::rollBack();
        \Log::error('Database error in payment creation: ' . $e->getMessage());
        return response()->json([
            'success' => false,
            'message' => 'Database error: ' . $e->getMessage()
        ], 500);
    } catch (\Exception $e) {
        DB::rollBack();
        \Log::error('Payment creation failed: ' . $e->getMessage());
        return response()->json([
            'success' => false,
            'message' => 'Failed to record payment: ' . $e->getMessage()
        ], 500);
    }
}



    //     $validator = Validator::make($request->all(), [
    //         'customer_id' => 'required|exists:customers,id',
    //         'sale_id' => 'nullable|exists:sales,id',
    //         'amount' => 'required|numeric|min:0.01',
    //         'method' => 'required|in:cash,transfer,pos,cheque',
    //         'reference' => 'nullable|string|max:255',
    //         'date' => 'required|date',
    //         'notes' => 'nullable|string'
    //     ]);

    //     if ($validator->fails()) {
    //         return response()->json([
    //             'success' => false,
    //             'errors' => $validator->errors()
    //         ], 422);
    //     }

    //     //Verify sale if provided
    //     if($request->sale_id) {
    //         $sale = Sale::where('company_id', $companyId)
    //              ->where('customer_id', $request->customer_id)
    //              ->findofFail($request->sale_id);
    //     }

    //     try {
    //         DB::beginTransaction();

    //         $companyId = $request->user()->company_id;
            
    //         // Verify customer belongs to company
    //         $customer = Customer::where('company_id', $companyId)
    //             ->findOrFail($request->customer_id);

    //         // Create payment
    //         $payment = Payment::create([
    //             'company_id' => $companyId,
    //             'user_id' => $request->user()->id,
    //             'customer_id' => $request->customer_id,
    //              'sale_id' => $request->sale_id,
    //             'amount' => $request->amount,
    //             'payment_method' => $request->method,
    //             'reference' => $request->reference,
    //             'payment_date' => now()->format('Y-m-d'),
    //             'notes' => $request->notes,
    //             'status' => 'completed'
    //         ]);

    //         // Update customer balance (decrease since they're paying)
    //         $customer->current_balance -= $request->amount;
    //         $customer->save();

    //         //if payment is linked to a sale, you could also update the sale's paid amount or status here
    //         if($request->sale_id) {
    //             $this->updateSalePaymentStatus($sale);
    //         }

    //         // Create transaction record (optional - if you have transactions table)
    //         // You can add inventory transaction here if needed

    //         DB::commit();

    //         return response()->json([
    //             'success' => true,
    //             'message' => 'Payment recorded successfully',
    //             'data' => $payment->load(['customer', 'user'])
    //         ], 201);

    //     } catch (\Exception $e) {
    //         DB::rollBack();
    //         return response()->json([
    //             'success' => false,
    //             'message' => 'Failed to record payment',
    //             'error' => $e->getMessage()
    //         ], 500);
    //     }
    // }

    // /**
    //  * Get a single payment
    //  */
    public function show(Request $request, $id)
    {
        try {
            $payment = Payment::where('company_id', $request->user()->company_id)
                ->with(['customer', 'user'])
                ->findOrFail($id);

            return response()->json([
                'success' => true,
                'data' => $payment
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Payment not found'
            ], 404);
        }
    }

    /**
     * Update a payment
     */
    public function update(Request $request, $id)
    {
        $validator = Validator::make($request->all(), [
            'amount' => 'sometimes|required|numeric|min:0.01',
            'method' => 'sometimes|required|in:cash,transfer,pos,cheque',
            'reference' => 'nullable|string|max:255',
            'date' => 'sometimes|required|date',
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

            $payment = Payment::where('company_id', $request->user()->company_id)
                ->with('customer')
                ->findOrFail($id);

            $oldAmount = $payment->amount;
            
            // Update payment
            $payment->fill($request->only([
                'amount', 'payment_method', 'reference', 'payment_date', 'notes'
            ]));
            
            // Handle status if needed
            if ($request->has('status')) {
                $payment->status = $request->status;
            }
            
            $payment->save();

            // Adjust customer balance if amount changed
            if ($request->has('amount') && $request->amount != $oldAmount) {
                $customer = $payment->customer;
                $customer->current_balance += $oldAmount; // Add back old amount
                $customer->current_balance -= $request->amount; // Subtract new amount
                $customer->save();
            }

            DB::commit();

            return response()->json([
                'success' => true,
                'message' => 'Payment updated successfully',
                'data' => $payment->load(['customer', 'user'])
            ]);

        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json([
                'success' => false,
                'message' => 'Failed to update payment',
                'error' => $e->getMessage()
            ], 500);
        }
    }



    /**
     * Delete a payment
     */
    public function destroy(Request $request, $id)
    {
        try {
            DB::beginTransaction();

            $payment = Payment::where('company_id', $request->user()->company_id)
                ->with('customer')
                ->findOrFail($id);

            // Restore customer balance
            $customer = $payment->customer;
            $customer->current_balance += $payment->amount;
            $customer->save();

            // Delete payment
            $payment->delete();

            DB::commit();

            return response()->json([
                'success' => true,
                'message' => 'Payment deleted successfully'
            ]);

        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json([
                'success' => false,
                'message' => 'Failed to delete payment',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Get payment summary for a date range
     */
    public function summary(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'from' => 'required|date',
            'to' => 'required|date|after_or_equal:from'
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'errors' => $validator->errors()
            ], 422);
        }

        try {
            $companyId = $request->user()->company_id;

            $payments = Payment::where('company_id', $companyId)
                ->whereBetween('payment_date', [$request->from, $request->to])
                ->get();

            $summary = [
                'period' => [
                    'from' => $request->from,
                    'to' => $request->to
                ],
                'total_payments' => $payments->count(),
                'total_amount' => $payments->sum('amount'),
                'by_method' => $payments->groupBy('payment_method')
                    ->map(function($group) {
                        return [
                            'count' => $group->count(),
                            'amount' => $group->sum('amount')
                        ];
                    }),
                'by_customer' => $payments->groupBy('customer_id')
                    ->map(function($group) {
                        $customer = $group->first()->customer;
                        return [
                            'customer_name' => $customer->name ?? 'Unknown',
                            'count' => $group->count(),
                            'amount' => $group->sum('amount')
                        ];
                    })->sortByDesc('amount')->take(10)
            ];

            return response()->json([
                'success' => true,
                'data' => $summary
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to generate payment summary',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Get outstanding payments (customers with balance)
     */
    public function outstanding(Request $request)
    {
        try {
            $companyId = $request->user()->company_id;

            $customers = Customer::where('company_id', $companyId)
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
                'message' => 'Failed to fetch outstanding payments',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Get payment methods statistics
     */
   /**
 * Get payment methods statistics
 */
public function methodsStats(Request $request)
{
    try {
        $companyId = $request->user()->company_id;
        
        // Check if companyId exists
        if (!$companyId) {
            return response()->json([
                'success' => false,
                'message' => 'User not associated with a company'
            ], 400);
        }
        
        // Get stats grouped by payment_method
        $stats = Payment::where('company_id', $companyId)
            ->select('payment_method', 
                DB::raw('count(*) as count'), 
                DB::raw('COALESCE(sum(amount), 0) as total'))
            ->groupBy('payment_method')
            ->get();

        // Format the response
        $formattedStats = $stats->map(function($stat) {
            return [
                'payment_method' => $stat->payment_method ?? 'unknown',
                'count' => (int) $stat->count,
                'total' => (float) $stat->total
            ];
        });

        return response()->json([
            'success' => true,
            'data' => $formattedStats
        ]);

    } catch (\Exception $e) {
        \Log::error('Payment methods stats error: ' . $e->getMessage(), [
            'trace' => $e->getTraceAsString()
        ]);
        
        return response()->json([
            'success' => false,
            'message' => 'Failed to fetch payment stats: ' . $e->getMessage()
        ], 500);
    }
}

    /**
     * Reverse a payment (for corrections)
     */
    public function reverse(Request $request, $id)
    {
        $validator = Validator::make($request->all(), [
            'reason' => 'required|string|max:255'
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'errors' => $validator->errors()
            ], 422);
        }

        try {
            DB::beginTransaction();

            $payment = Payment::where('company_id', $request->user()->company_id)
                ->with('customer')
                ->findOrFail($id);

            if ($payment->status === 'reversed') {
                return response()->json([
                    'success' => false,
                    'message' => 'Payment already reversed'
                ], 422);
            }

            // Reverse customer balance
            $customer = $payment->customer;
            $customer->current_balance += $payment->amount;
            $customer->save();

            // Update payment status
            $payment->status = 'reversed';
            $payment->notes = ($payment->notes ? $payment->notes . "\n" : '') 
                . "Reversed: " . $request->reason . " on " . now()->toDateTimeString();
            $payment->save();

            // Create a reversal record (optional)
            // You could create a negative payment or log entry

            DB::commit();

            return response()->json([
                'success' => true,
                'message' => 'Payment reversed successfully',
                'data' => $payment
            ]);

        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json([
                'success' => false,
                'message' => 'Failed to reverse payment',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Process bulk payments
     */
    public function bulkStore(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'payments' => 'required|array|min:1',
            'payments.*.customer_id' => 'required|exists:customers,id',
            'payments.*.amount' => 'required|numeric|min:0.01',
            'payments.*.method' => 'required|in:cash,transfer,pos,cheque',
            'payments.*.date' => 'required|date',
            'payments.*.reference' => 'nullable|string',
            'payments.*.notes' => 'nullable|string'
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'errors' => $validator->errors()
            ], 422);
        }

        try {
            DB::beginTransaction();

            $companyId = $request->user()->company_id;
            $createdPayments = [];

            foreach ($request->payments as $paymentData) {
                // Verify customer belongs to company
                $customer = Customer::where('company_id', $companyId)
                    ->findOrFail($paymentData['customer_id']);

                $payment = Payment::create([
                    'company_id' => $companyId,
                    'user_id' => $request->user()->id,
                    'customer_id' => $paymentData['customer_id'],
                    'amount' => $paymentData['amount'],
                    'payment_method' => $paymentData['method'],
                    'reference' => $paymentData['reference'] ?? null,
                    'payment_date' => $paymentData['date'],
                    'notes' => $paymentData['notes'] ?? null,
                    'status' => 'completed'
                ]);

                // Update customer balance
                $customer->current_balance -= $paymentData['amount'];
                $customer->save();

                $createdPayments[] = $payment;
            }

            DB::commit();

            return response()->json([
                'success' => true,
                'message' => count($createdPayments) . ' payments recorded successfully',
                'data' => $createdPayments
            ], 201);

        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json([
                'success' => false,
                'message' => 'Failed to process bulk payments',
                'error' => $e->getMessage()
            ], 500);
        }
    }
}