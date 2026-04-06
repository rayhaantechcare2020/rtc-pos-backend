// database/migrations/2024_01_01_000011_add_bank_to_sales_table.php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class AddBankToSalesTable extends Migration
{
    public function up()
    {
        Schema::table('sales', function (Blueprint $table) {
            $table->enum('payment_method', ['cash', 'bank'])->default('cash')->after('total');
            $table->foreignId('bank_id')->nullable()->constrained('banks')->onDelete('set null')->after('payment_method');
            $table->string('transaction_reference')->nullable()->after('bank_id');
            $table->string('deposit_slip')->nullable()->after('transaction_reference');
        });
    }

    public function down()
    {
        Schema::table('sales', function (Blueprint $table) {
            $table->dropColumn(['payment_method', 'bank_id', 'transaction_reference', 'deposit_slip']);
        });
    }
}