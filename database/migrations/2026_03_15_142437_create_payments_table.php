<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreatePaymentsTable extends Migration
{
    public function up()
    {
        Schema::create('payments', function (Blueprint $table) {
            $table->id();
             $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('sale_id')->constrained()->onDelete('cascade')->nullable();
            $table->string('method'); // cash, bank, pos, transfer, credit
            $table->foreignId('bank_id')->nullable()->constrained('banks')->onDelete('set null');
            $table->string('transaction_reference')->nullable();
            $table->decimal('amount', 15, 2);
            $table->string('reference')->nullable();
            $table->string('status')->default('completed');
            $table->text('notes')->nullable();
            $table->foreignId('user_id')->nullable()->constrained('users')->onDelete('set null');
            $table->foreignId('processed_by')->nullable()->constrained('users');
            $table->date('payment_date')->nullable()->after('reference');
            $table->unsignedBigInteger('customer_id')->nullable();
            $table->foreign('customer_id')->references('id')->on('customers')->onDelete('set null');
            $table->timestamps();
        });
    }

    public function down()
    {
        Schema::dropIfExists('payments');
    }
}