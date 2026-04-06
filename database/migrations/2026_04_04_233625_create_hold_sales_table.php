// database/migrations/2024_01_01_000000_create_hold_sales_table.php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateHoldSalesTable extends Migration
{
    public function up()
    {
        Schema::create('hold_sales', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->onDelete('cascade');
            $table->foreignId('user_id')->constrained()->onDelete('cascade');
            $table->foreignId('customer_id')->nullable()->constrained()->onDelete('set null');
            $table->string('hold_reference')->unique();
            $table->string('customer_name')->nullable();
            $table->string('customer_phone')->nullable();
            $table->json('cart_items');
            $table->decimal('subtotal', 15, 2)->default(0);
            $table->decimal('discount', 15, 2)->default(0);
            $table->decimal('total', 15, 2)->default(0);
            $table->text('notes')->nullable();
            $table->datetime('held_at');
            $table->datetime('expires_at')->nullable();
            $table->enum('status', ['active', 'expired', 'converted'])->default('active');
            $table->timestamps();
            
            $table->index(['company_id', 'status']);
            $table->index(['user_id', 'status']);
            $table->index('hold_reference');
        });
    }

    public function down()
    {
        Schema::dropIfExists('hold_sales');
    }
}