<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up()
    {
        Schema::create('direct_receive_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('direct_receive_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_id')->constrained()->cascadeOnDelete();
            
            $table->integer('quantity');
            $table->decimal('unit_cost', 15, 2);
            $table->decimal('discount', 15, 2)->default(0);
            $table->decimal('tax', 15, 2)->default(0);
            $table->decimal('total', 15, 2);
            
            // For products not in system yet
            $table->string('product_name')->nullable();
            $table->string('product_sku')->nullable();
            
            $table->date('expiry_date')->nullable(); // For perishable items
            $table->text('notes')->nullable();
            
            $table->timestamps();
            
            $table->index(['direct_receive_id', 'product_id']);
        });
    }

    public function down()
    {
        Schema::dropIfExists('direct_receive_items');
    }
};