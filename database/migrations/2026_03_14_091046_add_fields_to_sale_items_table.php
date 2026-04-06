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
        Schema::table('sale_items', function (Blueprint $table) {
            $table->foreignId('sale_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_id')->constrained()->restrictOnDelete();
            
            $table->integer('quantity');
            $table->decimal('price', 15, 2); // Selling price at time of sale
            $table->decimal('cost', 15, 2); // Cost price at time of sale (for profit calc)
            $table->decimal('discount', 15, 2)->default(0);
            $table->decimal('tax', 15, 2)->default(0);
            $table->decimal('subtotal', 15, 2); // price * quantity
            $table->decimal('total', 15, 2); // subtotal - discount + tax
            
            $table->string('product_name')->nullable(); // Snapshot in case product changes
            $table->string('product_sku')->nullable();
            $table->timestamps();
            
            
            $table->index(['sale_id', 'product_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('sale_items', function (Blueprint $table) {
            $table->dropColumn(['sale_items','product_id','quantity','price','cost','discount','tax',
            'subtotal','total','product_name','product_sku']);
        });
    }
};
