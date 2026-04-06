<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('customers', function (Blueprint $table) {
            $table->string('name')->after('company_id');
            $table->string('email')->nullable()->after('name');
            $table->string('phone')->nullable()->after('email');
            $table->string('address')->nullable()->after('phone');
            $table->string('tax_number')->nullable()->after('address');
            $table->decimal('credit_limit', 15, 2)->nullable()->after('tax_number');
            $table->decimal('current_balance', 15, 2)->default(0)->after('credit_limit');
            $table->string('status')->default('active')->after('current_balance');
            $table->text('notes')->nullable()->after('status');
            $table->softDeletes()->after('notes');
            $table->index(['company_id', 'name']);
            $table->index(['company_id', 'phone']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('customers', function (Blueprint $table) {
            $table->dropColumn(['name', 'email', 'phone', 'address', 'tax_number', 'credit_limit', 'current_balance', 'status', 'notes']);
            $table->dropSoftDeletes();
            $table->dropIndex(['customers_company_id_name_index']);
            $table->dropIndex(['customers_company_id_phone_index']);
        });
    }
};
