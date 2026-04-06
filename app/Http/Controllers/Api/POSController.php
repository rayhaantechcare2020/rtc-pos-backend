<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Sale;
use App\Models\SaleItem;
use App\Models\Product;
use App\Models\InventoryTransaction;
use App\Models\Customer;
use App\Models\Payment;
//use App\Services\ReceiptService;
//use App\Models\Company;
use App\Models\User;
use App\Models\Bank;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Log;

class POSController extends Controller
{
    /**
     * Get today's sales summary
     */
    public function today(Request $request)
    {
        try {
            $companyId = $request->user()->company_id;
            $today = now()->format('Y-m-d');
            
            $sales = Sale::where('company_id', $companyId)
                ->whereDate('created_at', $today)
                ->with('items', 'payments')
                ->get();
            
            $summary = [
                'total_sales' => $sales->count(),
                'total_revenue' => $sales->sum('total'),
                'total_transactions' => $sales->count(), 
                'total_profit' => $sales->sum(function($sale) {
                    return $sale->items->sum(function($item) {
                        return ($item->price - $item->cost) * $item->quantity;
                    });
                }),
                'payment_breakdown' => [
                    'cash' => $sales->filter(function($sale) {
                        return $sale->payments->where('payment_method', 'cash')->count() > 0;
                    })->sum('total'),
                    'transfer' => $sales->filter(function($sale) {
                        return $sale->payments->where('payment_method', 'transfer')->count() > 0;
                    })->sum('total'),
                    'pos' => $sales->filter(function($sale) {
                        return $sale->payments->where('payment_method', 'pos')->count() > 0;
                    })->sum('total'),
                ]
            ];
            
            return response()->json([
                'success' => true,
                'data' => $summary
            ]);
            
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to fetch today\'s sales',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    // app/Http/Controllers/Api/POSController.php - Add these methods

/**
 * Hold current sale
 */
public function holdSale(Request $request)
{
    $validator = Validator::make($request->all(), [
        'cart_items' => 'required|array',
        'cart_items.items' => 'required|array',
        'cart_items.subtotal' => 'required|numeric',
        'cart_items.discount' => 'nullable|numeric',
        'cart_items.total' => 'required|numeric',
        'customer_id' => 'nullable|exists:customers,id',
        'customer_name' => 'nullable|string',
        'customer_phone' => 'nullable|string',
        'notes' => 'nullable|string',
        'expires_in_hours' => 'nullable|integer|min:1|max:168' // Max 7 days
    ]);

    if ($validator->fails()) {
        return response()->json([
            'success' => false,
            'errors' => $validator->errors()
        ], 422);
    }

    try {
        $companyId = $request->user()->company_id;
        
        // Calculate expiry time (default 24 hours)
        $expiresInHours = $request->expires_in_hours ?? 24;
        $expiresAt = now()->addHours($expiresInHours);
        
        // Generate unique hold reference
        $holdReference = \App\Models\HoldSale::generateHoldReference();
        
        // Create hold sale record
        $holdSale = \App\Models\HoldSale::create([
            'company_id' => $companyId,
            'user_id' => $request->user()->id,
            'customer_id' => $request->customer_id,
            'hold_reference' => $holdReference,
            'customer_name' => $request->customer_name ?? 'Walk-in Customer',
            'customer_phone' => $request->customer_phone,
            'cart_items' => $request->cart_items,
            'subtotal' => $request->cart_items['subtotal'],
            'discount' => $request->cart_items['discount'] ?? 0,
            'total' => $request->cart_items['total'],
            'notes' => $request->notes,
            'held_at' => now(),
            'expires_at' => $expiresAt,
            'status' => 'active'
        ]);
        
        \Log::info('Sale held successfully', [
            'hold_reference' => $holdReference,
            'user_id' => $request->user()->id,
            'total' => $request->cart_items['total']
        ]);
        
        return response()->json([
            'success' => true,
            'message' => 'Sale held successfully',
            'data' => [
                'hold_reference' => $holdReference,
                'expires_at' => $expiresAt,
                'hold_id' => $holdSale->id
            ]
        ]);
        
    } catch (\Exception $e) {
        \Log::error('Hold sale failed: ' . $e->getMessage());
        return response()->json([
            'success' => false,
            'message' => 'Failed to hold sale: ' . $e->getMessage()
        ], 500);
    }
}

/**
 * Get all held sales
 */
public function getHeldSales(Request $request)
{
    try {
        $companyId = $request->user()->company_id;
        
        $heldSales = \App\Models\HoldSale::where('company_id', $companyId)
            ->where('status', 'active')
            ->where(function($q) {
                $q->whereNull('expires_at')
                  ->orWhere('expires_at', '>', now());
            })
            ->with(['customer', 'user'])
            ->orderBy('held_at', 'desc')
            ->get();
        
        return response()->json([
            'success' => true,
            'data' => $heldSales
        ]);
        
    } catch (\Exception $e) {
        return response()->json([
            'success' => false,
            'message' => 'Failed to fetch held sales'
        ], 500);
    }
}

/**
 * Get single held sale by reference
 */
public function getHeldSale(Request $request, $reference)
{
    try {
        $companyId = $request->user()->company_id;
        
        $heldSale = \App\Models\HoldSale::where('company_id', $companyId)
            ->where('hold_reference', $reference)
            ->where('status', 'active')
            ->first();
        
        if (!$heldSale) {
            return response()->json([
                'success' => false,
                'message' => 'Held sale not found or expired'
            ], 404);
        }
        
        // Check if expired
        if ($heldSale->expires_at && $heldSale->expires_at <= now()) {
            $heldSale->status = 'expired';
            $heldSale->save();
            
            return response()->json([
                'success' => false,
                'message' => 'This held sale has expired',
                'expired' => true
            ], 410);
        }
        
        return response()->json([
            'success' => true,
            'data' => $heldSale
        ]);
        
    } catch (\Exception $e) {
        return response()->json([
            'success' => false,
            'message' => 'Failed to fetch held sale'
        ], 500);
    }
}

/**
 * Restore held sale to cart
 */
public function restoreHeldSale(Request $request, $reference)
{
    try {
        $companyId = $request->user()->company_id;
        
        $heldSale = \App\Models\HoldSale::where('company_id', $companyId)
            ->where('hold_reference', $reference)
            ->where('status', 'active')
            ->first();
        
        if (!$heldSale) {
            return response()->json([
                'success' => false,
                'message' => 'Held sale not found'
            ], 404);
        }
        
        // Check if expired
        if ($heldSale->expires_at && $heldSale->expires_at <= now()) {
            $heldSale->status = 'expired';
            $heldSale->save();
            
            return response()->json([
                'success' => false,
                'message' => 'This held sale has expired',
                'expired' => true
            ], 410);
        }
        
        // Get cart items
        $cartData = $heldSale->cart_items;
        
        return response()->json([
            'success' => true,
            'message' => 'Sale restored successfully',
            'data' => [
                'cart' => $cartData,
                'customer' => [
                    'id' => $heldSale->customer_id,
                    'name' => $heldSale->customer_name,
                    'phone' => $heldSale->customer_phone
                ],
                'notes' => $heldSale->notes,
                'hold_reference' => $heldSale->hold_reference,
                'held_at' => $heldSale->held_at
            ]
        ]);
        
    } catch (\Exception $e) {
        \Log::error('Restore held sale failed: ' . $e->getMessage());
        return response()->json([
            'success' => false,
            'message' => 'Failed to restore held sale'
        ], 500);
    }
}

/**
 * Delete/Cancel held sale
 */
public function deleteHeldSale(Request $request, $reference)
{
    try {
        $companyId = $request->user()->company_id;
        
        $heldSale = \App\Models\HoldSale::where('company_id', $companyId)
            ->where('hold_reference', $reference)
            ->first();
        
        if (!$heldSale) {
            return response()->json([
                'success' => false,
                'message' => 'Held sale not found'
            ], 404);
        }
        
        $heldSale->status = 'expired';
        $heldSale->save();
        
        return response()->json([
            'success' => true,
            'message' => 'Held sale cancelled successfully'
        ]);
        
    } catch (\Exception $e) {
        return response()->json([
            'success' => false,
            'message' => 'Failed to cancel held sale'
        ], 500);
    }
}

/**
 * Mark held sale as converted (after checkout)
 */
public function convertHeldSale(Request $request, $reference)
{
    try {
        $companyId = $request->user()->company_id;
        
        $heldSale = \App\Models\HoldSale::where('company_id', $companyId)
            ->where('hold_reference', $reference)
            ->first();
        
        if ($heldSale) {
            $heldSale->status = 'converted';
            $heldSale->save();
        }
        
        return response()->json(['success' => true]);
        
    } catch (\Exception $e) {
        return response()->json(['success' => false], 500);
    }
}

    /**
     * Process a new sale (checkout)
     */
public function checkout(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'items' => 'required|array|min:1',
            'items.*.product_id' => 'required|integer|exists:products,id',
            'items.*.quantity' => 'required|integer|min:1',
            'items.*.price' => 'required|numeric|min:0',
            'customer_id' => 'nullable|integer|exists:customers,id',
            'customer_name' => 'required_without:customer_id|string|max:255',
            'customer_phone' => 'nullable|string',
            'payments' => 'required|array|min:1',
            'payments.*.method' => 'required|in:cash,bank,transfer,pos,credit',
            'payments.*.amount' => 'required|numeric|min:0',
            'payments.*.bank_id' => 'required_if:payments.*.method,bank,transfer|nullable|exists:banks,id',
            'payments.*.transaction_reference' => 'required_if:payments.*.method,bank,transfer|nullable|string|max:255',
            'discount' => 'nullable|numeric|min:0',
            'notes' => 'nullable|string',
            'total' => 'required|numeric|min:0'
        ]);

        if ($validator->fails()) {
            Log::error('Checkout validation failed:', $validator->errors()->toArray());
            return response()->json([
                'success' => false,
                'errors' => $validator->errors()
            ], 422);
        }

        try {
            DB::beginTransaction();

            $companyId = $request->user()->company_id;
            if (!$companyId) {
                return response()->json([
                    'success' => false,
                    'message' => 'User not assigned to a company'
                ], 400);
            }

            // Handle customer
            if ($request->customer_id) {
                $customer = Customer::where('id', $request->customer_id)
                    ->where('company_id', $companyId)
                    ->first();
                    
                if (!$customer) {
                    return response()->json([
                        'success' => false,
                        'message' => 'Customer not found'
                    ], 404);
                }
            } else {
                // Find or create Walk-in Customer
                $customer = Customer::where('company_id', $companyId)
                    ->where('name', 'Walk-in Customer')
                    ->first();
                
                if (!$customer) {
                    $customer = Customer::create([
                        'company_id' => $companyId,
                        'name' => $request->customer_name ?? 'Walk-in Customer',
                        'phone' => $request->customer_phone,
                        'status' => 'active',
                        'current_balance' => 0
                    ]);
                }
            }

            // Check credit limit if credit payment exists
            $hasCreditPayment = collect($request->payments)->contains('method', 'credit');
            $totalCreditAmount = collect($request->payments)
                ->where('method', 'credit')
                ->sum('amount');
            
            if ($hasCreditPayment && $customer->credit_limit) {
                $newBalance = $customer->current_balance + $totalCreditAmount;
                if ($newBalance > $customer->credit_limit) {
                    return response()->json([
                        'success' => false,
                        'message' => "Credit limit exceeded. Current balance: ₦{$customer->current_balance}, Limit: ₦{$customer->credit_limit}"
                    ], 422);
                }
            }

            // Calculate totals
            $subtotal = 0;
            $totalItems = 0;
            $itemsData = [];

            foreach ($request->items as $item) {
                $product = Product::where('id', $item['product_id'])
                    ->where('company_id', $companyId)
                    ->first();
                    
                if (!$product) {
                    throw new \Exception("Product not found: ID {$item['product_id']}");
                }
                
                // Check stock
                if ($product->stock_quantity < $item['quantity']) {
                    throw new \Exception("Insufficient stock for {$product->name}");
                }

                $itemSubtotal = $item['price'] * $item['quantity'];
                $subtotal += $itemSubtotal;
                $totalItems += $item['quantity'];

                $itemsData[] = [
                    'product' => $product,
                    'quantity' => $item['quantity'],
                    'price' => $item['price'],
                    'cost' => $product->cost,
                    'subtotal' => $itemSubtotal
                ];
            }

            $discount = $request->discount ?? 0;
            $total = $request->total ?? ($subtotal - $discount);

            // Process payments
            $totalPaid = 0;
            $creditAmount = 0;
            $paymentMethods = [];
            $bankPayments = [];
            
            foreach ($request->payments as $payment) {
                $totalPaid += $payment['amount'];
                $paymentMethods[] = $payment['method'];
                
                if ($payment['method'] === 'credit') {
                    $creditAmount += $payment['amount'];
                }
                
                if (in_array($payment['method'], ['bank', 'transfer'])) {
                    $bankPayments[] = [
                        'bank_id' => $payment['bank_id'],
                        'transaction_reference' => $payment['transaction_reference'],
                        'amount' => $payment['amount']
                    ];
                }
            }

            $changeDue = max(0, $totalPaid - $total);
            $balanceDue = max(0, $total - $totalPaid);
            $paymentStatus = $balanceDue > 0 ? 'partial' : 'paid';
            $isSplitPayment = count($request->payments) > 1;

            Log::info('Checkout calculation:', [
                'subtotal' => $subtotal,
                'total' => $total,
                'totalPaid' => $totalPaid,
                'balanceDue' => $balanceDue,
                'isSplitPayment' => $isSplitPayment,
                'payments' => $request->payments
            ]);

            // Generate invoice number
            $lastSale = Sale::where('company_id', $companyId)->latest()->first();
            $lastNumber = $lastSale ? intval(substr($lastSale->invoice_number, -4)) : 0;
            $invoiceNumber = 'INV-' . date('Ymd') . '-' . str_pad($lastNumber + 1, 4, '0', STR_PAD_LEFT);

            // Create sale
            $sale = Sale::create([
                'company_id' => $companyId,
                'user_id' => $request->user()->id,
                'customer_id' => $customer->id,
                'invoice_number' => $invoiceNumber,
                'sale_date' => now(),
                'sale_time' => now(),
                'item_count' => $totalItems,
                'subtotal' => $subtotal,
                'discount' => $discount,
                'total' => $total,
                'amount_paid' => $totalPaid,
                'change_due' => $changeDue,
                'payment_status' => $paymentStatus,
                'sale_date' => now()->format('Y-m-d'),
                //'payment_date' => now()->format('Y-m-d'),
                'status' => 'completed',
                'balance_due' => $balanceDue,
                'method' => $isSplitPayment ? 'split' : ($request->payments[0]['method'] ?? 'cash'),
                'is_split_payment' => $isSplitPayment,
                'notes' => $request->notes
            ]);

            Log::info('Sale created:', [
                'sale_id' => $sale->id,
                'invoice_number' => $sale->invoice_number,
                'is_split_payment' => $isSplitPayment
            ]);

            // Create sale items and update stock
            foreach ($itemsData as $itemData) {
                $product = $itemData['product'];
                
                SaleItem::create([
                    'sale_id' => $sale->id,
                    'product_id' => $product->id,
                    'quantity' => $itemData['quantity'],
                    'price' => $itemData['price'],
                    'cost' => $itemData['cost'],
                    'subtotal' => $itemData['subtotal'],
                    'total' => $itemData['subtotal'],
                    'product_name' => $product->name,
                    'product_sku' => $product->sku
                ]);

                // Update stock
                $oldStock = $product->stock_quantity;
                $product->stock_quantity -= $itemData['quantity'];
                $product->save();

                // Create inventory transaction
                InventoryTransaction::create([
                    'company_id' => $companyId,
                    'product_id' => $product->id,
                    'user_id' => $request->user()->id,
                    'type' => 'sale',
                    'quantity' => -$itemData['quantity'],
                    'before_quantity' => $oldStock,
                    'after_quantity' => $product->stock_quantity,
                    'reference_type' => 'sale',
                    'reference_id' => $sale->id,
                    'notes' => "Sold {$itemData['quantity']} units via POS"
                ]);
            }

            // Create individual payment records
            foreach ($request->payments as $payment) {
                $paymentData = [
                    'company_id' => $companyId,
                    'sale_id' => $sale->id,
                    'user_id' => $request->user()->id,
                    'customer_id' => $customer->id,
                    'method' => $payment['method'],
                    'payment_date' => now()->format('Y-m-d'),
                    'amount' => $payment['amount'],
                    'status' => 'completed'
                ];
                
                // Add bank details for bank/transfer payments
                if (in_array($payment['method'], ['bank', 'transfer'])) {
                    $paymentData['bank_id'] = $payment['bank_id'] ?? null;
                    $paymentData['reference'] = $payment['transaction_reference'] ?? null;
                }
                
                Payment::create($paymentData);
                
                Log::info('Payment recorded:', [
                    'method' => $payment['method'],
                    'amount' => $payment['amount'],
                    'bank_id' => $payment['bank_id'] ?? null,
                    'reference' => $payment['transaction_reference'] ?? null
                ]);
            }

            // Update customer balance for credit payments
            if ($creditAmount != 0) {
                $oldBalance = $customer->current_balance;
                $customer->current_balance += $creditAmount;
                $customer->save();
                
                Log::info('Customer balance updated:', [
                    'customer_id' => $customer->id,
                    'old_balance' => $oldBalance,
                    'credit_amount' => $creditAmount,
                    'new_balance' => $customer->current_balance
                ]);
            }

            DB::commit();

            // Prepare payment breakdown for response
            $paymentBreakdown = [];
            foreach ($request->payments as $payment) {
                $method = $payment['method'];
                if (!isset($paymentBreakdown[$method])) {
                    $paymentBreakdown[$method] = 0;
                }
                $paymentBreakdown[$method] += $payment['amount'];
            }

            return response()->json([
                'success' => true,
                'message' => 'Sale completed successfully',
                'data' => [
                    'sale' => $sale->load(['items.product', 'customer', 'payments.bank']),
                    'customer_balance' => $customer->current_balance,
                    'payment_breakdown' => $paymentBreakdown,
                    'receipt' => [
                        'invoice' => $sale->invoice_number,
                        'total' => $sale->total,
                        'paid' => $sale->amount_paid,
                        'change' => $sale->change_due,
                        'balance' => $sale->balance_due,
                        'payment_breakdown' => $paymentBreakdown
                    ]
                ]
            ], 201);

        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Checkout failed: ' . $e->getMessage(), [
                'request' => $request->all(),
                'user_id' => $request->user()->id ?? null,
                'trace' => $e->getTraceAsString()
            ]);
            return response()->json([
                'success' => false,
                'message' => 'Sale failed: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Get sales report with split payment support
     */
    public function getSalesReport(Request $request)
    {
        $request->validate([
            'date_from' => 'nullable|date',
            'date_to' => 'nullable|date|after_or_equal:date_from',
            'payment_method' => 'nullable|string',
            'bank_id' => 'nullable|exists:banks,id',
            'per_page' => 'nullable|integer|min:1|max:100'
        ]);

        try {
            $companyId = $request->user()->company_id;
            
            $query = Sale::where('company_id', $companyId)
                ->with(['customer', 'user', 'payments.bank', 'items.product']);

            // Date filter
            if ($request->date_from) {
                $query->whereDate('sale_date', '>=', $request->date_from);
            }
            if ($request->date_to) {
                $query->whereDate('sale_date', '<=', $request->date_to);
            }

            // Payment method filter
            if ($request->payment_method && $request->payment_method !== 'all') {
                if ($request->payment_method === 'split') {
                    $query->where('is_split_payment', true);
                } else {
                    $query->where('payment_method', $request->payment_method);
                }
            }

            // Bank filter (for payments)
            if ($request->bank_id) {
                $query->whereHas('payments', function($q) use ($request) {
                    $q->where('bank_id', $request->bank_id);
                });
            }

            $sales = $query->orderBy('created_at', 'desc')
                          ->paginate($request->per_page ?? 20);

            // Calculate summaries from payments table (more accurate for split payments)
            $paymentSummary = Payment::whereIn('sale_id', $query->pluck('id'))
                ->select('payment_method', DB::raw('SUM(amount) as total_amount'), DB::raw('COUNT(*) as count'))
                ->groupBy('payment_method')
                ->get();

            $paymentBreakdown = [];
            foreach ($paymentSummary as $ps) {
                $paymentBreakdown[$ps->payment_method] = [
                    'total' => $ps->total_amount,
                    'count' => $ps->count
                ];
            }

            $summary = [
                'total_sales' => $query->count(),
                'total_revenue' => $query->sum('total'),
                'total_transactions' => $query->count(),
                'average_transaction' => $query->avg('total'),
                'split_payment_count' => $query->where('is_split_payment', true)->count(),
                'payment_breakdown' => $paymentBreakdown
            ];

            // Bank breakdown from payments table
            $bankBreakdown = Bank::whereHas('payments', function($q) use ($companyId, $request) {
                $q->whereHas('sale', function($sq) use ($companyId, $request) {
                    $sq->where('company_id', $companyId);
                    if ($request->date_from) $sq->whereDate('sale_date', '>=', $request->date_from);
                    if ($request->date_to) $sq->whereDate('sale_date', '<=', $request->date_to);
                });
            })
            ->withSum(['payments' => function($q) use ($companyId, $request) {
                $q->whereHas('sale', function($sq) use ($companyId, $request) {
                    $sq->where('company_id', $companyId);
                    if ($request->date_from) $sq->whereDate('sale_date', '>=', $request->date_from);
                    if ($request->date_to) $sq->whereDate('sale_date', '<=', $request->date_to);
                });
            }], 'amount')
            ->get()
            ->map(function($bank) {
                return [
                    'id' => $bank->id,
                    'name' => $bank->name,
                    'account_name' => $bank->account_name,
                    'account_number' => $bank->account_number,
                    'total_amount' => $bank->payments_sum_amount ?? 0,
                    'transaction_count' => $bank->payments_count ?? 0
                ];
            })
            ->filter(function($bank) {
                return $bank['total_amount'] > 0;
            })
            ->values();

            return response()->json([
                'success' => true,
                'data' => $sales,
                'summary' => $summary,
                'payment_breakdown' => $paymentBreakdown,
                'bank_breakdown' => $bankBreakdown
            ]);

        } catch (\Exception $e) {
            Log::error('Sales report error: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Failed to generate sales report',
                'error' => $e->getMessage()
            ], 500);
        }
    }

  

    /**
     * Get single bank transaction details
     */
    public function getBankTransactionDetails(Request $request, $id)
    {
        try {
            $companyId = $request->user()->company_id;
            
            $payment = Payment::where('company_id', $companyId)
                ->whereIn('payment_method', ['bank', 'transfer'])
                ->with(['sale', 'sale.customer', 'sale.user', 'sale.items.product', 'bank'])
                ->findOrFail($id);

            $bankDetails = null;
            if ($payment->bank) {
                $bankDetails = [
                    'bank_name' => $payment->bank->name,
                    'account_name' => $payment->bank->account_name,
                    'account_number' => $payment->bank->account_number,
                    'branch' => $payment->bank->branch,
                    'bank_code' => $payment->bank->bank_code
                ];
            }

            return response()->json([
                'success' => true,
                'data' => [
                    'payment' => $payment,
                    'sale' => $payment->sale,
                    'bank_details' => $bankDetails,
                    'transaction' => [
                        'reference' => $payment->reference,
                        'date' => $payment->created_at,
                        'amount' => $payment->amount,
                        'payment_method' => $payment->payment_method,
                        'is_split_payment' => $payment->sale->is_split_payment ?? false
                    ],
                    'customer' => [
                        'name' => $payment->sale->customer->name ?? $payment->sale->customer_name ?? 'Walk-in Customer',
                        'phone' => $payment->sale->customer->phone ?? null,
                        'email' => $payment->sale->customer->email ?? null
                    ]
                ]
            ]);

        } catch (\Exception $e) {
            Log::error('Bank transaction details error: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Transaction not found'
            ], 404);
        }
    }


