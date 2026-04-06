<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Sale;
use App\Models\SaleItem;
use App\Models\Product;
use App\Models\InventoryTransaction;
use App\Models\Customer;
use App\Models\Payment;
use App\Models\Bank;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\Log;

class SaleController extends Controller
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
                ->get();
            
            $summary = [
                'total_sales' => $sales->count(),
                'total_revenue' => $sales->sum('total'),
                'total_profit' => $sales->sum(function($sale) {
                    return $sale->items->sum(function($item) {
                        return ($item->price - $item->cost) * $item->quantity;
                    });
                }),
                'payment_breakdown' => [
                    'cash' => $sales->filter(function($sale) {
                        return $sale->payments->where('payment_method', 'cash')->count() > 0;
                    })->sum('total'),
                    'bank' => $sales->filter(function($sale) {
                        return $sale->payments->where('payment_method', 'bank')->count() > 0;
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
            Log::error('Today sales error: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Failed to fetch today\'s sales',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Process a new sale (checkout)
     */
        public function store(Request $request)
    {
        Log::info('Sale request received:', $request->all());
        
        $validator = Validator::make($request->all(), [
            'items' => 'required|array|min:1',
            'items.*.product_id' => 'required|exists:products,id',
            'items.*.quantity' => 'required|integer|min:1',
            'items.*.price' => 'required|numeric|min:0',
            'customer_id' => 'nullable|exists:customers,id',
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
            Log::error('Validation failed:', $validator->errors()->toArray());
            return response()->json([
                'success' => false,
                'errors' => $validator->errors()
            ], 422);
        }

        try {
            DB::beginTransaction();

            $companyId = $request->user()->company_id;

            // Handle customer
            if ($request->customer_id) {
                $customer = Customer::find($request->customer_id);
            } else {
                $customer = Customer::create([
                    'company_id' => $companyId,
                    'name' => $request->customer_name,
                    'phone' => $request->customer_phone,
                    'status' => 'active'
                ]);
            }

            // Calculate totals
            $subtotal = 0;
            $totalItems = 0;
            $itemsData = [];

            foreach ($request->items as $item) {
                $product = Product::find($item['product_id']);
                
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
            $paymentMethods = [];
            $bankPayments = [];
            
            foreach ($request->payments as $payment) {
                $totalPaid += $payment['amount'];
                $paymentMethods[] = $payment['method'];
                
                // Track bank payments for reference
                if (in_array($payment['method'], ['bank', 'transfer'])) {
                    $bankPayments[] = [
                        'bank_id' => $payment['bank_id'] ?? null,
                        'transaction_reference' => $payment['transaction_reference'] ?? null,
                        'amount' => $payment['amount']
                    ];
                }
            }

            $changeDue = max(0, $totalPaid - $total);
            $balanceDue = max(0, $total - $totalPaid);

            // Determine payment status
            $paymentStatus = $balanceDue > 0 ? 'partial' : 'paid';
            
            // Determine if this is a split payment
            $isSplitPayment = count($request->payments) > 1;

            // Generate invoice number
            $invoiceNumber = 'INV-' . date('Ymd') . '-' . str_pad(rand(1, 9999), 4, '0', STR_PAD_LEFT);

            // Prepare sale data
            $saleData = [
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
                'status' => 'completed',
                'balance_due' => $balanceDue,
                'notes' => $request->notes,
                'payment_method' => $isSplitPayment ? 'split' : implode(',', $paymentMethods),
                'is_split_payment' => $isSplitPayment,
                'paid_amount' => $totalPaid
            ];

            $sale = Sale::create($saleData);
            
            Log::info('Sale created:', [
                'id' => $sale->id, 
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
                    'payment_method' => $payment['method'],
                    'amount' => $payment['amount'],
                    'status' => 'completed'
                ];
                
                // Add bank details for bank/transfer payments
                if (in_array($payment['method'], ['bank', 'transfer'])) {
                    $paymentData['bank_id'] = $payment['bank_id'] ?? null;
                    $paymentData['reference'] = $payment['transaction_reference'] ?? null;
                }
                
                Payment::create($paymentData);
            }

            // Update customer balance if credit or balance due
            if ($balanceDue > 0) {
                $customer->current_balance += $balanceDue;
                $customer->save();
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
            Log::error('Sale error: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Sale failed: ' . $e->getMessage()
            ], 500);
        }
    }


    /**
     * Get sales history
     */
    public function index(Request $request)
    {
        try {
            $companyId = $request->user()->company_id;
            
            $sales = Sale::where('company_id', $companyId)
                ->with(['customer', 'user', 'payments', 'bank'])
                ->when($request->from_date, function($query, $date) {
                    return $query->whereDate('sale_date', '>=', $date);
                })
                ->when($request->to_date, function($query, $date) {
                    return $query->whereDate('sale_date', '<=', $date);
                })
                ->when($request->customer_id, function($query, $id) {
                    return $query->where('customer_id', $id);
                })
                ->when($request->payment_method, function($query, $method) {
                    return $query->where('payment_method', 'LIKE', '%' . $method . '%');
                })
                ->orderBy('created_at', 'desc')
                ->paginate($request->per_page ?? 15);

            return response()->json([
                'success' => true,
                'data' => $sales
            ]);

        } catch (\Exception $e) {
            Log::error('Sales index error: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Failed to fetch sales',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Get single sale details
     */
    public function show(Request $request, $id)
    {
        try {
            $sale = Sale::where('company_id', $request->user()->company_id)
                ->with(['customer', 'user', 'items.product', 'payments', 'bank'])
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
    public function void(Request $request, $id)
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

    /**
     * Search sales with multiple filters
     */
    public function sales(Request $request)
    {
        try {
            $companyId = $request->user()->company_id;
            
            $query = Sale::where('company_id', $companyId)
                ->with(['customer', 'user', 'payments', 'bank']);
            
            // Search by invoice number
            if ($request->search) {
                $query->where('invoice_number', 'like', '%' . $request->search . '%');
            }
            
            // Search by total amount
            if ($request->search_amount) {
                $query->where('total', $request->search_amount);
            }
            
            // Search by transaction reference (for bank transfers)
            if ($request->transaction_reference) {
                $query->where('transaction_reference', 'like', '%' . $request->transaction_reference . '%');
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
            
            // Payment method filter
            if ($request->payment_method) {
                $query->where('payment_method', 'LIKE', '%' . $request->payment_method . '%');
            }
            
            // Bank filter
            if ($request->bank_id) {
                $query->where('bank_id', $request->bank_id);
            }
            
            $sales = $query->orderBy('created_at', 'desc')
                ->paginate($request->per_page ?? 15);

            return response()->json([
                'success' => true,
                'data' => $sales
            ]);

        } catch (\Exception $e) {
            Log::error('Sales search error: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Failed to fetch sales',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Get sales report with bank transaction filtering
     */
    public function getSalesReport(Request $request)
    {
        $request->validate([
            'date_from' => 'nullable|date',
            'date_to' => 'nullable|date|after_or_equal:date_from',
            'payment_method' => 'nullable|in:cash,bank,transfer,pos,credit',
            'bank_id' => 'nullable|exists:banks,id',
            'per_page' => 'nullable|integer|min:1|max:100'
        ]);

        try {
            $companyId = $request->user()->company_id;
            
            $query = Sale::where('company_id', $companyId)
                ->with(['customer', 'user', 'bank', 'items.product', 'payments']);

            // Date filter
            if ($request->date_from) {
                $query->whereDate('sale_date', '>=', $request->date_from);
            }
            if ($request->date_to) {
                $query->whereDate('sale_date', '<=', $request->date_to);
            }

            // Payment method filter (supports multiple methods stored as comma-separated)
            if ($request->payment_method) {
                $query->where('payment_method', 'LIKE', '%' . $request->payment_method . '%');
            }

            // Bank filter
            if ($request->bank_id) {
                $query->where('bank_id', $request->bank_id);
            }

            // Get paginated sales report
            $sales = $query->orderBy('created_at', 'desc')
                          ->paginate($request->per_page ?? 20);

            // Calculate summaries
            $summary = [
                'total_sales' => $query->sum('total'),
                'total_cash_sales' => Sale::where('company_id', $companyId)
                    ->where(function($q) use ($request) {
                        if ($request->date_from) $q->whereDate('sale_date', '>=', $request->date_from);
                        if ($request->date_to) $q->whereDate('sale_date', '<=', $request->date_to);
                    })
                    ->where('payment_method', 'LIKE', '%cash%')
                    ->sum('total'),
                'total_bank_sales' => Sale::where('company_id', $companyId)
                    ->where(function($q) use ($request) {
                        if ($request->date_from) $q->whereDate('sale_date', '>=', $request->date_from);
                        if ($request->date_to) $q->whereDate('sale_date', '<=', $request->date_to);
                    })
                    ->where(function($q) {
                        $q->where('payment_method', 'LIKE', '%bank%')
                          ->orWhere('payment_method', 'LIKE', '%transfer%');
                    })
                    ->sum('total'),
                'total_transactions' => $query->count(),
                'cash_transactions' => Sale::where('company_id', $companyId)
                    ->where(function($q) use ($request) {
                        if ($request->date_from) $q->whereDate('sale_date', '>=', $request->date_from);
                        if ($request->date_to) $q->whereDate('sale_date', '<=', $request->date_to);
                    })
                    ->where('payment_method', 'LIKE', '%cash%')
                    ->count(),
                'bank_transactions' => Sale::where('company_id', $companyId)
                    ->where(function($q) use ($request) {
                        if ($request->date_from) $q->whereDate('sale_date', '>=', $request->date_from);
                        if ($request->date_to) $q->whereDate('sale_date', '<=', $request->date_to);
                    })
                    ->where(function($q) {
                        $q->where('payment_method', 'LIKE', '%bank%')
                          ->orWhere('payment_method', 'LIKE', '%transfer%');
                    })
                    ->count(),
                'average_transaction' => $query->avg('total')
            ];

            // Bank breakdown
            $bankBreakdown = Bank::whereHas('sales', function($q) use ($companyId, $request) {
                $q->where('company_id', $companyId);
                if ($request->date_from) $q->whereDate('sale_date', '>=', $request->date_from);
                if ($request->date_to) $q->whereDate('sale_date', '<=', $request->date_to);
            })
            ->withCount(['sales' => function($q) use ($companyId, $request) {
                $q->where('company_id', $companyId);
                if ($request->date_from) $q->whereDate('sale_date', '>=', $request->date_from);
                if ($request->date_to) $q->whereDate('sale_date', '<=', $request->date_to);
            }])
            ->withSum(['sales' => function($q) use ($companyId, $request) {
                $q->where('company_id', $companyId);
                if ($request->date_from) $q->whereDate('sale_date', '>=', $request->date_from);
                if ($request->date_to) $q->whereDate('sale_date', '<=', $request->date_to);
            }], 'total')
            ->get()
            ->map(function($bank) {
                return [
                    'id' => $bank->id,
                    'name' => $bank->name,
                    'account_name' => $bank->account_name,
                    'account_number' => $bank->account_number,
                    'transaction_count' => $bank->sales_count,
                    'total_amount' => $bank->sales_sum_total ?? 0
                ];
            });

            return response()->json([
                'success' => true,
                'data' => $sales,
                'summary' => $summary,
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
     * Get bank transaction report (specifically for bank transfers)
     */
    public function getBankTransactionReport(Request $request)
    {
        $request->validate([
            'date_from' => 'nullable|date',
            'date_to' => 'nullable|date|after_or_equal:date_from',
            'bank_id' => 'nullable|exists:banks,id',
            'transaction_reference' => 'nullable|string',
            'per_page' => 'nullable|integer|min:1|max:100'
        ]);

        try {
            $companyId = $request->user()->company_id;
            
            $query = Sale::where('company_id', $companyId)
                ->whereNotNull('bank_id')
                ->whereNotNull('transaction_reference')
                ->where(function($q) {
                    $q->where('payment_method', 'LIKE', '%bank%')
                      ->orWhere('payment_method', 'LIKE', '%transfer%');
                })
                ->with(['customer', 'user', 'bank', 'items.product', 'payments']);

            // Date filter
            if ($request->date_from) {
                $query->whereDate('sale_date', '>=', $request->date_from);
            }
            if ($request->date_to) {
                $query->whereDate('sale_date', '<=', $request->date_to);
            }

            // Bank filter
            if ($request->bank_id) {
                $query->where('bank_id', $request->bank_id);
            }

            // Transaction reference filter
            if ($request->transaction_reference) {
                $query->where('transaction_reference', 'LIKE', '%' . $request->transaction_reference . '%');
            }

            // Get paginated results
            $transactions = $query->orderBy('created_at', 'desc')
                                 ->paginate($request->per_page ?? 20);

            // Calculate summaries
            $summary = [
                'total_transactions' => $query->count(),
                'total_amount' => $query->sum('total'),
                'average_transaction' => $query->avg('total'),
                'unique_customers' => $query->distinct('customer_id')->count('customer_id'),
                'total_banks_used' => $query->distinct('bank_id')->count('bank_id')
            ];

            // Bank breakdown
            $bankBreakdown = Bank::whereHas('sales', function($q) use ($companyId, $request) {
                $q->where('company_id', $companyId)
                  ->whereNotNull('transaction_reference');
                if ($request->date_from) $q->whereDate('sale_date', '>=', $request->date_from);
                if ($request->date_to) $q->whereDate('sale_date', '<=', $request->date_to);
            })
            ->withCount(['sales' => function($q) use ($companyId, $request) {
                $q->where('company_id', $companyId)
                  ->whereNotNull('transaction_reference');
                if ($request->date_from) $q->whereDate('sale_date', '>=', $request->date_from);
                if ($request->date_to) $q->whereDate('sale_date', '<=', $request->date_to);
            }])
            ->withSum(['sales' => function($q) use ($companyId, $request) {
                $q->where('company_id', $companyId)
                  ->whereNotNull('transaction_reference');
                if ($request->date_from) $q->whereDate('sale_date', '>=', $request->date_from);
                if ($request->date_to) $q->whereDate('sale_date', '<=', $request->date_to);
            }], 'total')
            ->get()
            ->map(function($bank) use ($summary) {
                return [
                    'id' => $bank->id,
                    'name' => $bank->name,
                    'account_name' => $bank->account_name,
                    'account_number' => $bank->account_number,
                    'transaction_count' => $bank->sales_count,
                    'total_amount' => $bank->sales_sum_total ?? 0,
                    'percentage' => $summary['total_amount'] > 0 
                        ? round(($bank->sales_sum_total / $summary['total_amount']) * 100, 2) 
                        : 0
                ];
            });

            return response()->json([
                'success' => true,
                'data' => $transactions,
                'summary' => $summary,
                'bank_breakdown' => $bankBreakdown
            ]);

        } catch (\Exception $e) {
            Log::error('Bank transaction report error: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Failed to fetch bank transactions',
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
            
            $transaction = Sale::where('company_id', $companyId)
                ->whereNotNull('bank_id')
                ->whereNotNull('transaction_reference')
                ->with(['customer', 'user', 'bank', 'items.product', 'payments'])
                ->findOrFail($id);

            // Get bank account details
            $bankDetails = null;
            if ($transaction->bank) {
                $bankDetails = [
                    'bank_name' => $transaction->bank->name,
                    'account_name' => $transaction->bank->account_name,
                    'account_number' => $transaction->bank->account_number,
                    'branch' => $transaction->bank->branch,
                    'bank_code' => $transaction->bank->bank_code
                ];
            }

            return response()->json([
                'success' => true,
                'data' => [
                    'sale' => $transaction,
                    'bank_details' => $bankDetails,
                    'transaction' => [
                        'reference' => $transaction->transaction_reference,
                        'date' => $transaction->sale_date,
                        'amount' => $transaction->total,
                        'payment_method' => $transaction->payment_method,
                        'deposit_slip' => $transaction->deposit_slip ?? null
                    ],
                    'customer' => [
                        'name' => $transaction->customer->name ?? $transaction->customer_name ?? 'Walk-in Customer',
                        'phone' => $transaction->customer->phone ?? $transaction->customer_phone ?? null,
                        'email' => $transaction->customer->email ?? null
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
}