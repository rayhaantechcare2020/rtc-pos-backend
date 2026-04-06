<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up()
    {
        Schema::create('purchase_order_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('purchase_order_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_id')->constrained()->cascadeOnDelete();
            
            $table->integer('quantity');
            $table->integer('received_quantity')->default(0);
            $table->integer('backordered_quantity')->default(0);
            
            $table->decimal('unit_cost', 15, 2);
            $table->decimal('discount', 15, 2)->default(0);
            $table->decimal('tax', 15, 2)->default(0);
            $table->decimal('total', 15, 2);
            
            $table->date('expected_delivery_date')->nullable();
            $table->text('notes')->nullable();
            
            $table->timestamps();
            
            $table->index(['purchase_order_id', 'product_id']);
        });
    }

    public function down()
    {
        Schema::dropIfExists('purchase_order_items');
    }
};