    /**
     * Quick sale - minimal input
     */
    public function quickSale(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'items' => 'required|array|min:1',
            'items.*.product_id' => 'required|integer',
            'items.*.quantity' => 'required|integer|min:1',
            'amount_paid' => 'required|numeric|min:0',
            'payment_method' => 'required|in:cash,transfer,pos'
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'errors' => $validator->errors()
            ], 422);
        }

        $companyId = $request->user()->company_id;
        if (!$companyId) {
            return response()->json([
                'success' => false,
                'message' => 'User not assigned to a company'
            ], 400);
        }

        // Get products with their current prices
        $items = [];
        foreach ($request->items as $item) {
            $product = Product::where('id', $item['product_id'])->where('company_id', $companyId)->first();
            if (!$product) {
                return response()->json([
                    'success' => false,
                    'message' => 'Product not found or does not belong to your company'
                ], 404);
            }
            $items[] = [
                'product_id' => $item['product_id'],
                'quantity' => $item['quantity'],
                'price' => $product->price // Use current selling price
            ];
        }

        // Merge with default customer
        $request->merge([
            'items' => $items,
            'customer_name' => 'Walk-in Customer',
            'payments' => [
                [
                    'method' => $request->payment_method,
                    'amount' => $request->amount_paid
                ]
            ]
        ]);

        return $this->checkout($request);
    }

