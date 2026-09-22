<?php

namespace App\Http\Controllers\Api\Core;

use App\Http\Controllers\Controller;
use App\Models\CRM\EcommerceOrderReturn;
use App\Models\CRM\SalesReview;
use App\Models\Core\SystemNotification;
use App\Models\Ecommerce\EcommerceOrder;
use App\Models\Logistics\ReturnPickup;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;

class SystemNotificationController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        try {
            $user = auth()->user();
            $perPage = (int) $request->get('per_page', 15);
            $sortBy = $request->get('sort_by', 'created_at');
            $sortOrder = $request->get('sort_order', 'desc');
            $onlyUnread = $request->boolean('unread_only', false);

            $query = SystemNotification::where('user_id', $user->id);

            if ($request->filled('module')) {
                $query->where('module', $request->module);
            }

            if ($request->filled('branch_id')) {
                $query->where('branch_id', $request->branch_id);
            }

            if ($request->filled('entity_type')) {
                $query->where('entity_type', $request->entity_type);
            }

            if ($onlyUnread) {
                $query->where('is_read', false);
            }

            $notifications = $query
                ->orderBy($sortBy, $sortOrder)
                ->paginate($perPage);

            if ($request->get('module') === 'ecommerce') {
                $notifications->setCollection(
                    $this->enrichEcommerceNotifications($notifications->getCollection(), (int) $user->id)
                );
            }

