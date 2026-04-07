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
            $table->foreignId('sale_id')->constrained()->onDelete('cascade');
            $table->string('method'); // cash, bank, pos, transfer, credit
            $table->foreignId('bank_id')->nullable()->constrained('banks')->onDelete('set null');
            $table->string('transaction_reference')->nullable();
            $table->decimal('amount', 15, 2);
            $table->string('status')->default('completed');
            $table->text('notes')->nullable();
            $table->foreignId('processed_by')->nullable()->constrained('users');
            $table->timestamps();
        });
    }

    public function down()
    {
        Schema::dropIfExists('payments');
    }
}