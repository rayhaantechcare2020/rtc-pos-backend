<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class UpdatePaymentsTable extends Migration
{
    public function up()
    {
        Schema::table('payments', function (Blueprint $table) {
            // Check and add missing columns
            if (!Schema::hasColumn('payments', 'user_id')) {
                $table->foreignId('user_id')->nullable()->after('company_id')->constrained('users')->onDelete('set null');
            }
            
            if (!Schema::hasColumn('payments', 'customer_id')) {
                $table->foreignId('customer_id')->nullable()->after('user_id')->constrained('customers')->onDelete('set null');
            }

            
            if (!Schema::hasColumn('payments', 'reference')) {
                $table->string('reference')->nullable()->after('amount');
            }
            
            if (!Schema::hasColumn('payments', 'bank_id')) {
                $table->foreignId('bank_id')->nullable()->after('reference')->constrained('banks')->onDelete('set null');
            }
            
            if (!Schema::hasColumn('payments', 'notes')) {
                $table->text('notes')->nullable()->after('status');
            }
        });
    }

    public function down()
    {
        Schema::table('payments', function (Blueprint $table) {
            $table->dropForeign(['user_id']);
            $table->dropForeign(['customer_id']);
            $table->dropForeign(['bank_id']);
            $table->dropColumn(['user_id', 'customer_id', 'reference', 'bank_id', 'notes']);
        });
    }
}