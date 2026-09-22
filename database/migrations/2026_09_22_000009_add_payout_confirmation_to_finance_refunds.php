<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('finance_refunds', function (Blueprint $table) {
            $table->string('payout_reference')->nullable()->after('refund_account_number');
            $table->foreignId('sent_by')->nullable()->after('processed_at')->constrained('users')->nullOnDelete();
            $table->timestamp('sent_at')->nullable()->after('sent_by');
        });
    }

    public function down(): void
    {
        Schema::table('finance_refunds', function (Blueprint $table) {
            $table->dropConstrainedForeignId('sent_by');
            $table->dropColumn(['payout_reference', 'sent_at']);
        });
    }
};
