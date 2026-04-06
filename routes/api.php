<?php

use Illuminate\Support\Facades\Route;
//use Illuminate\Http\Request;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\CompanyController;
use App\Http\Controllers\Api\ProductController;
use App\Http\Controllers\Api\CategoryController;
use App\Http\Controllers\Api\VendorController;
use App\Http\Controllers\Api\POSController;
use App\Http\Controllers\Api\CustomerController;
use App\Http\Controllers\Api\ReportController;
use App\Http\Controllers\Api\UserController;
use App\Http\Controllers\Api\PaymentController;
use App\Http\Controllers\Api\DirectReceiveController;
use App\Http\Controllers\Api\ImportController;
use App\Http\Controllers\Api\BankController;
use App\Http\Controllers\Api\SaleController;


// Public routes

Route::get('/test-connection', function () {
    return response()->json([
        'message' => 'Connection successful!',
        'status' => 'ok',
        'timestamp' => now()
    ]);
});

Route::post('/register', [AuthController::class, 'register']);
Route::post('/login', [AuthController::class, 'login']);
//Your existing login route
// Route::post('/login', function(Request $request) {
//     // This is just for testing - replace with your actual login logic
//     $credentials = $request->only('email', 'password');
    
//     // Test credentials for debugging
//     if ($credentials['email'] === 'admin@example.com' && $credentials['password'] === 'password') {
//         return response()->json([
//             'success' => true,
//             'message' => 'Login successful!',
//             'token' => 'test-token-123',
//             'user' => [
//                 'id' => 1,
//                 'email' => 'admin@example.com',
//                 'name' => 'Admin User'
//             ]
//         ]);
//     }
    
//     return response()->json([
//         'success' => false,
//         'message' => 'Invalid credentials'
//     ], 401);
// });




