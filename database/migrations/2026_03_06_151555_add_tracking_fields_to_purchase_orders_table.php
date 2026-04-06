<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up()
    {
        Schema::table('purchase_orders', function (Blueprint $table) {
            // Add waybill and truck number fields
            $table->string('tracking_number')->nullable();
            $table->string('waybill_number')->nullable()->after('tracking_number');
            $table->string('truck_number')->nullable()->after('waybill_number');
             

            // You can also add index for searching
            $table->index('waybill_number');
            $table->index('truck_number');
        });
    }

    public function down()
    {
        Schema::table('purchase_orders', function (Blueprint $table) {
            $table->dropIndex(['waybill_number']);
            $table->dropIndex(['truck_number']);
            $table->dropColumn(['waybill_number', 'truck_number','tracking_number']);
        });
    }
};