<?php

namespace App\Http\Controllers\Api\CRM;

use App\Http\Controllers\Controller;
use App\Models\CRM\EcommerceProductReview;
use App\Models\CRM\SalesReview;
use App\Models\Store\Branch;
use Illuminate\Http\Request;

class ReviewController extends Controller
{
    public function index(Request $request)
    {
        $user = $request->user();
        $storeId = $user?->store_id;

        if (!$storeId && $request->filled('store_id')) {
            $storeId = (int) $request->integer('store_id');
        }

        if (!$storeId && $user?->branch_id) {
            $storeId = Branch::query()->whereKey($user->branch_id)->value('store_id');
        }

        if (!$storeId) {
            return response()->json(['data' => []]);
        }

        $this->syncEcommerceReviewsToSales((int) $storeId);

        $query = SalesReview::query()
            ->with(['product:id,product_name,sku', 'replier:id,fname,lname'])
            ->where('store_id', $storeId)
            ->orderByDesc('created_at');

        if ($request->filled('status')) {
            $query->where('status', $request->string('status'));
        }

        if ($request->filled('rating')) {
            $query->where('rating', (int) $request->integer('rating'));
        }

        if ($request->filled('search')) {
            $term = $request->string('search');
            $query->where(function ($q) use ($term) {
                $q->where('customer_name', 'like', "%{$term}%")
                    ->orWhere('order_id', 'like', "%{$term}%")
                    ->orWhere('message', 'like', "%{$term}%");
            });
        }

        $perPage = max(10, min((int) $request->integer('per_page', 20), 100));
        $reviews = $query->paginate($perPage);

        $base = SalesReview::query()->where('store_id', $storeId);
        $summary = [
            'total_reviews' => (int) (clone $base)->count(),
            'pending_reviews' => (int) (clone $base)->where('status', 'pending')->count(),
            'replied_reviews' => (int) (clone $base)->where('status', 'replied')->count(),
            'average_rating' => round((float) ((clone $base)->avg('rating') ?? 0), 2),
            'ratings_breakdown' => [
                5 => (int) (clone $base)->where('rating', 5)->count(),
                4 => (int) (clone $base)->where('rating', 4)->count(),
                3 => (int) (clone $base)->where('rating', 3)->count(),
                2 => (int) (clone $base)->where('rating', 2)->count(),
                1 => (int) (clone $base)->where('rating', 1)->count(),
            ],
        ];

        return response()->json([
            'data' => $reviews,
            'summary' => $summary,
        ]);
    }

    private function syncEcommerceReviewsToSales(int $storeId): void
    {
        try {
            EcommerceProductReview::query()
                ->with(['user:id,fname,lname,email,phone_number', 'product:id,product_name,sku'])
                ->where('store_id', $storeId)
                ->where('status', 'published')
                ->orderBy('id')
                ->chunkById(200, function ($reviews) use ($storeId) {
                    $reviews->each(function (EcommerceProductReview $review) use ($storeId) {
                    $customerName = trim(($review->user?->fname ?? '') . ' ' . ($review->user?->lname ?? ''));
                    $customerContact = (string) ($review->user?->phone_number ?: $review->user?->email ?: '');

                    $salesReview = SalesReview::query()
                        ->where('store_id', $storeId)
                        ->whereIn('order_type', ['ecommerce', 'ecommerce_order'])
                        ->where('order_id', (int) $review->order_id)
                        ->where('product_id', (int) $review->product_id)
                        ->where('created_by', (int) $review->user_id)
                        ->first();

                    if (! $salesReview) {
                        $salesReview = new SalesReview([
                            'order_type' => 'ecommerce_order',
                            'order_id' => (int) $review->order_id,
                            'product_id' => (int) $review->product_id,
                            'created_by' => (int) $review->user_id,
                        ]);
                    }

                    $existingStatus = (string) ($salesReview->status ?? 'pending');

                    $salesReview->store_id = $storeId;
                    $salesReview->customer_name = $customerName ?: ($salesReview->customer_name ?: null);
                    $salesReview->customer_contact = $customerContact ?: ($salesReview->customer_contact ?: null);
                    $salesReview->rating = (int) $review->rating;
                    $salesReview->message = $review->review_text;

                    if ($existingStatus !== 'replied') {
                        $salesReview->status = 'pending';
                    }

                    if (!$salesReview->exists) {
                        $salesReview->created_at = $review->created_at;
                        $salesReview->updated_at = $review->updated_at;
                    }

                    $salesReview->save();
                    });
                });
        } catch (\Throwable $exception) {
            report($exception);
        }
    }

    public function show(SalesReview $review)
    {
        return response()->json([
            'data' => $review,
        ]);
    }

    public function reply(Request $request, SalesReview $review)
    {
        $request->validate([
            'reply' => ['required', 'string', 'max:2000'],
        ]);

        $user = $request->user();

        $review->update([
            'reply' => $request->string('reply'),
            'replied_by' => $user?->id,
            'replied_at' => now(),
            'status' => 'replied',
        ]);

        if (! $review->created_by && in_array((string) $review->order_type, ['ecommerce', 'ecommerce_order'], true)) {
            $customerId = EcommerceProductReview::query()
                ->where('store_id', (int) $review->store_id)
                ->where('order_id', (int) $review->order_id)
                ->where('product_id', (int) $review->product_id)
                ->value('user_id');

            if ($customerId) {
                $review->created_by = (int) $customerId;
                $review->save();
            }
        }

        if ($review->created_by) {
            $productName = $review->product?->product_name ?: 'your purchased item';
            $this->notify((int) $review->created_by, [
                'store_id' => (int) $review->store_id,
                'branch_id' => $review->branch_id ? (int) $review->branch_id : null,
                'module' => 'ecommerce',
                'entity_type' => 'product_review',
                'entity_id' => (int) $review->id,
                'action' => 'store_replied',
                'title' => 'Store replied to your review',
                'message' => "The store replied to your review for {$productName}: \"" . (string) $review->reply . '"',
                'severity' => 'info',
                'link' => '/products/' . (int) $review->product_id . '?tab=reviews',
                'data' => [
                    'product_id' => (int) $review->product_id,
                    'reply' => (string) $review->reply,
                ],
            ]);
        }

        return response()->json([
            'message' => 'Reply saved.',
            'data' => $review,
        ]);
    }
}
