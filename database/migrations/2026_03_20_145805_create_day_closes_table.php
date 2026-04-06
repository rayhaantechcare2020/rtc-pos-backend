<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up()
    {
        Schema::create('day_closes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained(); // Who closed the day
            $table->date('close_date');
            $table->timestamp('closed_at');
            $table->integer('total_sales')->default(0);
            $table->decimal('total_revenue', 15, 2)->default(0);
            $table->json('summary')->nullable();
            $table->timestamps();
            
            $table->unique(['company_id', 'close_date']); // Prevent duplicate closing
            $table->index('close_date');
        });
        
        // Add is_closed column to sales table
        Schema::table('sales', function (Blueprint $table) {
            if (!Schema::hasColumn('sales', 'is_closed')) {
                $table->boolean('is_closed')->default(false)->after('status');
                $table->index('is_closed');
            }
        });
    }

    public function down()
    {
        Schema::table('sales', function (Blueprint $table) {
            $table->dropColumn('is_closed');
        });
        Schema::dropIfExists('day_closes');
    }
};