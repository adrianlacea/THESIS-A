<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('crm_return_investigation_tickets', function (Blueprint $table): void {
            $table->string('reference_number', 40)->nullable()->unique()->after('id');
        });

        $tickets = \DB::table('crm_return_investigation_tickets')->whereNull('reference_number')->orderBy('id')->get(['id']);
        foreach ($tickets as $ticket) {
            \DB::table('crm_return_investigation_tickets')->where('id', $ticket->id)->update([
                'reference_number' => 'RET-' . now()->format('Ymd') . '-' . str_pad((string) $ticket->id, 6, '0', STR_PAD_LEFT),
            ]);
        }
    }

    public function down(): void
    {
        Schema::table('crm_return_investigation_tickets', function (Blueprint $table): void {
            $table->dropUnique(['reference_number']);
            $table->dropColumn('reference_number');
        });
    }
};
