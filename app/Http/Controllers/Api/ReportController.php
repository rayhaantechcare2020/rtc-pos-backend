<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Sale;
use App\Models\Product;
use App\Models\Customer;
//use App\Models\Vendor;
//use App\Models\PurchaseOrder;
//use App\Models\DirectReceive;
use App\Models\Bank;
use App\Models\SaleItem;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

class ReportController extends Controller
{
    /**
     * Daily sales summary
     */
       public function dailySales(Request $request)
{
    try {
        $companyId = $request->user()->company_id;
        $date = $request->date ?? now()->format('Y-m-d');
        
        $sales = Sale::where('company_id', $companyId)
            ->whereDate('sale_date', $date)
            ->with(['items', 'payments', 'customer', 'user'])
            ->get();
        
        $summary = [
            'date' => $date,
            'total_transactions' => $sales->count(),
            'total_revenue' => $sales->sum('total'),
            'total_profit' => $sales->sum(function($sale) {
                return $sale->items->sum(function($item) {
                    return ($item->price - $item->cost) * $item->quantity;
                });
            }),
            'total_items_sold' => $sales->sum('item_count'),
            'average_sale' => $sales->avg('total'),
            'by_payment_method' => [
                'cash' => $sales->filter(function($sale) {
                    return $sale->payments->first()?->payment_method === 'cash';
                })->sum('total'),
                'transfer' => $sales->filter(function($sale) {
                    return $sale->payments->first()?->payment_method === 'transfer';
                })->sum('total'),
                'pos' => $sales->filter(function($sale) {
                    return $sale->payments->first()?->payment_method === 'pos';
                })->sum('total'),
                'credit' => $sales->filter(function($sale) {
                    return $sale->payments->first()?->payment_method === 'credit';
                })->sum('total'),
            ]
        ];
        
        $transactions = $sales->map(function($sale) {
            return [
                'time' => $sale->created_at->format('H:i'),
                'invoice' => $sale->invoice_number,
                'cashier' => $sale->user->name,
                'customer' => $sale->customer->name ?? 'Walk-in',
                'items' => $sale->item_count,
                'total' => $sale->total,
                'payment' => $sale->payments->first()?->payment_method ?? 'cash'
            ];
        });
        
        return response()->json([
            'success' => true,
            'data' => [
                'summary' => $summary,
                'transactions' => $transactions
            ]
        ]);
        
    } catch (\Exception $e) {
        return response()->json([
            'success' => false,
            'message' => 'Failed to fetch daily sales',
            'error' => $e->getMessage()
        ], 500);
    }
}