            return response()->json([
                'success' => true,
                'data' => $notifications->items(),
                'meta' => [
                    'total' => $notifications->total(),
                    'count' => count($notifications->items()),
                    'per_page' => $notifications->perPage(),
                    'current_page' => $notifications->currentPage(),
                    'last_page' => $notifications->lastPage(),
                    'unread_count' => SystemNotification::where('user_id', $user->id)->where('is_read', false)->count(),
                ],
            ], 200);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to retrieve notifications: ' . $e->getMessage(),
            ], 500);
        }
    }

    public function show(string $id): JsonResponse
    {
        try {
            $user = auth()->user();
            $query = SystemNotification::where('id', $id)->where('user_id', $user->id);
            $notification = $query->firstOrFail();

            return response()->json([
                'success' => true,
                'data' => $notification,
            ], 200);
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException) {
            return response()->json([
                'success' => false,
                'message' => 'Notification not found',
            ], 404);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to retrieve notification: ' . $e->getMessage(),
            ], 500);
        }
    }

    public function markAsRead(string $id): JsonResponse
    {
        try {
            $user = auth()->user();
            $query = SystemNotification::where('id', $id)->where('user_id', $user->id);
            $notification = $query->firstOrFail();

            $notification->markAsRead();

            return response()->json([
                'success' => true,
                'message' => 'Notification marked as read',
                'data' => $notification,
            ], 200);
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException) {
            return response()->json([
                'success' => false,
                'message' => 'Notification not found',
            ], 404);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to mark notification as read: ' . $e->getMessage(),
            ], 500);
        }
    }

    public function markAllAsRead(Request $request): JsonResponse
    {
        try {
            $user = auth()->user();
            $query = SystemNotification::where('user_id', $user->id)->where('is_read', false);
            if ($request->filled('module')) {
                $query->where('module', $request->module);
            }
            $updated = $query->update([
                    'is_read' => true,
                    'read_at' => now(),
                ]);

            return response()->json([
                'success' => true,
                'message' => "Marked {$updated} notifications as read",
                'meta' => ['updated_count' => $updated],
            ], 200);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to mark all notifications as read: ' . $e->getMessage(),
            ], 500);
        }
    }

    public function delete(string $id): JsonResponse
    {
        try {
            $user = auth()->user();
            $query = SystemNotification::where('id', $id)->where('user_id', $user->id);
            $notification = $query->firstOrFail();

            $notification->delete();

            return response()->json([
                'success' => true,
                'message' => 'Notification deleted successfully',
            ], 200);
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException) {
            return response()->json([
                'success' => false,
                'message' => 'Notification not found',
            ], 404);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to delete notification: ' . $e->getMessage(),
            ], 500);
        }
    }

    public function batchDelete(Request $request): JsonResponse
    {
        try {
            $user = auth()->user();
            $ids = $request->get('ids', []);

            if (empty($ids)) {
                return response()->json([
                    'success' => false,
                    'message' => 'No notification IDs provided',
                ], 422);
            }

            $query = SystemNotification::where('user_id', $user->id)->whereIn('id', $ids);
            $deleted = $query->delete();

            return response()->json([
                'success' => true,
                'message' => "Deleted {$deleted} notifications",
                'meta' => ['deleted_count' => $deleted],
            ], 200);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to delete notifications: ' . $e->getMessage(),
            ], 500);
        }
    }

    public function getUnread(Request $request): JsonResponse
    {
        try {
            $user = auth()->user();
            $query = SystemNotification::where('user_id', $user->id)->where('is_read', false);
            if ($request->filled('module')) {
                $query->where('module', $request->module);
            }
            $unreadCount = $query->count();

            return response()->json([
                'success' => true,
                'data' => ['unread_count' => $unreadCount],
            ], 200);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to retrieve unread count: ' . $e->getMessage(),
            ], 500);
        }
    }

    private function enrichEcommerceNotifications(Collection $notifications, int $userId): Collection
    {
        $idsFor = fn (string $type) => $notifications
            ->where('entity_type', $type)
            ->pluck('entity_id')
            ->filter()
            ->map(fn ($id) => (int) $id)
            ->unique()
            ->values();

        $returns = EcommerceOrderReturn::query()
            ->with(['orderItem.product.assets'])
            ->where('user_id', $userId)
            ->whereIn('id', $idsFor('ecommerce_order_return'))
            ->get()
            ->keyBy('id');

        $pickups = ReturnPickup::query()
            ->with(['returnRequest.orderItem.product.assets'])
            ->whereIn('id', $idsFor('return_pickup'))
            ->whereHas('returnRequest', fn ($query) => $query->where('user_id', $userId))
            ->get()
            ->keyBy('id');

        $reviews = SalesReview::query()
            ->with(['product.assets'])
            ->where('created_by', $userId)
            ->whereIn('order_type', ['ecommerce', 'ecommerce_order'])
            ->whereIn('id', $idsFor('product_review'))
            ->get()
            ->keyBy('id');

        $orderIds = $idsFor('ecommerce_order')
            ->merge($returns->pluck('order_id'))
            ->merge($pickups->pluck('returnRequest.order_id'))
            ->merge($reviews->pluck('order_id'))
            ->filter()
            ->map(fn ($id) => (int) $id)
            ->unique()
            ->values();

        $orders = EcommerceOrder::query()
            ->with(['store:id,name', 'items.product.assets'])
            ->where('user_id', $userId)
            ->whereIn('id', $orderIds)
            ->get()
            ->keyBy('id');

        return $notifications->map(function (SystemNotification $notification) use ($orders, $returns, $pickups, $reviews) {
            $return = null;
            $review = null;
            $order = null;

            if (is_string($notification->link)) {
                $notification->link = preg_replace('#^/shop/(chats|profile)#', '/$1', $notification->link);
            }

            if ($notification->entity_type === 'ecommerce_order') {
                $order = $orders->get((int) $notification->entity_id);
            } elseif ($notification->entity_type === 'ecommerce_order_return') {
                $return = $returns->get((int) $notification->entity_id);
                $order = $return ? $orders->get((int) $return->order_id) : null;
            } elseif ($notification->entity_type === 'return_pickup') {
                $return = $pickups->get((int) $notification->entity_id)?->returnRequest;
                $order = $return ? $orders->get((int) $return->order_id) : null;
            } elseif ($notification->entity_type === 'product_review') {
                $review = $reviews->get((int) $notification->entity_id);
                $order = $review ? $orders->get((int) $review->order_id) : null;
            }

            if (!$order) {
                return $notification;
            }

            $selectedItem = $return?->orderItem;
            if (!$selectedItem && $review) {
                $selectedItem = $order->items->firstWhere('product_id', (int) $review->product_id);
            }
            $items = $selectedItem ? collect([$selectedItem]) : $order->items;
            $itemData = $items->map(function ($item) use ($return) {
                $asset = $item->product?->assets
                    ?->filter(fn ($asset) => in_array($asset->asset_type, ['Image_Main', 'Image_Gallery', 'Image_360'], true))
                    ->sortByDesc('is_primary')
                    ->sortBy('display_order')
                    ->first();

                return [
                    'id' => (int) $item->id,
                    'product_id' => (int) $item->product_id,
                    'product_name' => (string) ($item->product_name ?: $item->product?->product_name ?: 'Product'),
                    'quantity' => (int) ($return?->requested_quantity ?: $item->quantity),
                    'image_url' => $asset?->url,
                ];
            })->values()->all();

            $notification->data = array_merge((array) $notification->data, [
                'order_id' => (int) $order->id,
                'order_number' => (string) $order->order_number,
                'order_status' => (string) $order->status,
                'store_name' => (string) ($order->store?->name ?? 'Store'),
                'items' => $itemData,
                'return_number' => $return?->return_number,
                'return_type' => $return?->return_type,
            ]);
            $notification->link = $review
                ? '/products/' . (int) $review->product_id . '?tab=reviews'
                : '/orders/' . (int) $order->id;

            return $notification;
        });
    }
}