/**
 * Export bank transaction report to CSV or Excel
 */
public function exportBankTransactionReport(Request $request)
{
    try {
        $request->validate([
            'date_from' => 'nullable|date',
            'date_to' => 'nullable|date|after_or_equal:date_from',
            'bank_id' => 'nullable|exists:banks,id',
            'format' => 'nullable|in:csv,excel'
        ]);

        $companyId = $request->user()->company_id;
        $format = $request->format ?? 'csv';
        
        // Build query for bank/transfer payments
        $query = \App\Models\Payment::where('company_id', $companyId)
            ->whereIn('method', ['bank', 'transfer'])
            ->whereNotNull('bank_id')
            ->with(['sale', 'sale.customer', 'bank']);

        // Apply filters
        if ($request->date_from) {
            $query->whereHas('sale', function($q) use ($request) {
                $q->whereDate('sale_date', '>=', $request->date_from);
            });
        }
        if ($request->date_to) {
            $query->whereHas('sale', function($q) use ($request) {
                $q->whereDate('sale_date', '<=', $request->date_to);
            });
        }
        if ($request->bank_id) {
            $query->where('bank_id', $request->bank_id);
        }

        $payments = $query->orderBy('created_at', 'desc')->get();

        if ($format === 'csv') {
            return $this->exportToCSV($payments);
        } else {
            return $this->exportToExcel($payments);
        }

    } catch (\Exception $e) {
        \Log::error('Export error: ' . $e->getMessage());
        return response()->json([
            'success' => false,
            'message' => 'Export failed: ' . $e->getMessage()
        ], 500);
    }
}

