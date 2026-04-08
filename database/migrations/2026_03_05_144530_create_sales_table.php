<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up()
    {
        Schema::create('sales', function (Blueprint $table) {
          $table->id();
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
            $table->boolean('is_closed')->default(false);
                
            // Bank
            $table->foreignId('bank_id')->nullable()->constrained('banks')->onDelete('set null');
            $table->string('transaction_reference')->nullable();
            $table->string('deposit_slip')->nullable();
            $table->boolean('is_split_payment')->default(false);

            $table->text('notes')->nullable();
            $table->timestamps();
            
            $table->softDeletes();
            
            $table->index(['company_id', 'sale_date']);
            $table->index(['company_id', 'invoice_number']);
            $table->index(['company_id', 'customer_id']);
            $table->index('payment_status');
            $table->index('is_closed');
            
      
            
        });
    }

    public function down()
    {
        Schema::dropIfExists('sales');
    }
};