    /**
     * Sales by date range
     */
    public function salesRange(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'from' => 'required|date',
            'to' => 'required|date|after_or_equal:from',
            'group_by' => 'nullable|in:day,week,month'
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'errors' => $validator->errors()
            ], 422);
        }

        $companyId = $request->user()->company_id;
        $from = $request->from;
        $to = $request->to;
        $groupBy = $request->group_by ?? 'day';

        $query = Sale::forCompany($companyId)
            ->whereBetween('sale_date', [$from, $to])
            ->with('payments');

        // Group by format
        $groupFormat = match($groupBy) {
            'day' => '%Y-%m-%d',
            'week' => '%Y-%u',
            'month' => '%Y-%m',
            default => '%Y-%m-%d'
        };

        $sales = $query->get();
        
        $grouped = $sales->groupBy(function($sale) use ($groupBy) {
            return match($groupBy) {
                'day' => $sale->sale_date->format('Y-m-d'),
                'week' => $sale->sale_date->format('Y-W'),
                'month' => $sale->sale_date->format('Y-m'),
            };
        })->map(function($group, $key) {
            return [
                'period' => $key,
                'count' => $group->count(),
                'revenue' => $group->sum('total'),
                'profit' => $group->sum('profit'),
                'items_sold' => $group->sum('item_count')
            ];
        })->values();

        $summary = [
            'from' => $from,
            'to' => $to,
            'total_sales' => $sales->count(),
            'total_revenue' => $sales->sum('total'),
            'total_profit' => $sales->sum('profit'),
            'average_per_day' => $sales->average('total'),
            'average_sale' => $sales->average('total'),
            'best_day' => $sales->sortByDesc('total')->first()?->sale_date,
            'payment_methods' => $sales->flatMap->payments->groupBy('payment_method')
                ->map->sum('amount')
        ];

        return response()->json([
            'success' => true,
            'data' => [
                'summary' => $summary,
                'breakdown' => $grouped
            ]
        ]);
    }

    /**
     * Top selling products
     */
    public function topProducts(Request $request)
    {
        $companyId = $request->user()->company_id;
        $limit = $request->limit ?? 10;
        $from = $request->from ?? now()->subDays(30);
        $to = $request->to ?? now();

        $topProducts = DB::table('sale_items')
            ->join('sales', 'sale_items.sale_id', '=', 'sales.id')
            ->join('products', 'sale_items.product_id', '=', 'products.id')
            ->where('sales.company_id', $companyId)
            ->whereBetween('sales.sale_date', [$from, $to])
            ->select(
                'products.id',
                'products.name',
                'products.sku',
                DB::raw('SUM(sale_items.quantity) as total_quantity'),
                DB::raw('SUM(sale_items.total) as total_revenue'),
                DB::raw('SUM(sale_items.quantity * (sale_items.price - sale_items.cost)) as total_profit')
            )
            ->groupBy('products.id', 'products.name', 'products.sku')
            ->orderBy('total_quantity', 'desc')
            ->limit($limit)
            ->get();

        return response()->json([
            'success' => true,
            'data' => $topProducts
        ]);
    }

    /**
     * Inventory summary
     */
    public function inventorySummary(Request $request)
    {
        $companyId = $request->user()->company_id;

        $summary = [
            'total_products' => Product::forCompany($companyId)->count(),
            'total_value' => Product::forCompany($companyId)
                ->get()
                ->sum(function($product) {
                    return $product->stock_quantity * $product->cost;
                }),
            'low_stock' => Product::forCompany($companyId)
                ->lowStock()
                ->get(['name', 'stock_quantity', 'low_stock_threshold']),
            'out_of_stock' => Product::forCompany($companyId)
                ->outOfStock()
                ->count(),
            'categories' => DB::table('products')
                ->join('categories', 'products.category_id', '=', 'categories.id')
                ->where('products.company_id', $companyId)
                ->select(
                    'categories.id',
                    'categories.name',
                    DB::raw('COUNT(products.id) as product_count'),
                    DB::raw('SUM(products.stock_quantity * products.cost) as category_value')
                )
                ->groupBy('categories.id', 'categories.name')
                ->get()
        ];

        return response()->json([
            'success' => true,
            'data' => $summary
        ]);
    }

    /**
     * Customer summary
     */
    public function customerSummary(Request $request)
    {
        $companyId = $request->user()->company_id;

        $customers = Customer::forCompany($companyId)
            ->with(['sales' => function($query) {
                $query->orderBy('created_at', 'desc');
            }])
            ->get()
            ->map(function($customer) {
                return [
                    'id' => $customer->id,
                    'name' => $customer->name,
                    'phone' => $customer->phone,
                    'total_purchases' => $customer->sales->count(),
                    'total_spent' => $customer->sales->sum('total'),
                    'balance' => $customer->current_balance,
                    'last_purchase' => $customer->sales->first()?->created_at,
                    'credit_limit' => $customer->credit_limit
                ];
            })
            ->sortByDesc('total_spent')
            ->values();

        $summary = [
            'total_customers' => $customers->count(),
            'total_receivables' => $customers->sum('balance'),
            'average_spent' => $customers->avg('total_spent'),
            'top_customer' => $customers->first()
        ];

        return response()->json([
            'success' => true,
            'data' => [
                'summary' => $summary,
                'customers' => $customers
            ]
        ]);
    }

    /**
     * Profit & Loss report
     */
  /**
 * Get profit & loss report
 */