/**
 * Export to CSV
 */
private function exportToCSV($payments)
{
    $filename = 'bank_transactions_' . date('Y-m-d') . '.csv';
    
    $headers = [
        'Content-Type' => 'text/csv',
        'Content-Disposition' => 'attachment; filename="' . $filename . '"',
        'Pragma' => 'no-cache',
        'Cache-Control' => 'must-revalidate, post-check=0, pre-check=0',
        'Expires' => '0'
    ];

    $callback = function() use ($payments) {
        $file = fopen('php://output', 'w');
        
        // Add CSV headers
        fputcsv($file, [
            'Date',
            'Invoice Number',
            'Customer Name',
            'Customer Phone',
            'Bank Name',
            'Account Name',
            'Account Number',
            'Transaction Reference',
            'Amount',
            'Payment Method',
            'Status'
        ]);
        
        // Add data rows
        foreach ($payments as $payment) {
            fputcsv($file, [
                $payment->created_at->format('Y-m-d H:i:s'),
                $payment->sale->invoice_number ?? 'N/A',
                $payment->sale->customer->name ?? 'Walk-in Customer',
                $payment->sale->customer->phone ?? 'N/A',
                $payment->bank->name ?? 'N/A',
                $payment->bank->account_name ?? 'N/A',
                $payment->bank->account_number ?? 'N/A',
                $payment->reference ?? 'N/A',
                number_format($payment->amount, 2),
                $payment->method,
                $payment->status
            ]);
        }
        
        fclose($file);
    };
    
    return response()->stream($callback, 200, $headers);
}

