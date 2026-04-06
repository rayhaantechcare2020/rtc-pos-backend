<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('sales', function (Blueprint $table) {
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained(); // Cashier
            $table->foreignId('customer_id')->nullable()->constrained()->nullOnDelete();
            $table->string('invoice_number')->unique();
            $table->date('sale_date');
            $table->time('sale_time');
            
            // Items summary
            $table->integer('item_count')->default(0);
            $table->decimal('subtotal', 15, 2)->default(0);
            $table->decimal('tax', 15, 2)->default(0);
            $table->decimal('discount', 15, 2)->default(0);
            $table->decimal('total', 15, 2)->default(0);
            
            // Payment
            $table->decimal('amount_paid', 15, 2)->default(0);
            $table->decimal('change_due', 15, 2)->default(0);
            $table->string('payment_status')->default('paid'); // paid, partial, credit
            $table->string('status')->default('completed'); // completed, voided, returned
            
            // For credit sales
            $table->decimal('balance_due', 15, 2)->default(0);
            $table->date('due_date')->nullable();
            
            $table->text('notes')->nullable();
            $table->timestamps();
            
            $table->softDeletes();
            
            $table->index(['company_id', 'sale_date']);
            $table->index(['company_id', 'invoice_number']);
            $table->index(['company_id', 'customer_id']);
            $table->index('payment_status');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('sales', function (Blueprint $table) {
            $table->dropColumn(['company_id','user_id','customer_id','invoice_number', 'sale_day','sale_time','item_count','subtotal','tax','discount','total','amount_paid','change_due','payment_status',
            'status','balance_due','due_date','notes']);
        });
    }
};
