<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up()
    {
        Schema::create('purchase_orders', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('vendor_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained(); // Who created the PO
            $table->string('po_number')->unique();
            $table->date('order_date');
            $table->date('expected_delivery_date')->nullable();
            $table->date('delivery_date')->nullable();
            $table->string('status')->default('draft'); // draft, sent, received, cancelled
            $table->string('payment_status')->default('pending'); // pending, paid, partial
            $table->decimal('subtotal', 15, 2)->default(0);
            $table->decimal('tax', 15, 2)->default(0);
            $table->decimal('discount', 15, 2)->default(0);
            $table->decimal('total', 15, 2)->default(0);
            $table->text('notes')->nullable();
            $table->string('tracking_number')->nullable();
            $table->string('waybill_number')->nullable()->after('tracking_number');
            $table->string('truck_number')->nullable()->after('waybill_number');
            $table->timestamps();
            
            $table->index(['company_id', 'status']);
            $table->index(['company_id', 'vendor_id']);
            $table->index('waybill_number');
            $table->index('truck_number');
        });
    }

    public function down()
    {
        Schema::dropIfExists('purchase_orders');
    }
};