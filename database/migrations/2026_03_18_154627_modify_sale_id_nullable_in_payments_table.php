<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up()
    {
        Schema::table('payments', function (Blueprint $table) {
            // Make sale_id nullable
            $table->foreignId('sale_id')->nullable()->change();
        });
    }

    public function down()
    {
        Schema::table('payments', function (Blueprint $table) {
            // Revert to NOT NULL (this might fail if there are null values)
            $table->foreignId('sale_id')->nullable(false)->change();
        });
    }
};