<?php

namespace App\Models\Finance;

use App\Models\Core\User;
use App\Models\CRM\EcommerceOrderReturn;
use App\Models\Store\Branch;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class FinanceRefund extends Model
{
    protected $table = 'finance_refunds';

    protected $fillable = [
        'store_id', 'branch_id', 'order_type', 'order_id', 'order_number',
        'customer_name', 'reason', 'amount', 'status', 'requested_by',
        'refund_method', 'refund_account_name', 'refund_account_number',
        'payout_reference', 'payout_provider', 'paymongo_refund_id', 'processed_by', 'processed_at', 'sent_by', 'sent_at', 'notes',
    ];

    protected $casts = ['amount' => 'decimal:2', 'processed_at' => 'datetime', 'sent_at' => 'datetime'];

    public function branch(): BelongsTo { return $this->belongsTo(Branch::class); }
    public function requester(): BelongsTo { return $this->belongsTo(User::class, 'requested_by'); }
    public function processor(): BelongsTo { return $this->belongsTo(User::class, 'processed_by'); }
    public function sender(): BelongsTo { return $this->belongsTo(User::class, 'sent_by'); }
    public function ecommerceReturn(): BelongsTo { return $this->belongsTo(EcommerceOrderReturn::class, 'order_id'); }
}
