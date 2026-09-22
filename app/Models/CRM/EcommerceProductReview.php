<?php

namespace App\Models\CRM;

use App\Models\Ecommerce\EcommerceOrder;
use App\Models\Ecommerce\EcommerceOrderItem;

use App\Models\Core\User;
use App\Models\ProductCatalog\Product;
use App\Models\Store\Store;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Storage;

class EcommerceProductReview extends Model
{
    protected $table = 'ecommerce_product_reviews';

    protected $fillable = [
        'order_id',
        'order_item_id',
        'product_id',
        'store_id',
        'user_id',
        'rating',
        'review_text',
        'attachment_path',
        'status',
    ];

    protected $appends = ['attachment_url'];

    protected $casts = [
        'rating' => 'integer',
    ];

    public function getAttachmentUrlAttribute(): ?string
    {
        return $this->attachment_path
            ? Storage::disk('public')->url($this->attachment_path)
            : null;
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(EcommerceOrder::class, 'order_id');
    }

    public function orderItem(): BelongsTo
    {
        return $this->belongsTo(EcommerceOrderItem::class, 'order_item_id');
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class, 'product_id');
    }

    public function store(): BelongsTo
    {
        return $this->belongsTo(Store::class, 'store_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }
}
