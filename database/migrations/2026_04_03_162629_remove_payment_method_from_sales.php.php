<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class RemovePaymentMethodFromSales extends Migration
{
    public function up()
    {
        Schema::table('sales', function (Blueprint $table) {

            //Drop foreign key constraints
            $this->dropForeignKeys($table);
            
            // Remove old columns if they exist
            if (Schema::hasColumn('sales', 'payment_method')) {
                $table->dropColumn('payment_method');
            }
            if (Schema::hasColumn('sales', 'bank_id')) {
                $table->dropColumn('bank_id');
            }
            if (Schema::hasColumn('sales', 'transaction_reference')) {
                $table->dropColumn('transaction_reference');
            }
        });
    }

    private function dropForeignKeys($table)
    {
        //Drop foreign key constraint for bank_id
        try{
            $table->dropForeign(['bank_id']);
        }catch (\Exception $e)
        {
            
        }
    }
    public function down()
    {
        Schema::table('sales', function (Blueprint $table) {
            $table->string('payment_method')->nullable();
            $table->foreignId('bank_id')->nullable();
            $table->string('transaction_reference')->nullable();
        });
    }
}