/**
 * Export to Excel (using simple HTML table for now)
 */
private function exportToExcel($payments)
{
    $filename = 'bank_transactions_' . date('Y-m-d') . '.xls';
    
    $html = '<html><head><meta charset="UTF-8"><title>Bank Transactions Report</title></head><body>';
    $html .= '<table border="1">';
    $html .= '<thead>';
    $html .= '<tr>';
    $html .= '<th>Date</th>';
    $html .= '<th>Invoice Number</th>';
    $html .= '<th>Customer Name</th>';
    $html .= '<th>Customer Phone</th>';
    $html .= '<th>Bank Name</th>';
    $html .= '<th>Account Name</th>';
    $html .= '<th>Account Number</th>';
    $html .= '<th>Transaction Reference</th>';
    $html .= '<th>Amount (₦)</th>';
    $html .= '<th>Payment Method</th>';
    $html .= '<th>Status</th>';
    $html .= '</tr>';
    $html .= '</thead>';
    $html .= '<tbody>';
    
    foreach ($payments as $payment) {
        $html .= '<tr>';
        $html .= '<td>' . $payment->created_at->format('Y-m-d H:i:s') . '</td>';
        $html .= '<td>' . ($payment->sale->invoice_number ?? 'N/A') . '</td>';
        $html .= '<td>' . ($payment->sale->customer->name ?? 'Walk-in Customer') . '</td>';
        $html .= '<td>' . ($payment->sale->customer->phone ?? 'N/A') . '</td>';
        $html .= '<td>' . ($payment->bank->name ?? 'N/A') . '</td>';
        $html .= '<td>' . ($payment->bank->account_name ?? 'N/A') . '</td>';
        $html .= '<td>' . ($payment->bank->account_number ?? 'N/A') . '</td>';
        $html .= '<td>' . ($payment->reference ?? 'N/A') . '</td>';
        $html .= '<td>' . number_format($payment->amount, 2) . '</td>';
        $html .= '<td>' . $payment->method . '</td>';
        $html .= '<td>' . $payment->status . '</td>';
        $html .= '</tr>';
    }
    
    $html .= '</tbody>';
    $html .= '</table>';
    $html .= '</body></html>';
    
    return response($html)
        ->header('Content-Type', 'application/vnd.ms-excel')
        ->header('Content-Disposition', 'attachment; filename="' . $filename . '"')
        ->header('Pragma', 'no-cache')
        ->header('Cache-Control', 'must-revalidate, post-check=0, pre-check=0')
        ->header('Expires', '0');
}
        //Search sales with multiple filters (invoice number, date range, customer, total amount)
    public function sales(Request $request)
{
    try {
        $companyId = $request->user()->company_id;
        
        $query = Sale::where('company_id', $companyId)
            ->with(['customer', 'user', 'payments']);
        
        // Search by invoice number
        if ($request->search) {
            $query->where('invoice_number', 'like', '%' . $request->search . '%');
        }
        
        // Search by total amount (exact match)
        if ($request->search_amount) {
            $query->where('total', 'like', '%' . $request->search_amount . '%');
        }
        
        // Date range filter
        if ($request->from_date) {
            $query->whereDate('sale_date', '>=', $request->from_date);
        }
        if ($request->to_date) {
            $query->whereDate('sale_date', '<=', $request->to_date);
        }
        
        // Customer filter
        if ($request->customer_id) {
            $query->where('customer_id', $request->customer_id);
        }
        
        $sales = $query->orderBy('created_at', 'desc')
            ->paginate($request->per_page ?? 15);

        return response()->json([
            'success' => true,
            'data' => $sales
        ]);

    } catch (\Exception $e) {
        return response()->json([
            'success' => false,
            'message' => 'Failed to fetch sales',
            'error' => $e->getMessage()
        ], 500);
    }
}
    /**
     * Get sales history
     */
    // public function sales(Request $request)
    // {
    //     try {
    //         $companyId = $request->user()->company_id;
            
    //         $sales = Sale::where('company_id', $companyId)
    //             ->with(['customer', 'user', 'payments'])
    //             ->when($request->from_date, function($query, $date) {
    //                 return $query->whereDate('sale_date', '>=', $date);
    //             })
    //             ->when($request->to_date, function($query, $date) {
    //                 return $query->whereDate('sale_date', '<=', $date);
    //             })
    //             ->when($request->customer_id, function($query, $id) {
    //                 return $query->where('customer_id', $id);
    //             })
    //             ->orderBy('created_at', 'desc')
    //             ->paginate($request->per_page ?? 15);

    //         return response()->json([
    //             'success' => true,
    //             'data' => $sales
    //         ]);

    //     } catch (\Exception $e) {
    //         return response()->json([
    //             'success' => false,
    //             'message' => 'Failed to fetch sales',
    //             'error' => $e->getMessage()
    //         ], 500);
    //     }
    // }

    /**
     * Get single sale details
     */
    public function saleDetails(Request $request, $id)
    {
        try {
            $sale = Sale::where('company_id', $request->user()->company_id)
                ->with(['customer', 'user', 'items.product', 'payments'])
                ->findOrFail($id);

            return response()->json([
                'success' => true,
                'data' => $sale
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Sale not found'
            ], 404);
        }
    }

    /**
     * Void a sale
     */
    public function voidSale(Request $request, $id)
    {
        try {
            DB::beginTransaction();

            $sale = Sale::where('company_id', $request->user()->company_id)
                ->with('items')
                ->findOrFail($id);

            if ($sale->status === 'voided') {
                return response()->json([
                    'success' => false,
                    'message' => 'Sale already voided'
                ], 422);
            }

            // Restore stock
            foreach ($sale->items as $item) {
                $product = Product::find($item->product_id);
                $oldStock = $product->stock_quantity;
                $product->stock_quantity += $item->quantity;
                $product->save();

                InventoryTransaction::create([
                    'company_id' => $sale->company_id,
                    'product_id' => $product->id,
                    'user_id' => $request->user()->id,
                    'type' => 'void',
                    'quantity' => $item->quantity,
                    'before_quantity' => $oldStock,
                    'after_quantity' => $product->stock_quantity,
                    'reference_type' => 'sale',
                    'reference_id' => $sale->id,
                    'notes' => "Voided sale #{$sale->invoice_number}"
                ]);
            }

            // Update sale status
            $sale->status = 'voided';
            $sale->save();

            DB::commit();

            return response()->json([
                'success' => true,
                'message' => 'Sale voided successfully'
            ]);

        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json([
                'success' => false,
                'message' => 'Failed to void sale',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Get sales summary by date range
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
            
            $sales = Sale::where('company_id', $companyId)
                ->whereBetween('sale_date', [$request->from, $request->to])
                ->with('items')
                ->get();

            $summary = [
                'period' => [
                    'from' => $request->from,
                    'to' => $request->to
                ],
                'total_sales' => $sales->count(),
                'total_revenue' => $sales->sum('total'),
                'average_items_per_sale' => $sales->avg('item_count'),
                'total_cost' => $sales->sum(function($sale) {
                    return $sale->items->sum(function($item) {
                        return $item->cost * $item->quantity;
                    });
                }),
                'total_profit' => $sales->sum(function($sale) {
                    return $sale->items->sum(function($item) {
                        return ($item->price - $item->cost) * $item->quantity;
                    });
                }),
                'average_sale' => $sales->avg('total'),
                'by_payment_method' => Payment::whereIn('sale_id', $sales->pluck('id'))
                    ->get()
                    ->groupBy('payment_method')
                    ->map(function($payments) {
                        return $payments->sum('amount');
                    })
            ];

            return response()->json([
                'success' => true,
                'data' => $summary
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to generate summary',
                'error' => $e->getMessage()
            ], 500);
        }
    }

public function printReceipt(Request $request, $id)
{
    try {
        // Check if token is provided in URL (for popup windows)
    
        if ($request->has('token')) {
            // Authenticate using the token (URL-encoded token is supported)
            $rawToken = urldecode($request->token);
            $token = \Laravel\Sanctum\PersonalAccessToken::findToken($rawToken);
            
            if (!$token) {
                \Log::warning('Invalid token provided for receipt access', [
                    'token_provided' => substr($request->token, 0, 10) . '...'
                ]);
                
                return response()->json([
                    'success' => false,
                    'message' => 'Invalid or expired token. Please log in again.'
                ], 401);
            }
            
            // Optional: Check if token has ability (if you're using token abilities)
            // If you haven't set up abilities, you can skip this check or modify it
            $user = $token->tokenable;
            
            // Log successful token authentication
            \Log::info('Receipt accessed via token', [
                'user_id' => $user->id,
                'sale_id' => $id
            ]);
            
        } else {
            // Try normal authentication
            $user = auth()->guard('sanctum')->user();
            
            if (!$user) {
                \Log::warning('Unauthorized receipt access attempt', [
                    'sale_id' => $id,
                    'ip' => $request->ip()
                ]);
                
                return response()->json([
                    'success' => false,
                    'message' => 'Unauthorized. Please log in.'
                ], 401);
            }
        }
        
        $sale = Sale::where('company_id', $user->company_id)
            ->with(['items', 'payments', 'customer', 'company'])
            ->findOrFail($id);

        // Get printer settings from company
        $company = $sale->company;
        $settings = $company->settings['printer'] ?? [
            'default_printer_type' => 'thermal',
            'thermal_width' => '80mm',
            'print_copies' => 1,
            'print_logo' => true,
            'print_barcode' => true
        ];

        $receiptService = new \App\Services\ReceiptService();
        
        // Determine receipt type from request or settings
        $receiptType = $request->type ?? $settings['default_printer_type'];
        $action = $request->action ?? 'display';
        $autoPrint = $request->autoprint === 'true';
        
        \Log::info('Generating receipt', [
            'sale_id' => $id,
            'type' => $receiptType,
            'action' => $action,
            'auto_print' => $autoPrint
        ]);
        
        switch ($receiptType) {
            case 'thermal':
                $receipt = $receiptService->generateThermalText($sale, $settings);
                
                if ($action === 'print' || $autoPrint) {
                    return response($receipt)
                        ->header('Content-Type', 'text/plain')
                        ->header('X-Printer-Type', 'thermal')
                        ->header('X-Print-Action', 'print');
                } else {
                    $html = $this->wrapThermalInHtml($receipt, $sale, $autoPrint);
                    return response($html)->header('Content-Type', 'text/html');
                }
                
            case 'html':
                $receipt = $receiptService->generateHtmlReceipt($sale, $settings);
                
                if ($action === 'print' || $autoPrint) {
                    $receipt = $this->addPrintScript($receipt);
                }
                return response($receipt);
                
            case 'a4':
            case 'pdf':
                $pdf = $receiptService->generatePdfReceipt($sale, $settings);
                
                if ($autoPrint) {
                    // For PDF with auto-print, return with print dialog
                    return $pdf->stream("receipt-{$sale->invoice_number}.pdf");
                }
                
                return $pdf->stream("receipt-{$sale->invoice_number}.pdf");
                
            default:
                return response()->json([
                    'success' => false,
                    'message' => 'Invalid receipt type'
                ], 400);
        }
    } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
        \Log::error('Sale not found for receipt', [
            'sale_id' => $id,
            'user_id' => $user->id ?? 'unknown'
        ]);
        
        return response()->json([
            'success' => false,
            'message' => 'Sale not found'
        ], 404);
        
    } catch (\Exception $e) {
        \Log::error('Receipt printing error: ' . $e->getMessage(), [
            'sale_id' => $id,
            'trace' => $e->getTraceAsString()
        ]);
        
        return response()->json([
            'success' => false,
            'message' => 'Failed to generate receipt: ' . $e->getMessage()
        ], 500);
    }
}

/**
 * Wrap thermal receipt text in HTML with print button and auto-print
 */
private function wrapThermalInHtml($thermalText, $sale, $autoPrint = false)
{
    $invoice = $sale->invoice_number;
    $companyName = $sale->company->name;
    $autoPrintValue = $autoPrint ? 'true' : 'false';
    
    return <<<HTML
<!DOCTYPE html>
<html>
<head>
    <title>Receipt - {$invoice}</title>
    <style>
        body {
            font-family: 'Courier New', monospace;
            background: #f5f5f5;
            margin: 0;
            padding: 20px;
            display: flex;
            flex-direction: column;
            align-items: center;
        }
        .receipt-container {
            background: white;
            padding: 20px;
            border-radius: 8px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
            max-width: 400px;
            width: 100%;
        }
        .receipt-content {
            background: white;
            padding: 15px;
            border: 1px dashed #ccc;
            font-size: 12px;
            line-height: 1.4;
            white-space: pre-wrap;
            font-family: 'Courier New', monospace;
        }
        .print-controls {
            margin-top: 20px;
            display: flex;
            gap: 10px;
            justify-content: center;
        }
        .btn {
            padding: 10px 20px;
            border: none;
            border-radius: 4px;
            cursor: pointer;
            font-size: 14px;
            font-weight: bold;
            transition: background 0.3s;
        }
        .btn-print {
            background: #4CAF50;
            color: white;
        }
        .btn-print:hover {
            background: #45a049;
        }
        .btn-close {
            background: #f44336;
            color: white;
        }
        .btn-close:hover {
            background: #da190b;
        }
        @media print {
            .print-controls {
                display: none;
            }
        }
    </style>
    <script>
        window.autoPrint = {$autoPrintValue};
        
        document.addEventListener('DOMContentLoaded', function() {
            if (window.autoPrint) {
                setTimeout(function() {
                    window.print();
                }, 500);
            }
        });
    </script>
</head>
<body>
    <div class="receipt-container">
        <h3 style="text-align: center; margin-top: 0;">{$companyName}</h3>
        <p style="text-align: center; margin: 5px 0;">Receipt: {$invoice}</p>
        
        <div class="receipt-content">
            {$thermalText}
        </div>
        
        <div class="print-controls">
            <button class="btn btn-print" onclick="window.print()">
                🖨️ Print Receipt
            </button>
            <button class="btn btn-close" onclick="window.close()">
                ✖ Close
            </button>
        </div>
    </div>
</body>
</html>
HTML;
}


/**
 * Close the day - finalize all sales for the day
 */
/**
 * Close the day - finalize all sales for the day
 */
/**
 * Close the day - finalize all sales for the day
 */
public function closeDay(Request $request)
{
    try {
        // Validate the request
        $validator = Validator::make($request->all(), [
            'date' => 'nullable|date|date_format:Y-m-d'
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'errors' => $validator->errors()
            ], 422);
        }

        $companyId = $request->user()->company_id;
        $date = $request->date ?? now()->format('Y-m-d');
        
        // Check if DayClose model exists
        if (!class_exists('App\Models\DayClose')) {
            return response()->json([
                'success' => false,
                'message' => 'DayClose model not found. Please run migrations.'
            ], 500);
        }
        
        // Check if the day is already closed
        $existingClose = \App\Models\DayClose::where('company_id', $companyId)
            ->where('close_date', $date)
            ->first();
            
        if ($existingClose) {
            $user = \App\Models\User::find($existingClose->user_id);
            return response()->json([
                'success' => false,
                'message' => 'Day already closed on ' . $date,
                'data' => [
                    'closed_by' => $user->name ?? 'Unknown',
                    'closed_at' => $existingClose->closed_at
                ]
            ], 422);
        }
        
        // Get all sales for the day
        $sales = Sale::where('company_id', $companyId)
            ->whereDate('sale_date', $date)
            ->with(['items', 'payments', 'user'])
            ->get();
        
        // Calculate summary
        $totalSales = $sales->count();
        $totalRevenue = $sales->sum('total');
        $totalItems = $sales->sum('item_count');
        
        // Calculate profit
        $totalProfit = 0;
        foreach ($sales as $sale) {
            foreach ($sale->items as $item) {
                $totalProfit += ($item->price - $item->cost) * $item->quantity;
            }
        }
        
        // Group by cashier
        $byCashier = [];
        foreach ($sales as $sale) {
            $userId = $sale->user_id;
            $userName = $sale->user->name ?? 'Unknown';
            if (!isset($byCashier[$userId])) {
                $byCashier[$userId] = [
                    'cashier_name' => $userName,
                    'sales_count' => 0,
                    'total' => 0
                ];
            }
            $byCashier[$userId]['sales_count']++;
            $byCashier[$userId]['total'] += $sale->total;
        }
        
        // Group by payment method
        $byPaymentMethod = [];
        foreach ($sales as $sale) {
            $paymentMethod = $sale->payments->first()->payment_method ?? 'cash';
            if (!isset($byPaymentMethod[$paymentMethod])) {
                $byPaymentMethod[$paymentMethod] = 0;
            }
            $byPaymentMethod[$paymentMethod] += $sale->total;
        }
        
        $summary = [
            'date' => $date,
            'total_sales' => $totalSales,
            'total_revenue' => $totalRevenue,
            'total_items' => $totalItems,
            'total_profit' => $totalProfit,
            'by_cashier' => array_values($byCashier),
            'by_payment_method' => $byPaymentMethod
        ];
        
        // Create DayClose record
        $close = \App\Models\DayClose::create([
            'company_id' => $companyId,
            'user_id' => $request->user()->id,
            'close_date' => $date,
            'closed_at' => now(),
            'total_sales' => $totalSales,
            'total_revenue' => $totalRevenue,
            'summary' => $summary
        ]);
        
        // Mark all sales as closed if is_closed column exists
        if (Schema::hasColumn('sales', 'is_closed')) {
            Sale::where('company_id', $companyId)
                ->whereDate('sale_date', $date)
                ->update(['is_closed' => true]);
        }
        
        return response()->json([
            'success' => true,
            'message' => 'Day closed successfully',
            'data' => [
                'closed_by' => $request->user()->name,
                'closed_at' => now(),
                'summary' => $summary
            ]
        ], 200);
        
    } catch (\Exception $e) {
        \Log::error('Close day error: ' . $e->getMessage());
        \Log::error('Close day trace: ' . $e->getTraceAsString());
        
        return response()->json([
            'success' => false,
            'message' => 'Failed to close day: ' . $e->getMessage(),
            'error' => $e->getMessage()
        ], 500);
    }
}

