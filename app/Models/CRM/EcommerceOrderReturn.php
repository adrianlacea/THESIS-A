<?php

namespace App\Models\CRM;

use App\Models\Ecommerce\EcommerceOrder;
use App\Models\Ecommerce\EcommerceOrderItem;

use App\Models\Core\User;
use App\Models\Logistics\ReturnPickup;
use App\Models\Finance\FinanceRefund;
use App\Models\Store\Store;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

class EcommerceOrderReturn extends Model
{
    protected $table = 'ecommerce_order_returns';

    protected $fillable = [
        'return_number',
        'order_id',
        'order_item_id',
        'store_id',
        'user_id',
        'requested_quantity',
        'reason',
        'details',
        'evidence_urls',
        'status',
        'return_type',
        'product_condition',
        'inventory_disposition',
        'received_quantity',
        'inspected_by',
        'inspected_at',
        'inspection_notes',
        'resolved_at',
        'reviewed_by',
        'reviewed_at',
        'review_notes',
    ];

    protected $casts = [
        'requested_quantity' => 'integer',
        'reviewed_at' => 'datetime',
        'received_quantity' => 'integer',
        'inspected_at' => 'datetime',
        'resolved_at' => 'datetime',
        'evidence_urls' => 'array',
    ];

    protected static function booted(): void
    {
        static::creating(function (self $return): void {
            if (filled($return->return_number)) {
                return;
            }

            $timestamp = now();
            do {
                $number = 'RET-' . $timestamp->format('YmdHis');
                $timestamp->addSecond();
            } while (static::query()->where('return_number', $number)->exists());

            $return->return_number = $number;
        });
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(EcommerceOrder::class, 'order_id');
    }

    public function orderItem(): BelongsTo
    {
        return $this->belongsTo(EcommerceOrderItem::class, 'order_item_id');
    }

    public function store(): BelongsTo
    {
        return $this->belongsTo(Store::class, 'store_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }

    public function inspector(): BelongsTo
    {
        return $this->belongsTo(User::class, 'inspected_by');
    }

    public function pickup(): HasOne
    {
        return $this->hasOne(ReturnPickup::class, 'return_id');
    }

    public function investigationTicket(): HasOne
    {
        return $this->hasOne(ReturnInvestigationTicket::class, 'return_id');
    }

    public function financeRefund(): HasOne
    {
        return $this->hasOne(FinanceRefund::class, 'order_id')
            ->where('order_type', 'ecommerce_return');
    }
}
