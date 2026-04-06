<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up()
    {
        Schema::create('sale_items', function (Blueprint $table) {
          $table->id();
          
    
        });
    }

    public function down()
    {
        Schema::dropIfExists('sale_items');
    }
};