/**
 * Get bank transaction report from payments table
 */
public function getBankTransactionReport(Request $request)
{
    try {
        $request->validate([
            'date_from' => 'nullable|date',
            'date_to' => 'nullable|date|after_or_equal:date_from',
            'bank_id' => 'nullable|exists:banks,id',
            'transaction_reference' => 'nullable|string',
            'per_page' => 'nullable|integer|min:1|max:100'
        ]);

        $companyId = $request->user()->company_id;
        
        // Build query on payments table for bank/transfer payments
        $query = \App\Models\Payment::where('company_id', $companyId)
            ->whereIn('method', ['bank', 'transfer'])
            ->whereNotNull('bank_id')
            ->with(['sale', 'sale.customer', 'bank']);

        // Apply date filters
        if ($request->date_from) {
            $query->whereHas('sale', function($q) use ($request) {
                $q->whereDate('sale_date', '>=', $request->date_from);
            });
        }
        if ($request->date_to) {
            $query->whereHas('sale', function($q) use ($request) {
                $q->whereDate('sale_date', '<=', $request->date_to);
            });
        }

        // Apply bank filter
        if ($request->bank_id) {
            $query->where('bank_id', $request->bank_id);
        }

        // Apply transaction reference filter
        if ($request->transaction_reference) {
            $query->where('reference', 'LIKE', '%' . $request->transaction_reference . '%');
        }

        // Get paginated results
        $payments = $query->orderBy('created_at', 'desc')
                         ->paginate($request->per_page ?? 20);

        // Calculate summaries
        $totalAmount = $query->sum('amount');
        $totalTransactions = $query->count();
        
        $summary = [
            'total_transactions' => $totalTransactions,
            'total_amount' => $totalAmount,
            'average_transaction' => $totalTransactions > 0 ? $totalAmount / $totalTransactions : 0,
            'unique_customers' => $query->get()->pluck('sale.customer_id')->unique()->count(),
            'total_banks_used' => $query->distinct('bank_id')->count('bank_id')
        ];

        // Bank breakdown
        $bankBreakdown = \App\Models\Bank::whereHas('payments', function($q) use ($companyId, $request) {
            $q->where('company_id', $companyId)
              ->whereIn('method', ['bank', 'transfer']);
            if ($request->date_from) {
                $q->whereHas('sale', function($sq) use ($request) {
                    $sq->whereDate('sale_date', '>=', $request->date_from);
                });
            }
            if ($request->date_to) {
                $q->whereHas('sale', function($sq) use ($request) {
                    $sq->whereDate('sale_date', '<=', $request->date_to);
                });
            }
        })
        ->withSum(['payments' => function($q) use ($companyId, $request) {
            $q->where('company_id', $companyId)
              ->whereIn('method', ['bank', 'transfer']);
            if ($request->date_from) {
                $q->whereHas('sale', function($sq) use ($request) {
                    $sq->whereDate('sale_date', '>=', $request->date_from);
                });
            }
            if ($request->date_to) {
                $q->whereHas('sale', function($sq) use ($request) {
                    $sq->whereDate('sale_date', '<=', $request->date_to);
                });
            }
        }], 'amount')
        ->get()
        ->map(function($bank) use ($summary) {
            $bankAmount = $bank->payments_sum_amount ?? 0;
            return [
                'id' => $bank->id,
                'name' => $bank->name,
                'account_name' => $bank->account_name,
                'account_number' => $bank->account_number,
                'transaction_count' => $bank->payments_count ?? 0,
                'total_amount' => $bankAmount,
                'percentage' => $summary['total_amount'] > 0 
                    ? round(($bankAmount / $summary['total_amount']) * 100, 2) 
                    : 0
            ];
        })
        ->filter(function($bank) {
            return $bank['total_amount'] > 0;
        })
        ->values();

        // Format the response data
        $formattedData = $payments->map(function($payment) {
            return [
                'id' => $payment->id,
                'sale_id' => $payment->sale_id,
                'invoice_number' => $payment->sale->invoice_number ?? 'N/A',
                'customer_name' => $payment->sale->customer->name ?? 'Walk-in Customer',
                'bank' => $payment->bank ? [
                    'id' => $payment->bank->id,
                    'name' => $payment->bank->name,
                    'account_name' => $payment->bank->account_name,
                    'account_number' => $payment->bank->account_number
                ] : null,
                'transaction_reference' => $payment->reference,
                'amount' => $payment->amount,
                'payment_method' => $payment->method,
                'created_at' => $payment->created_at,
                'status' => $payment->status
            ];
        });

        return response()->json([
            'success' => true,
            'data' => [
                'data' => $formattedData,
                'current_page' => $payments->currentPage(),
                'last_page' => $payments->lastPage(),
                'per_page' => $payments->perPage(),
                'total' => $payments->total()
            ],
            'summary' => $summary,
            'bank_breakdown' => $bankBreakdown
        ]);

    } catch (\Exception $e) {
        \Log::error('Bank transaction report error: ' . $e->getMessage(), [
            'trace' => $e->getTraceAsString(),
            'request' => $request->all()
        ]);
        
        return response()->json([
            'success' => false,
            'message' => 'Failed to fetch bank transactions: ' . $e->getMessage()
        ], 500);
    }
}
/**
 * Check if a day is already closed
 */
public function dayStatus(Request $request)
{
    try {
        $companyId = $request->user()->company_id;
        $date = $request->date ?? now()->format('Y-m-d');
        
        $closed = \App\Models\DayClose::where('company_id', $companyId)
            ->where('close_date', $date)
            ->first();
        
        if ($closed) {
            return response()->json([
                'success' => true,
                'is_closed' => true,
                'closed_by' => $closed->user->name ?? 'Unknown',
                'closed_at' => $closed->closed_at
            ]);
        }
        
        return response()->json([
            'success' => true,
            'is_closed' => false
        ]);
        
    } catch (\Exception $e) {
        return response()->json([
            'success' => false,
            'message' => 'Failed to check day status',
            'error' => $e->getMessage()
        ], 500);

        
    }
}


}