<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('finance_refunds', function (Blueprint $table) {
            $table->string('refund_method', 30)->nullable()->after('amount');
            $table->string('refund_account_name')->nullable()->after('refund_method');
            $table->string('refund_account_number', 100)->nullable()->after('refund_account_name');
        });

        DB::statement("ALTER TABLE ecommerce_orders MODIFY status ENUM(
            'pending','processing','ready_for_dispatch','packed','shipped','in_transit','out_for_delivery',
            'delivered','pending_cancellation','cancelled','return_pending','return_approved',
            'return_received','refund_pending','refunded','replaced'
        ) DEFAULT 'pending'");

        DB::table('ecommerce_order_returns')->orderBy('id')->get(['order_id', 'status'])->each(function ($return): void {
            $status = match ((string) $return->status) {
                'pending_verification' => 'return_pending',
                'approved' => 'return_approved',
                'received' => 'return_received',
                'refund_pending' => 'refund_pending',
                'refunded' => 'refunded',
                'replaced' => 'replaced',
                default => null,
            };
            if ($status) DB::table('ecommerce_orders')->where('id', $return->order_id)->update(['status' => $status]);
        });
    }

    public function down(): void
    {
        DB::table('ecommerce_orders')->whereIn('status', [
            'return_pending', 'return_approved', 'return_received', 'refund_pending', 'refunded', 'replaced',
        ])->update(['status' => 'delivered']);

        DB::statement("ALTER TABLE ecommerce_orders MODIFY status ENUM(
            'pending','processing','ready_for_dispatch','packed','shipped','in_transit','out_for_delivery',
            'delivered','pending_cancellation','cancelled'
        ) DEFAULT 'pending'");

        Schema::table('finance_refunds', function (Blueprint $table) {
            $table->dropColumn(['refund_method', 'refund_account_name', 'refund_account_number']);
        });
    }
};
