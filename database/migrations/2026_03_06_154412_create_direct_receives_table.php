<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up()
    {
        Schema::create('direct_receives', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('vendor_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('user_id')->constrained(); // Who received
            $table->string('reference_number')->unique(); // DR-20240306-001
            $table->date('receive_date');
            
            // Vendor info (even if not in database)
            $table->string('vendor_name')->nullable();
            $table->string('vendor_phone')->nullable();
            
            // Tracking
            $table->string('waybill_number')->nullable();
            $table->string('truck_number')->nullable();
            $table->string('driver_name')->nullable();
            $table->string('driver_phone')->nullable();
            
            // Financial
            $table->decimal('subtotal', 15, 2)->default(0);
            $table->decimal('tax', 15, 2)->default(0);
            $table->decimal('discount', 15, 2)->default(0);
            $table->decimal('total', 15, 2)->default(0);
            $table->string('payment_status')->default('pending'); // pending, paid, partial
            $table->string('payment_method')->nullable(); // cash, transfer, pos
            
            // Notes
            $table->text('notes')->nullable();
            
            $table->timestamps();
            $table->softDeletes();
            
            $table->index(['company_id', 'receive_date']);
            $table->index('reference_number');
        });
    }

    public function down()
    {
        Schema::dropIfExists('direct_receives');
    }
};