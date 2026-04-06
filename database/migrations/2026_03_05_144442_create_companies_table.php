<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up()
    {
        Schema::create('companies', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('email')->unique();
            $table->string('phone')->nullable();
            $table->string('address')->nullable();
            $table->string('logo')->nullable();
            $table->string('website')->nullable();
            $table->string('tax_number')->nullable();
            $table->string('registration_number')->nullable();
            $table->string('currency')->default('₦'); // Naira default
            $table->string('currency_code')->default('NGN');
            $table->string('timezone')->default('Africa/Lagos');
            $table->string('date_format')->default('d/m/Y');
            
            // Store settings as JSON
            $table->json('settings')->nullable();
            
            // Status
            $table->string('status')->default('active'); // active, suspended, inactive
            
            $table->timestamps();
        });
    }

    public function down()
    {
        Schema::dropIfExists('companies');
    }
};