public function profitLoss(Request $request)
{
    // Add validation to make from and to required
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
        $from = $request->from;
        $to = $request->to;

        // Log the request for debugging
        \Log::info('Profit Loss Request:', [
            'from' => $from,
            'to' => $to,
            'company_id' => $companyId
        ]);

        // Get all sales in period
        $sales = Sale::where('company_id', $companyId)
            ->whereBetween('sale_date', [$from, $to])
            ->where('status', 'completed')
            ->with('items')
            ->get();

        // Log how many sales found
        \Log::info('Sales found: ' . $sales->count());

        // Revenue calculations
        $totalRevenue = $sales->sum('total');
        
        // Cost of Goods Sold
        $cogs = $sales->sum(function($sale) {
            return $sale->items->sum(function($item) {
                return $item->cost * $item->quantity;
            });
        });

        // Gross profit
        $grossProfit = $sales->sum(function($sale) {
            return $sale->items->sum(function($item) {
                return ($item->price - $item->cost) * $item->quantity;
            });
        });

        // Gross margin percentage
        $grossMargin = $totalRevenue > 0 ? round(($grossProfit / $totalRevenue) * 100, 2) : 0;

        // Revenue by category
        $revenueByCategory = SaleItem::select(
                'categories.id',
                'categories.name',
                DB::raw('SUM(sale_items.subtotal) as revenue')
            )
            ->join('sales', 'sale_items.sale_id', '=', 'sales.id')
            ->join('products', 'sale_items.product_id', '=', 'products.id')
            ->leftJoin('categories', 'products.category_id', '=', 'categories.id')
            ->where('sales.company_id', $companyId)
            ->whereBetween('sales.sale_date', [$from, $to])
            ->where('sales.status', 'completed')
            ->groupBy('categories.id', 'categories.name')
            ->get();

        return response()->json([
            'success' => true,
            'data' => [
                'period' => [
                    'from' => $from,
                    'to' => $to
                ],
                'summary' => [
                    'revenue' => $totalRevenue,
                    'cogs' => $cogs,
                    'gross_profit' => $grossProfit,
                    'gross_margin' => $grossMargin,
                    
                ],
                'revenue_breakdown' => $revenueByCategory
            ]
        ]);

    } catch (\Exception $e) {
        \Log::error('Profit & Loss error: ' . $e->getMessage());
        return response()->json([
            'success' => false,
            'message' => 'Failed to fetch profit & loss',
            'error' => $e->getMessage()
        ], 500);
    }
}

