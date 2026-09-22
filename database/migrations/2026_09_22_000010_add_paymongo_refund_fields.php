<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('finance_refunds', function (Blueprint $table) {
            $table->string('payout_provider', 30)->nullable()->after('payout_reference');
            $table->string('paymongo_refund_id')->nullable()->after('payout_provider');
        });
    }

    public function down(): void
    {
        Schema::table('finance_refunds', function (Blueprint $table) {
            $table->dropColumn(['payout_provider', 'paymongo_refund_id']);
        });
    }
};