// Protected routes (require authentication)
Route::middleware('auth:sanctum')->group(function () {
    // Auth
    Route::post('/logout', [AuthController::class, 'logout']);
    Route::get('/user', [AuthController::class, 'user']);

    //User routes
    Route::apiResource('users', UserController::class);
    Route::get('/profile', [UserController::class, 'profile']);
    Route::put('/profile', [UserController::class, 'updateProfile']);

    // Payment routes
    Route::get('/payments', [PaymentController::class, 'index']);
     Route::get('/payments/summary', [PaymentController::class, 'summary']);
     Route::get('/payments/outstanding', [PaymentController::class, 'outstanding']);
     Route::post('/payments', [PaymentController::class, 'store']);
      Route::get('/payments/methods-stats', [PaymentController::class, 'methodsStats']);
    Route::get('/customers/{customerId}/payments', [PaymentController::class, 'customerPayments']);
    //Route::apiResource('payments', PaymentController::class)->except(['index', 'store', 'show', 'update', 'destroy']);
    
    //Route::get('/payments/updatesalepaymentstatus', [PaymentController::class, 'updateSalePaymentStatus']);
    
    Route::get('/payments/{id}', [PaymentController::class, 'show']);
    Route::put('/payments/{id}', [PaymentController::class, 'update']);
    Route::delete('/payments/{id}', [PaymentController::class, 'destroy']);
   
   
 
    Route::post('/payments/{id}/reverse', [PaymentController::class, 'reverse']);
    Route::post('/payments/bulk', [PaymentController::class, 'bulkStore']);
    
    // Company
    Route::get('/company', [CompanyController::class, 'show']);
    Route::put('/company', [CompanyController::class, 'update']);
    Route::post('/company/logo', [CompanyController::class, 'uploadLogo']);
    Route::get('/company/settings', [CompanyController::class, 'getSettings']);
    Route::put('/company/settings', [CompanyController::class, 'updateSettings']);// Printer settings routes
    Route::get('/company/printer-settings', [CompanyController::class, 'getPrinterSettings']);
    Route::put('/company/printer-settings', [CompanyController::class, 'updatePrinterSettings']);
    
    // Products
    Route::apiResource('products', ProductController::class);
    Route::get('/products-list', [ProductController::class, 'list']);
    Route::patch('/products/{id}/stock', [ProductController::class, 'updateStock']);
    
    // Categories
    Route::apiResource('categories', CategoryController::class);
    Route::get('/categories-list', [CategoryController::class, 'list']);
    Route::post('/categories/order', [CategoryController::class, 'updateOrder']);
    
    // Vendors
    Route::apiResource('vendors', VendorController::class);
    Route::get('/vendors-list', [VendorController::class, 'list']);
    Route::patch('/vendors/{id}/toggle-status', [VendorController::class, 'toggleStatus']);
    
    // Customers
    Route::apiResource('customers', CustomerController::class);
    Route::get('/customers-list', [CustomerController::class, 'list']);
    Route::patch('/customers/{id}/balance', [CustomerController::class, 'updateBalance']);
    Route::get('/customers/outstanding/balance', [CustomerController::class, 'outstandingBalance']);

    
    
    // POS Routes
    Route::prefix('pos')->group(function () {
        Route::get('/today', [POSController::class, 'today']);
        Route::post('/checkout', [POSController::class, 'checkout']);
        Route::post('/quick-sale', [POSController::class, 'quickSale']);
        Route::get('/summary', [POSController::class, 'summary']);
        Route::post('/receipt/{id}/email', [POSController::class, 'emailReceipt']);
        Route::post('/close-day', [POSController::class, 'closeDay']); // Add this line
        Route::get('/day-status', [POSController::class, 'dayStatus']); // Optional
        Route::post('/hold-sale', [POSController::class, 'holdSale']);
        Route::get('/held-sales', [POSController::class, 'getHeldSales']);
        Route::get('/held-sale/{reference}', [POSController::class, 'getHeldSale']);
        Route::post('/restore-held-sale/{reference}', [POSController::class, 'restoreHeldSale']);
        Route::delete('/held-sale/{reference}', [POSController::class, 'deleteHeldSale']);
        Route::post('/convert-held-sale/{reference}', [POSController::class, 'convertHeldSale']);
    

        Route::get('/receipt/test', function() {
            return "Receipt routes are working!";
        });

    });

    //Bank routes
    Route::apiResource('banks', BankController::class);

    // Sales routes
    Route::get('/sales', [POSController::class, 'sales']);
    Route::get('/sales/bank-transactions', [SaleController::class, 'getBankTransactionReport']);
    Route::get('/sales/bank-transactions/{id}', [SaleController::class, 'getBankTransactionDetail']);
    Route::get('/sales/{id}', [POSController::class, 'saleDetails']);
    Route::post('/sales/{id}/void', [POSController::class, 'voidSale']);
    Route::get('sales/report', [POSController::class, 'getSalesReport']);
    
    // Direct Receives
    Route::apiResource('direct-receives', DirectReceiveController::class);
    Route::post('/direct-receives/quick', [DirectReceiveController::class, 'quickReceive']);
    Route::patch('/direct-receives/{id}/payment', [DirectReceiveController::class, 'updatePayment']);
    
    // Reports
    Route::prefix('reports')->group(function () {
        Route::get('/customers', [ReportController::class, 'customerSummary']);
        Route::get('/inventory', [ReportController::class, 'inventorySummary']);
        Route::get('/daily-sales', [ReportController::class, 'dailySales']);
        Route::get('/sales-range', [ReportController::class, 'salesRange']);
        Route::get('/top-products', [ReportController::class, 'topProducts']);
        Route::get('/bank-transactions', [POSController::class, 'getBankTransactionReport']);
        Route::get('/bank-transactions/{id}', [POSController::class, 'getBankTransactionDetails']);
        //Route::get('/bank-transactions', [ReportController::class, 'bankTransactionReport']);
        Route::get('/bank-transactions/export', [ReportController::class, 'exportBankReport']);
        //Route::get('/bank-transactions/{id}', [ReportController::class, 'getSaleTransaction']);
        Route::get('/profit-loss', [ReportController::class, 'profitLoss']);
        Route::get('/cashier-sales', [ReportController::class, 'cashierSales']);
        Route::get('/end-of-day', [ReportController::class, 'endOfDay']);
        
    });

    // Import/Export routes
    Route::prefix('import')->group(function () {
        Route::post('/products', [ImportController::class, 'importProducts']);
        Route::get('/template', [ImportController::class, 'exportTemplate']);
        Route::post('/customers', [ImportController::class, 'importCustomers']);
    });
    
    Route::prefix('export')->group(function () {
        Route::get('/products', [ImportController::class, 'exportProducts']);
    });

});

// Public receipt print route to support token via query param in popup windows
Route::get('/pos/receipt/{id}/print', [POSController::class, 'printReceipt']);