public function bankTransactionReport(Request $request)
    {
        $request->validate([
            'bank_id' => 'nullable|exists:banks,id',
            'date_from' => 'nullable|date',
            'date_to' => 'nullable|date|after_or_equal:date_from',
            'transaction_reference' => 'nullable|string',
            'per_page' => 'nullable|integer|min:1|max:100',
            'export' => 'nullable|in:pdf,excel'
        ]);

        $companyId = $request->user()->company_id;

        $query = Sale::forCompany($companyId)
            ->with(['customer', 'user', 'bank', 'items.product'])
            ->whereNotNull('bank_id');

        // Filter by bank
        if ($request->bank_id) {
            $query->where('bank_id', $request->bank_id);
        }

        // Filter by date range
        if ($request->date_from) {
            $query->whereDate('created_at', '>=', $request->date_from);
        }
        if ($request->date_to) {
            $query->whereDate('created_at', '<=', $request->date_to);
        }

        // Filter by transaction reference
        if ($request->transaction_reference) {
            $query->where('transaction_reference', 'LIKE', '%' . $request->transaction_reference . '%');
        }

        // Order by latest first
        $query->orderBy('created_at', 'desc');

        // Clone query for summaries (before pagination)
        $summaryQuery = clone $query;

        // Get results with pagination
        $sales = $query->paginate($request->per_page ?? 20);

        // Calculate summaries using the cloned query
        $totalTransactions = $summaryQuery->count();
        $totalAmount = $summaryQuery->sum('total');
        $averageTransaction = $totalTransactions > 0 ? $summaryQuery->avg('total') : 0;
        $uniqueCustomers = $summaryQuery->distinct('customer_id')->pluck('customer_id')->filter()->unique()->count();
        $totalBanksUsed = $summaryQuery->distinct('bank_id')->pluck('bank_id')->filter()->unique()->count();

        $summary = [
            'total_transactions' => $totalTransactions,
            'total_amount' => $totalAmount,
            'average_transaction' => $averageTransaction,
            'unique_customers' => $uniqueCustomers,
            'total_banks_used' => $totalBanksUsed
        ];

        // Get breakdown by bank
        $bankIds = $summaryQuery->distinct('bank_id')->pluck('bank_id');
        $bankBreakdown = Bank::where('company_id', $companyId)
            ->whereIn('id', $bankIds)
            ->with(['sales' => function($q) use ($request, $companyId) {
                $q->where('company_id', $companyId)->whereNotNull('bank_id');
                if ($request->date_from) $q->whereDate('created_at', '>=', $request->date_from);
                if ($request->date_to) $q->whereDate('created_at', '<=', $request->date_to);
            }])
            ->get()
            ->map(function($bank) {
                $transactionCount = $bank->sales->count();
                $totalAmount = $bank->sales->sum('total');
                return [
                    'id' => $bank->id,
                    'name' => $bank->name,
                    'account_name' => $bank->account_name,
                    'account_number' => $bank->account_number,
                    'transaction_count' => $transactionCount,
                    'total_amount' => $totalAmount,
                    'percentage' => 0 // Will calculate later
                ];
            });

        // Calculate percentages
        $totalAmount = $summary['total_amount'];
        foreach ($bankBreakdown as &$bank) {
            $bank['percentage'] = $totalAmount > 0 ? round(($bank['total_amount'] / $totalAmount) * 100, 2) : 0;
        }

        return response()->json([
            'success' => true,
            'data' => $sales,
            'summary' => $summary,
            'bank_breakdown' => $bankBreakdown,
            'filters' => $request->all()
        ]);
    }

    /**
     * Get single sale transaction details with bank info
     */
    public function getSaleTransaction(Request $request, $id)
    {
        $companyId = $request->user()->company_id;

        $sale = Sale::forCompany($companyId)
            ->with(['customer', 'user', 'bank', 'items.product', 'payments'])
            ->whereNotNull('bank_id')
            ->findOrFail($id);

        // Get bank account details
        $bankDetails = null;
        if ($sale->bank) {
            $bankDetails = [
                'bank_name' => $sale->bank->name,
                'account_name' => $sale->bank->account_name,
                'account_number' => $sale->bank->account_number,
                'branch' => $sale->bank->branch,
                'bank_code' => $sale->bank->bank_code
            ];
        }

        return response()->json([
            'success' => true,
            'data' => [
                'sale' => $sale,
                'bank_details' => $bankDetails,
                'transaction' => [
                    'reference' => $sale->transaction_reference,
                    'date' => $sale->created_at,
                    'amount' => $sale->total,
                    'payment_method' => $sale->payment_method,
                    'deposit_slip' => $sale->deposit_slip_path ?? null
                ],
                'customer' => [
                    'name' => $sale->customer_name ?? $sale->customer->name ?? 'Walk-in Customer',
                    'phone' => $sale->customer_phone ?? $sale->customer->phone ?? null,
                    'email' => $sale->customer->email ?? null
                ]
            ]
        ]);
    }

    /**
     * Export bank transaction report
     */
    public function exportBankReport(Request $request)
    {
        $request->validate([
            'bank_id' => 'nullable|exists:banks,id',
            'date_from' => 'nullable|date',
            'date_to' => 'nullable|date|after_or_equal:date_from',
            'format' => 'required|in:pdf,excel,csv'
        ]);

        $companyId = $request->user()->company_id;

        $query = Sale::forCompany($companyId)
            ->with(['customer', 'user', 'bank', 'items.product'])
            ->whereNotNull('bank_id');

        if ($request->bank_id) {
            $query->where('bank_id', $request->bank_id);
        }
        if ($request->date_from) {
            $query->whereDate('created_at', '>=', $request->date_from);
        }
        if ($request->date_to) {
            $query->whereDate('created_at', '<=', $request->date_to);
        }

        $sales = $query->orderBy('created_at', 'desc')->get();

        if ($request->format === 'csv') {
            return $this->exportToCSV($sales);
        } elseif ($request->format === 'excel') {
            return $this->exportToExcel($sales);
        } else {
            return $this->exportToPDF($sales);
        }
    }

    private function exportToCSV($sales)
    {
        $filename = 'bank_transactions_' . date('Y-m-d') . '.csv';
        $handle = fopen('php://temp', 'w+');

        // Headers
        fputcsv($handle, [
            'Date', 'Invoice No', 'Customer', 'Bank', 'Account Name', 
            'Account Number', 'Transaction Ref', 'Amount', 'Items', 
            'Cashier', 'Status'
        ]);

        // Data
        foreach ($sales as $sale) {
            fputcsv($handle, [
                $sale->created_at->format('Y-m-d H:i'),
                $sale->invoice_number,
                $sale->customer_name ?? $sale->customer->name ?? 'Walk-in',
                $sale->bank->name ?? 'N/A',
                $sale->bank->account_name ?? 'N/A',
                $sale->bank->account_number ?? 'N/A',
                $sale->transaction_reference,
                number_format($sale->total, 2),
                $sale->items->count(),
                $sale->user->name,
                $sale->status
            ]);
        }

        rewind($handle);
        $csv = stream_get_contents($handle);
        fclose($handle);

        return response($csv, 200)
            ->header('Content-Type', 'text/csv')
            ->header('Content-Disposition', 'attachment; filename="' . $filename . '"');
    }

    private function exportToExcel($sales)
    {
        // You'll need to install maatwebsite/excel package
        // For now, return CSV
        return $this->exportToCSV($sales);
    }

    private function exportToPDF($sales)
    {
        // You'll need to install barryvdh/laravel-dompdf
        // For now, return JSON
        return response()->json([
            'success' => true,
            'data' => $sales,
            'message' => 'PDF export coming soon'
        ]);
    }



}