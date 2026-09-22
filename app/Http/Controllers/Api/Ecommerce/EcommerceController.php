<?php

namespace App\Http\Controllers\Api\Ecommerce;

use App\Http\Controllers\Controller;
use App\Models\Ecommerce\EcommerceCart;
use App\Models\Ecommerce\EcommerceCartItem;
use App\Models\Ecommerce\EcommerceFavorite;
use App\Models\Ecommerce\EcommerceAddressTemplate;
use App\Models\CRM\EcommerceChatMessage;
use App\Models\CRM\EcommerceChatThread;
use App\Models\Ecommerce\EcommerceOrder;
use App\Models\Ecommerce\EcommerceOrderDelivery;
use App\Models\Ecommerce\EcommerceOrderCancellation;
use App\Models\CRM\EcommerceOrderReturn;
use App\Models\CRM\EcommerceProductReview;
use App\Models\Ecommerce\EcommerceStoreFollow;
use App\Models\CRM\EcommerceVoucher;
use App\Models\Admin\ViolationReport;
use App\Models\Customer\Customer;
use App\Models\Finance\FinanceRefund;
use App\Models\Inventory\BranchInventory;
use App\Models\ProductCatalog\Category;
use App\Models\ProductCatalog\Product;
use App\Models\ProductCatalog\ProductVariation;
use App\Models\Store\Store;
use App\Models\Store\Branch;
use App\Models\Store\StoreDeliveryFeeSetting;
use App\Models\Logistics\DeliveryZone;
use App\Models\Logistics\DeliveryZoneRate;
use App\Models\CRM\SalesReview;
use App\Services\Sales\OrderCommissionService;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Laravel\Sanctum\PersonalAccessToken;

class EcommerceController extends Controller
{
    private const VAT_RATE = 12.0;

    public function __construct(
        private readonly OrderCommissionService $commissionService
    ) {
    }

    public function storeDirectory(Request $request)
    {
        $query = Store::query()
            ->select(['id', 'name', 'phone as contact_number', 'city', 'address', 'status', 'created_at'])
            ->whereIn('status', ['active', 'verified'])
            // Hide stores with no active products available in ecommerce.
            ->whereHas('products', function ($productQuery) {
                $productQuery->where('product_type', 'finished_good')
                    ->where('is_active', true)
                    ->whereNull('deleted_at')
                    ->whereHas('inventory', function ($inventoryQuery) {
                        $inventoryQuery->where('quantity_available', '>', 0)
                            ->where('stock_status', '!=', 'out_of_stock');
                    });
            });

        if ($request->filled('search')) {
            $search = trim((string) $request->search);
            $query->where(function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                    ->orWhere('city', 'like', "%{$search}%");
            });
        }

        $sort = (string) $request->input('sort', 'latest');
        if ($sort === 'name') {
            $query->orderBy('name');
        } else {
            $query->orderByDesc('created_at');
        }

        $stores = $query->paginate((int) $request->input('per_page', 12));
        $storeIds = $stores->getCollection()->pluck('id')->values();
        $statsByStore = $this->buildStoreStatsMap($storeIds);
        $followMap = $this->buildFollowMapForUser($storeIds);
        $storeLogos = Branch::query()
            ->whereIn('store_id', $storeIds)
            ->orderByDesc('is_main_branch')
            ->orderBy('id')
            ->get(['store_id', 'logo_path'])
            ->unique('store_id')
            ->pluck('logo_path', 'store_id');

        $stores->getCollection()->transform(function (Store $store) use ($statsByStore, $followMap, $storeLogos) {
            $stats = $statsByStore[$store->id] ?? $this->defaultStoreStats();

            return [
                'id' => $store->id,
                'store_name' => $store->name,
                'contact_person' => null,
                'contact_number' => $store->contact_number,
                'city' => $store->city,
                'address' => $store->address,
                'store_logo' => $this->toAssetUrl($storeLogos->get($store->id)),
                'status' => $store->status,
                'products_count' => $stats['products_count'],
                'categories_count' => $stats['categories_count'],
                'rating_avg' => $stats['rating_avg'],
                'rating_count' => $stats['rating_count'],
                'followers_count' => $stats['followers_count'],
                'badges' => $stats['badges'],
                'is_following' => (bool) ($followMap[$store->id] ?? false),
            ];
        });

        return response()->json([
            'success' => true,
            'data' => $stores,
        ]);
    }

    public function storeProfile(Request $request, int $storeId)
    {
        $store = Store::query()
            ->select(['id', 'name', 'phone as contact_number', 'city', 'address', 'status', 'created_at', 'settings'])
            ->whereIn('status', ['active', 'verified'])
            ->findOrFail($storeId);

        $statsMap = $this->buildStoreStatsMap(collect([$storeId]));
        $stats = $statsMap[$storeId] ?? $this->defaultStoreStats();
        $followMap = $this->buildFollowMapForUser(collect([$storeId]));
        $storeLogo = Branch::query()
            ->where('store_id', $storeId)
            ->orderByDesc('is_main_branch')
            ->orderBy('id')
            ->value('logo_path');

        $categories = Category::query()
            ->select(['categories.id', 'categories.category_name'])
            ->whereHas('products', function ($query) use ($storeId) {
                $query->where('store_id', $storeId)
                    ->where('product_type', 'finished_good')
                    ->where('is_active', true)
                    ->whereNull('deleted_at')
                    ->whereHas('inventory', function ($inventoryQuery) use ($storeId) {
                        $inventoryQuery->where('store_id', $storeId)
                            ->where('quantity_available', '>', 0)
                            ->where('stock_status', '!=', 'out_of_stock');
                    });
            })
            ->orderBy('category_name')
            ->get()
            ->map(fn($category) => ['id' => $category->id, 'name' => $category->category_name])
            ->values();

        $vouchers = EcommerceVoucher::query()
            ->where('store_id', $storeId)
            ->where('is_active', true)
            ->where(function ($query) {
                $query->whereNull('starts_at')->orWhere('starts_at', '<=', now());
            })
            ->where(function ($query) {
                $query->whereNull('ends_at')->orWhere('ends_at', '>=', now());
            })
            ->orderByDesc('created_at')
            ->get()
            ->map(fn($voucher) => [
                'id' => $voucher->id,
                'code' => $voucher->code,
                'discount_type' => $voucher->discount_type,
                'discount_value' => (float) $voucher->discount_value,
                'min_order_amount' => (float) ($voucher->min_order_amount ?? 0),
                'max_discount_amount' => is_null($voucher->max_discount_amount) ? null : (float) $voucher->max_discount_amount,
                'starts_at' => $voucher->starts_at,
                'ends_at' => $voucher->ends_at,
            ])
            ->values();

        $branches = Branch::query()
            ->select(['id', 'store_id', 'name', 'city', 'province', 'address', 'contact_number', 'is_main_branch'])
            ->where('store_id', $storeId)
            ->where('status', 'active')
            ->orderByDesc('is_main_branch')
            ->orderBy('name')
            ->get()
            ->map(fn (Branch $branch) => [
                'id' => $branch->id,
                'name' => $branch->name,
                'city' => $branch->city,
                'province' => $branch->province,
                'address' => $branch->address,
                'contact_number' => $branch->contact_number,
                'is_main_branch' => (bool) $branch->is_main_branch,
            ])
            ->values();

        return response()->json([
            'success' => true,
            'data' => [
                'id' => $store->id,
                'store_name' => $store->name,
                'name' => $store->name,
                'contact_person' => null,
                'contact_number' => $store->contact_number,
                'city' => $store->city,
                'address' => $store->address,
                'store_logo' => $this->toAssetUrl($storeLogo),
                'status' => $store->status,
                'created_at' => $store->created_at,
                'is_following' => (bool) ($followMap[$store->id] ?? false),
                'products_count' => $stats['products_count'],
                'categories_count' => $stats['categories_count'],
                'rating_avg' => $stats['rating_avg'],
                'rating_count' => $stats['rating_count'],
                'followers_count' => $stats['followers_count'],
                'badges' => $stats['badges'],
                'categories' => $categories,
                'vouchers' => $vouchers,
                'branches' => $branches,
            ],
        ]);
    }

    public function storeProducts(Request $request, int $storeId)
    {
        $hasOrderItemsTable = Schema::hasTable('ecommerce_order_items');
        $hasReviewsTable = Schema::hasTable('ecommerce_product_reviews');

        Store::query()
            ->where('id', $storeId)
            ->whereIn('status', ['active', 'verified'])
            ->firstOrFail();

        $query = Product::query()
            ->with(['category:id,category_name', 'assets:id,product_id,file_path,asset_type,is_primary,created_at,display_order'])
            ->where('store_id', $storeId)
            ->where('product_type', 'finished_good')
            ->where('is_active', true)
            ->whereNull('deleted_at')
            ->whereHas('inventory', function ($inventoryQuery) use ($storeId) {
                $inventoryQuery->where('store_id', $storeId)
                    ->where('quantity_available', '>', 0)
                    ->where('stock_status', '!=', 'out_of_stock');
            });

        if ($request->filled('search')) {
            $search = trim((string) $request->search);
            $query->where(function ($q) use ($search) {
                $q->where('product_name', 'like', "%{$search}%")
                    ->orWhere('sku', 'like', "%{$search}%");
            });
        }

        if ($request->filled('category_id')) {
            $query->where('category_id', (int) $request->category_id);
        }

        $sort = (string) $request->input('sort', 'popular');
        if ($sort === 'latest') {
            $query->orderByDesc('created_at');
        } elseif ($sort === 'price_asc') {
            $query->orderByRaw('COALESCE(discounted_price, base_price, 0) ASC');
        } elseif ($sort === 'price_desc') {
            $query->orderByRaw('COALESCE(discounted_price, base_price, 0) DESC');
        } else {
            if ($hasOrderItemsTable) {
                $query->withSum(['items as sold_quantity' => function ($itemQuery) use ($storeId) {
                    $itemQuery->whereHas('order', function ($orderQuery) use ($storeId) {
                        $orderQuery->where('store_id', $storeId)
                            ->whereNotIn('status', ['cancelled', 'canceled']);
                    });
                }], 'quantity')->orderByDesc('sold_quantity')->orderByDesc('created_at');
            } else {
                $query->orderByDesc('created_at');
            }
        }

        $products = $query->paginate((int) ($request->input('per_page', 16)));

        $products->getCollection()->transform(function (Product $product) use ($storeId, $hasReviewsTable) {
            $inventory = BranchInventory::query()
                ->where('store_id', $storeId)
                ->where('product_id', $product->id)
                ->orderByDesc('quantity_available')
                ->first();

            $ratingAvg = 0.0;
            $ratingCount = 0;

            if ($hasReviewsTable) {
                $ratingStats = EcommerceProductReview::query()
                    ->where('store_id', $storeId)
                    ->where('product_id', $product->id)
                    ->where('status', 'published')
                    ->selectRaw('COALESCE(AVG(rating), 0) as avg_rating, COUNT(*) as total_reviews')
                    ->first();

                $ratingAvg = round((float) ($ratingStats?->avg_rating ?? 0), 2);
                $ratingCount = (int) ($ratingStats?->total_reviews ?? 0);
            }

            return [
                'id' => $product->id,
                'sku' => $product->sku,
                'product_name' => $product->product_name,
                'description' => $product->description,
                'category_id' => $product->category_id,
                'category' => $product->category?->category_name,
                'price' => round((float) ($product->discounted_price ?? $product->base_price ?? 0), 2),
                'tax_rate' => self::VAT_RATE,
                // Prefer served asset URL (avoids relying on public /storage symlink in production).
                'image' => $this->selectBestProductImage($product)?->url,
                'quantity_available' => (int) ($inventory?->quantity_available ?? 0),
                'stock_status' => $inventory?->stock_status ?? 'out_of_stock',
                'sold_quantity' => (int) ($product->sold_quantity ?? 0),
                'rating_avg' => $ratingAvg,
                'rating_count' => $ratingCount,
            ];
        });

        $categories = Category::query()
            ->select(['categories.id', 'categories.category_name'])
            ->whereHas('products', function ($categoryProductQuery) use ($storeId) {
                $categoryProductQuery->where('store_id', $storeId)
                    ->where('product_type', 'finished_good')
                    ->where('is_active', true)
                    ->whereNull('deleted_at')
                    ->whereHas('inventory', function ($inventoryQuery) use ($storeId) {
                        $inventoryQuery->where('store_id', $storeId)
                            ->where('quantity_available', '>', 0)
                            ->where('stock_status', '!=', 'out_of_stock');
                    });
            })
            ->orderBy('category_name')
            ->get()
            ->map(fn($category) => ['id' => $category->id, 'name' => $category->category_name])
            ->values();

        return response()->json([
            'success' => true,
            'data' => $products,
            'meta' => [
                'categories' => $categories,
                'sort' => $sort,
            ],
        ]);
    }

    public function followStore(int $storeId)
    {
        if (!Schema::hasTable('ecommerce_store_follows')) {
            return response()->json([
                'success' => false,
                'message' => 'Store follow feature is not ready. Please run latest migrations.',
            ], 503);
        }

        $store = Store::query()->whereIn('status', ['active', 'verified'])->findOrFail($storeId);
        $user = Auth::user();

        $follow = EcommerceStoreFollow::query()->firstOrCreate([
            'store_id' => $store->id,
            'user_id' => $user->id,
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Store followed successfully.',
            'data' => $follow,
        ], 201);
    }

    public function unfollowStore(int $storeId)
    {
        if (!Schema::hasTable('ecommerce_store_follows')) {
            return response()->json([
                'success' => false,
                'message' => 'Store follow feature is not ready. Please run latest migrations.',
            ], 503);
        }

        $store = Store::query()->whereIn('status', ['active', 'verified'])->findOrFail($storeId);
        $user = Auth::user();

        EcommerceStoreFollow::query()
            ->where('store_id', $store->id)
            ->where('user_id', $user->id)
            ->delete();

        return response()->json([
            'success' => true,
            'message' => 'Store unfollowed.',
        ]);
    }

    public function storeReviews(Request $request, int $storeId)
    {
        Store::query()->whereIn('status', ['active', 'verified'])->findOrFail($storeId);

        $reviews = EcommerceProductReview::query()
            ->with(['user:id,fname,lname', 'product:id,product_name'])
            ->where('store_id', $storeId)
            ->where('status', 'published')
            ->orderByDesc('created_at')
            ->paginate((int) $request->input('per_page', 10));

        $reviews->getCollection()->transform(function (EcommerceProductReview $review) {
            return [
                'id' => $review->id,
                'rating' => (int) $review->rating,
                'review_text' => $review->review_text,
                'created_at' => $review->created_at,
                'customer_name' => trim(($review->user?->fname ?? '') . ' ' . ($review->user?->lname ?? '')) ?: 'Customer',
                'product_name' => $review->product?->product_name ?? 'Product',
            ];
        });

        return response()->json([
            'success' => true,
            'data' => $reviews,
        ]);
    }

    public function products(Request $request)
    {
        $storeId = $this->resolveStoreId($request);
        $perPage = min((int) $request->input('per_page', 16), 100); // Limit max per page

        // Build optimized query with eager loading
        $query = Product::query()
            ->select([
                'products.id',
                'products.sku',
                'products.product_name',
                'products.description',
                'products.category_id',
                'products.base_price',
                'products.discounted_price',
                'products.store_id'
            ])
            ->with([
                'category:id,category_name',
                'tags:id,tag_name',
            ])
            ->where('products.store_id', $storeId)
            ->where('products.product_type', 'finished_good')
            ->where('products.is_active', true)
            ->whereNull('products.deleted_at')
            ->whereHas('store', function ($storeQuery) {
                $storeQuery->whereIn('status', ['active', 'verified']);
            });

        // Optimized inventory filter using EXISTS instead of WHERE HAS (faster)
        $query->whereExists(function ($subQuery) use ($storeId) {
            $subQuery->select(DB::raw(1))
                ->from('branch_inventory')
                ->whereColumn('branch_inventory.product_id', 'products.id')
                ->where('branch_inventory.store_id', $storeId)
                ->where('branch_inventory.quantity_available', '>', 0)
                ->where('branch_inventory.stock_status', '!=', 'out_of_stock');
        });

        // Apply filters with optimized conditions
        $this->applyProductFilters($query, $request);

        // Execute pagination first, then prefetch for only the current page.
        $products = $query->paginate($perPage);
        $productIds = $products->getCollection()->pluck('id')->values()->all();

        // Prefetch supplemental data (single queries, page-sized)
        $inventories = $this->getInventoryData($storeId, $productIds);
        $productImages = $this->getProductImages($storeId, $productIds);
        $product3dModels = $this->getProduct3dModels($storeId, $productIds);
        $productRatings = $this->getProductRatingData($storeId, $productIds);

        // Transform results with pre-fetched data
        $products->getCollection()->transform(function (Product $product) use ($storeId, $inventories, $productImages, $product3dModels, $productRatings) {
            $inventory = $inventories[$product->id] ?? null;
            $rating = $productRatings[$product->id] ?? ['rating_avg' => 0.0, 'rating_count' => 0];
            $price = (float) ($product->discounted_price ?? $product->base_price ?? 0);

            return [
                'id' => $product->id,
                'sku' => $product->sku,
                'product_name' => $product->product_name,
                'description' => $product->description,
                'category_id' => $product->category_id,
                'category' => $product->category?->category_name,
                'base_price' => round((float) ($product->base_price ?? 0), 2),
                'discounted_price' => $product->discounted_price !== null ? round((float) $product->discounted_price, 2) : null,
                'price' => round($price, 2),
                'base_price' => round((float) ($product->base_price ?? 0), 2),
                'discounted_price' => $product->discounted_price !== null ? round((float) $product->discounted_price, 2) : null,
                'image' => $this->toAssetUrl($productImages[$product->id] ?? null),
                'has_3d_model' => isset($product3dModels[$product->id]),
                'rating_avg' => $rating['rating_avg'],
                'rating_count' => $rating['rating_count'],
                'quantity_available' => (int) ($inventory['quantity_available'] ?? 0),
                'stock_status' => $inventory['stock_status'] ?? 'out_of_stock',
            ];
        });

        return response()->json([
            'success' => true,
            'data' => $products,
        ]);
    }

    /**
     * Apply filters with optimized queries
     */
    private function applyProductFilters($query, Request $request): void
    {
        // Search filter with indexed columns
        if ($request->filled('search')) {
            $search = trim($request->search);
            $query->where(function ($q) use ($search) {
                $q->where('products.product_name', 'LIKE', "{$search}%") // Prefix match is faster
                    ->orWhere('products.sku', 'LIKE', "{$search}%");
            });
        }

        // Category filter
        if ($request->filled('category_id')) {
            $query->where('products.category_id', $request->integer('category_id'));
        }

        // Price range filter - optimized with direct column comparison
        if ($request->filled('min_price')) {
            $minPrice = (float) $request->min_price;
            $query->where(function ($q) use ($minPrice) {
                $q->where('products.discounted_price', '>=', $minPrice)
                    ->orWhere(function ($subQ) use ($minPrice) {
                        $subQ->whereNull('products.discounted_price')
                            ->where('products.base_price', '>=', $minPrice);
                    });
            });
        }

        if ($request->filled('max_price')) {
            $maxPrice = (float) $request->max_price;
            $query->where(function ($q) use ($maxPrice) {
                $q->where('products.discounted_price', '<=', $maxPrice)
                    ->orWhere(function ($subQ) use ($maxPrice) {
                        $subQ->whereNull('products.discounted_price')
                            ->where('products.base_price', '<=', $maxPrice);
                    });
            });
        }
    }

    /**
     * Get inventory data in a single query with proper indexing
     */
    private function getInventoryData(int $storeId, array $productIds): array
    {
        if (empty($productIds)) {
            return [];
        }

        // DB-portable approach (no window functions): order by qty desc and pick first per product in PHP.
        $inventories = BranchInventory::query()
            ->select(['product_id', 'quantity_available', 'stock_status'])
            ->where('store_id', $storeId)
            ->whereIn('product_id', $productIds)
            ->where('quantity_available', '>', 0)
            ->orderBy('product_id')
            ->orderByDesc('quantity_available')
            ->get();

        $result = [];
        foreach ($inventories as $inventory) {
            if (!array_key_exists($inventory->product_id, $result)) {
                $result[$inventory->product_id] = [
                    'quantity_available' => $inventory->quantity_available,
                    'stock_status' => $inventory->stock_status,
                ];
            }
        }

        return $result;
    }

    /**
     * Get primary product images in a single query
     */
    private function getProductImages(int $storeId, array $productIds): array
    {
        if (empty($productIds)) {
            return [];
        }

        $images = DB::table('product_assets')
            ->select(['product_id', 'file_path'])
            ->where('store_id', $storeId)
            ->whereIn('product_id', $productIds)
            ->whereNull('deleted_at')
            ->whereIn('asset_type', ['Image_Main', 'Image_Gallery', 'Image_360'])
            ->orderBy('is_primary', 'desc') // Primary images first
            ->orderBy('display_order', 'asc')
            ->get()
            ->groupBy('product_id')
            ->map(function ($group) {
                return $group->first()->file_path; // Get the best image
            })
            ->toArray();

        return $images;
    }

    /**
     * Get 3D models in a single query
     */
    private function getProduct3dModels(int $storeId, array $productIds): array
    {
        if (empty($productIds)) {
            return [];
        }

        $models = DB::table('product_assets')
            ->select(['product_id'])
            ->where('store_id', $storeId)
            ->whereIn('product_id', $productIds)
            ->whereNull('deleted_at')
            ->where('asset_type', '3D_Model')
            ->get()
            ->pluck('product_id')
            ->flip()
            ->toArray();

        return $models;
    }

    /**
     * Return published customer-review totals for all products on the current page.
     */
    private function getProductRatingData(int $storeId, array $productIds): array
    {
        if (empty($productIds)) {
            return [];
        }

        return EcommerceProductReview::query()
            ->selectRaw('product_id, COALESCE(AVG(rating), 0) as rating_avg, COUNT(*) as rating_count')
            ->where('store_id', $storeId)
            ->whereIn('product_id', $productIds)
            ->where('status', 'published')
            ->groupBy('product_id')
            ->get()
            ->mapWithKeys(fn (EcommerceProductReview $review) => [
                (int) $review->product_id => [
                    'rating_avg' => round((float) $review->rating_avg, 2),
                    'rating_count' => (int) $review->rating_count,
                ],
            ])
            ->all();
    }


    public function productShow(Request $request, int $id)
    {
        $product = Product::query()
            ->with([
                'category:id,category_name',
                'store:id,name,settings',
                'assignedTags:id,tag_name',
                'assets:id,product_id,file_path,asset_type,is_primary,created_at,updated_at,display_order,model_format,file_name,default_camera_angle_x,default_camera_angle_y,default_zoom_level',
                'variations' => function ($query) {
                    $query->where('is_active', true)
                        ->with([
                            'custom3dModel:id,product_id,file_name,file_path,model_format,updated_at,default_camera_angle_x,default_camera_angle_y,default_zoom_level',
                            'customImage:id,product_id,file_name,file_path,asset_type,is_primary,display_order',
                        ])
                        ->orderBy('variation_name');
                },
            ])
            ->where('id', $id)
            ->where('product_type', 'finished_good')
            ->where('is_active', true)
            ->whereNull('deleted_at')
            ->whereHas('store', function ($storeQuery) {
                $storeQuery->whereIn('status', ['active', 'verified']);
            })
            ->when($request->filled('store_id'), function ($query) use ($request) {
                $query->where('store_id', (int) $request->input('store_id'));
            })
            ->whereHas('inventory', function ($inventoryQuery) {
                $inventoryQuery
                    ->where('quantity_available', '>', 0)
                    ->whereIn('stock_status', ['in_stock', 'low_stock']);
            })
            ->firstOrFail();

        $storeId = (int) $product->store_id;

        $inventory = BranchInventory::query()
            ->where('store_id', $storeId)
            ->where('product_id', $product->id)
            ->where('quantity_available', '>', 0)
            ->whereIn('stock_status', ['in_stock', 'low_stock'])
            ->orderByDesc('quantity_available')
            ->first();

        $price = (float) ($product->discounted_price ?? $product->base_price ?? 0);

        // Variation-level availability across branches (best available row per variation).
        $variationInventories = BranchInventory::query()
            ->select(['variation_id', 'quantity_available', 'stock_status'])
            ->where('store_id', $storeId)
            ->where('product_id', $product->id)
            ->whereNotNull('variation_id')
            ->orderBy('variation_id')
            ->orderByDesc('quantity_available')
            ->get();

        $variationInventoryMap = [];
        foreach ($variationInventories as $row) {
            $variationId = (int) ($row->variation_id ?? 0);
            if ($variationId <= 0) {
                continue;
            }
            if (!array_key_exists($variationId, $variationInventoryMap)) {
                $variationInventoryMap[$variationId] = [
                    'quantity_available' => (int) ($row->quantity_available ?? 0),
                    'stock_status' => (string) ($row->stock_status ?? 'out_of_stock'),
                ];
            }
        }

        $model3d = $this->selectBest3DModel($product);
        $storeLogo = Branch::query()
            ->where('store_id', $storeId)
            ->orderByDesc('is_main_branch')
            ->orderBy('id')
            ->value('logo_path');
        $reviewPerPage = max(1, min((int) $request->input('reviews_per_page', 8), 30));

        $reviewStats = EcommerceProductReview::query()
            ->where('product_id', $product->id)
            ->where('store_id', $storeId)
            ->where('status', 'published')
            ->selectRaw('COALESCE(AVG(rating), 0) as average_rating, COUNT(*) as total_reviews')
            ->first();

        $breakdownRaw = EcommerceProductReview::query()
            ->where('product_id', $product->id)
            ->where('store_id', $storeId)
            ->where('status', 'published')
            ->selectRaw('rating, COUNT(*) as total')
            ->groupBy('rating')
            ->pluck('total', 'rating');

        $reviews = EcommerceProductReview::query()
            ->with('user:id,fname,lname')
            ->where('product_id', $product->id)
            ->where('store_id', $storeId)
            ->where('status', 'published')
            ->latest('created_at')
            ->paginate($reviewPerPage);

        $reviewCollection = $reviews->getCollection();
        $replyMap = SalesReview::query()
            ->where('store_id', $storeId)
            ->whereIn('order_type', ['ecommerce', 'ecommerce_order'])
            ->where('product_id', $product->id)
            ->whereIn('order_id', $reviewCollection->pluck('order_id')->filter()->unique())
            ->whereIn('created_by', $reviewCollection->pluck('user_id')->filter()->unique())
            ->whereNotNull('reply')
            ->get(['order_id', 'product_id', 'created_by', 'reply', 'replied_at'])
            ->keyBy(fn (SalesReview $review) => implode(':', [
                (int) $review->order_id,
                (int) $review->product_id,
                (int) $review->created_by,
            ]));

        $reviewCollection->transform(function (EcommerceProductReview $review) use ($replyMap) {
            $name = trim(($review->user?->fname ?? '') . ' ' . ($review->user?->lname ?? ''));
            $reply = $replyMap->get(implode(':', [
                (int) $review->order_id,
                (int) $review->product_id,
                (int) $review->user_id,
            ]));

            return [
                'id' => $review->id,
                'rating' => (int) $review->rating,
                'review_text' => $review->review_text,
                'attachment_url' => $review->attachment_path
                    ? "/api/ecommerce/reviews/{$review->id}/attachment"
                    : null,
                'store_reply' => $reply?->reply,
                'replied_at' => $reply?->replied_at,
                'customer_name' => $name !== '' ? $name : 'Customer',
                'created_at' => $review->created_at,
            ];
        });

        return response()->json([
            'success' => true,
            'data' => [
                'id' => $product->id,
                'sku' => $product->sku,
                'product_name' => $product->product_name,
                'description' => $product->description,
                'brand' => $product->brand,
                'collection_name' => $product->collection_name,
                'tags' => ($product->assignedTags ?? collect())->map(fn ($tag) => [
                    'id' => (int) $tag->id,
                    'tag_name' => $tag->tag_name,
                ])->values(),
                'store_id' => $product->store_id,
                'store_name' => $product->store?->name,
                'store_logo' => $this->toAssetUrl($storeLogo),
                'category' => $product->category?->category_name,
                'price' => round($price, 2),
                'tax_rate' => self::VAT_RATE,
                'assembly_required' => (bool) ($product->assembly_required ?? false),
                'is_featured' => (bool) ($product->is_featured ?? false),
                'is_new_arrival' => (bool) ($product->is_new_arrival ?? false),
                'is_bestseller' => (bool) ($product->is_bestseller ?? false),
                'dimensions' => [
                    'length_cm' => $product->length_cm !== null ? (float) $product->length_cm : null,
                    'width_cm' => $product->width_cm !== null ? (float) $product->width_cm : null,
                    'height_cm' => $product->height_cm !== null ? (float) $product->height_cm : null,
                    'weight_kg' => $product->weight_kg !== null ? (float) $product->weight_kg : null,
                ],
                'image' => $this->selectBestProductImage($product)?->url,
                'images' => $product->assets
                    ->filter(fn($a) => $this->isImageAsset($a->asset_type) && !empty($a->file_path) && !is_null($a->created_at))
                    ->sortBy([['is_primary', 'desc'], ['display_order', 'asc'], ['created_at', 'desc']])
                    ->map(fn($a) => $a->url)
                    ->values(),
                'model_3d' => $model3d ? [
                    'id' => $model3d->id,
                    'file_name' => $model3d->file_name,
                    'model_format' => strtolower((string) $model3d->model_format),
                    'url' => url("/api/product-catalog/assets/{$model3d->id}/serve").'?v='.($model3d->updated_at?->timestamp ?? time()),
                    'camera_settings' => [
                        'angle_x' => (float) ($model3d->default_camera_angle_x ?? 0),
                        'angle_y' => (float) ($model3d->default_camera_angle_y ?? 15),
                        'zoom' => (float) ($model3d->default_zoom_level ?? 3),
                    ],
                ] : null,
                'variations' => $product->variations->map(function ($variation) use ($price, $variationInventoryMap) {
                    $variationModel = $variation->custom3dModel;
                    $variationImage = $variation->customImage;
                    $variationInventory = $variationInventoryMap[(int) $variation->id] ?? [
                        'quantity_available' => 0,
                        'stock_status' => 'out_of_stock',
                    ];
                    $isSelectable = ((int) $variationInventory['quantity_available']) > 0
                        && ((string) $variationInventory['stock_status']) !== 'out_of_stock';
                    $variationPrice = (float) ($variation->discounted_price
                        ?? $variation->base_price
                        ?? ($price + (float) ($variation->price_adjustment ?? 0)));

                    return [
                        'id' => (int) $variation->id,
                        'variation_name' => $variation->variation_name,
                        'variation_sku' => $variation->variation_sku,
                        'color' => $variation->color,
                        'size' => $variation->size,
                        'material' => $variation->material,
                        'price_adjustment' => (float) ($variation->price_adjustment ?? 0),
                        'base_price' => round((float) ($variation->base_price ?? $variationPrice), 2),
                        'discounted_price' => $variation->discounted_price !== null ? round((float) $variation->discounted_price, 2) : null,
                        'final_price' => round($variationPrice, 2),
                        'quantity_available' => (int) ($variationInventory['quantity_available'] ?? 0),
                        'stock_status' => (string) ($variationInventory['stock_status'] ?? 'out_of_stock'),
                        'is_selectable' => (bool) $isSelectable,
                        'image' => $variationImage?->url,
                        'images' => $variationImage?->url ? [$variationImage->url] : [],
                        'model_3d' => $variationModel ? [
                            'id' => $variationModel->id,
                            'file_name' => $variationModel->file_name,
                            'model_format' => strtolower((string) $variationModel->model_format),
                            'url' => url("/api/product-catalog/assets/{$variationModel->id}/serve").'?v='.($variationModel->updated_at?->timestamp ?? time()),
                            'camera_settings' => [
                                'angle_x' => (float) ($variationModel->default_camera_angle_x ?? 0),
                                'angle_y' => (float) ($variationModel->default_camera_angle_y ?? 15),
                                'zoom' => (float) ($variationModel->default_zoom_level ?? 3),
                            ],
                        ] : null,
                    ];
                })->values(),
                'quantity_available' => (int) ($inventory?->quantity_available ?? 0),
                'stock_status' => $inventory?->stock_status ?? 'out_of_stock',
                'reviews_summary' => [
                    'average_rating' => round((float) ($reviewStats?->average_rating ?? 0), 2),
                    'total_reviews' => (int) ($reviewStats?->total_reviews ?? 0),
                    'breakdown' => collect([5, 4, 3, 2, 1])->map(fn($star) => [
                        'star' => $star,
                        'count' => (int) ($breakdownRaw[$star] ?? 0),
                    ])->values(),
                ],
                'rating_avg' => round((float) ($reviewStats?->average_rating ?? 0), 2),
                'rating_count' => (int) ($reviewStats?->total_reviews ?? 0),
                'reviews' => $reviews,
            ],
        ]);
    }

    public function getCart()
    {
        $user = Auth::user();
        $requestedStoreId = (int) request()->input('store_id');
        if ($requestedStoreId > 0) {
            $cart = $this->getOrCreateCart($requestedStoreId);
        } else {
            $existingCart = EcommerceCart::query()
                ->where('user_id', $user->id)
                ->latest('updated_at')
                ->first();
            $cart = $existingCart ?: $this->getOrCreateCart();
        }
        $cart->load([
            'items.product.store:id,name',
            'items.product.assets' => function ($q) {
                $q->select(['id', 'product_id', 'file_path', 'asset_type', 'is_primary', 'created_at', 'display_order']);
            },
            'items.variation',
        ]);

        $favoriteIds = EcommerceFavorite::query()
            ->where('user_id', $user->id)
            ->pluck('product_id')
            ->map(fn ($id) => (int) $id)
            ->all();
        $favoriteMap = array_fill_keys($favoriteIds, true);

        return response()->json([
            'success' => true,
            'data' => $this->formatCart($cart, $favoriteMap),
        ]);
    }

    public function carts()
    {
        $user = Auth::user();

        $carts = EcommerceCart::query()
            ->with(['store:id,name'])
            ->withCount('items')
            ->where('user_id', $user->id)
            ->orderByDesc('updated_at')
            ->get()
            ->map(fn (EcommerceCart $cart) => [
                'id' => (int) $cart->id,
                'store_id' => (int) $cart->store_id,
                'store_name' => $cart->store?->name ?? 'Store',
                'items_count' => (int) ($cart->items_count ?? 0),
                'updated_at' => $cart->updated_at,
            ])
            ->values();

        return response()->json([
            'success' => true,
            'data' => $carts,
        ]);
    }

    public function favorites(): \Illuminate\Http\JsonResponse
    {
        $user = Auth::user();
        $ids = EcommerceFavorite::query()
            ->where('user_id', $user->id)
            ->latest('id')
            ->pluck('product_id')
            ->map(fn ($id) => (int) $id)
            ->values();

        return response()->json([
            'success' => true,
            'data' => [
                'product_ids' => $ids,
            ],
        ]);
    }

    public function toggleFavorite(Request $request): \Illuminate\Http\JsonResponse
    {
        $user = Auth::user();
        $validated = $request->validate([
            'product_id' => ['required', 'integer', 'exists:products,id'],
        ]);

        $productId = (int) $validated['product_id'];

        $existing = EcommerceFavorite::query()
            ->where('user_id', $user->id)
            ->where('product_id', $productId)
            ->first();

        if ($existing) {
            $existing->delete();
            return response()->json([
                'success' => true,
                'data' => [
                    'product_id' => $productId,
                    'is_favorite' => false,
                ],
            ]);
        }

        EcommerceFavorite::create([
            'user_id' => $user->id,
            'product_id' => $productId,
        ]);

        return response()->json([
            'success' => true,
            'data' => [
                'product_id' => $productId,
                'is_favorite' => true,
            ],
        ]);
    }

    public function addCartItem(Request $request)
    {
        $validated = $request->validate([
            'product_id' => ['required', 'exists:products,id'],
            'variation_id' => ['nullable', 'exists:product_variations,id'],
            'quantity' => ['required', 'integer', 'min:1', 'max:999'],
            'store_id' => ['nullable', 'integer', 'exists:stores,id'],
        ]);

        $product = Product::query()
            ->where('product_type', 'finished_good')
            ->where('is_active', true)
            ->findOrFail($validated['product_id']);
        $storeId = (int) $product->store_id;

        $isStoreVisibleInEcommerce = Store::query()
            ->where('id', $storeId)
            ->whereIn('status', ['active', 'verified'])
            ->exists();

        if (!$isStoreVisibleInEcommerce) {
            return response()->json([
                'success' => false,
                'message' => 'This product is not available in ecommerce.',
            ], 404);
        }
        if (!empty($validated['store_id']) && (int) $validated['store_id'] !== $storeId) {
            return response()->json([
                'success' => false,
                'message' => 'Selected product does not belong to the provided store.',
            ], 422);
        }

        $requiresVariationSelection = ProductVariation::query()
            ->where('product_id', $product->id)
            ->where('is_active', true)
            ->exists();

        if ($requiresVariationSelection && empty($validated['variation_id'])) {
            return response()->json([
                'success' => false,
                'message' => 'Please select a variation before adding this product to cart.',
            ], 422);
        }

        $variation = null;
        if (!empty($validated['variation_id'])) {
            $variation = ProductVariation::query()
                ->where('id', (int) $validated['variation_id'])
                ->where('product_id', $product->id)
                ->where('is_active', true)
                ->first();

            if (!$variation) {
                return response()->json([
                    'success' => false,
                    'message' => 'Selected variation is invalid for this product.',
                ], 422);
            }
        }

        $inventory = BranchInventory::query()
            ->where('store_id', $storeId)
            ->where('product_id', $product->id)
            ->when($variation, fn($q) => $q->where('variation_id', $variation->id))
            ->orderByDesc('quantity_available')
            ->first();

        if (!$inventory || $inventory->quantity_available < $validated['quantity']) {
            return response()->json([
                'success' => false,
                'message' => 'Not enough stock available for this product.',
            ], 422);
        }

        $basePrice = (float) ($product->discounted_price ?? $product->base_price ?? 0);
        $price = $variation
            ? round((float) ($variation->discounted_price
                ?? $variation->base_price
                ?? ($basePrice + (float) ($variation->price_adjustment ?? 0))), 2)
            : $basePrice;
        $variationName = $variation
            ? ($variation->variation_name ?: trim(collect([$variation->color, $variation->size, $variation->material])->filter()->join(' / ')))
            : null;
        $cart = $this->getOrCreateCart($storeId);

        $item = EcommerceCartItem::query()
            ->where('cart_id', $cart->id)
            ->where('product_id', $product->id)
            ->where('variation_id', $variation?->id)
            ->first();

        if ($item) {
            $newQty = $item->quantity + (int) $validated['quantity'];
            if ($newQty > $inventory->quantity_available) {
                return response()->json([
                    'success' => false,
                    'message' => 'Not enough stock available for requested quantity.',
                ], 422);
            }
            $item->update([
                'quantity' => $newQty,
                'unit_price' => $price,
                'tax_rate' => self::VAT_RATE,
                'variation_name' => $variationName,
            ]);
        } else {
            EcommerceCartItem::create([
                'cart_id' => $cart->id,
                'product_id' => $product->id,
                'variation_id' => $variation?->id,
                'variation_name' => $variationName,
                'quantity' => (int) $validated['quantity'],
                'unit_price' => $price,
                'tax_rate' => self::VAT_RATE,
            ]);
        }

        $cart->load([
            'items.product.store:id,name',
            'items.product.assets' => function ($q) {
                $q->select(['id', 'product_id', 'file_path', 'asset_type', 'is_primary', 'created_at', 'display_order']);
            },
            'items.variation',
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Item added to cart.',
            'data' => $this->formatCart($cart),
        ]);
    }

    public function updateCartItem(Request $request, int $itemId)
    {
        $validated = $request->validate([
            'quantity' => ['required', 'integer', 'min:1', 'max:999'],
        ]);

        $user = Auth::user();
        $item = EcommerceCartItem::query()
            ->where('id', $itemId)
            ->whereHas('cart', fn($q) => $q->where('user_id', $user->id))
            ->with('cart')
            ->first();

        if (!$item) {
            return response()->json([
                'success' => false,
                'message' => 'Cart item not found. Please refresh your cart.',
            ], 404);
        }
        $cart = $item->cart;

        $inventory = BranchInventory::query()
            ->where('store_id', $cart->store_id)
            ->where('product_id', $item->product_id)
            ->when($item->variation_id, fn($q) => $q->where('variation_id', $item->variation_id))
            ->orderByDesc('quantity_available')
            ->first();

        if (!$inventory || $inventory->quantity_available < $validated['quantity']) {
            return response()->json([
                'success' => false,
                'message' => 'Not enough stock available for requested quantity.',
            ], 422);
        }

        $item->update(['quantity' => (int) $validated['quantity']]);
        $cart->load([
            'items.product.store:id,name',
            'items.product.assets' => function ($q) {
                $q->select(['id', 'product_id', 'file_path', 'asset_type', 'is_primary', 'created_at', 'display_order']);
            },
            'items.variation',
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Cart item updated.',
            'data' => $this->formatCart($cart),
        ]);
    }

    public function removeCartItem(int $itemId)
    {
        $user = Auth::user();
        $item = EcommerceCartItem::query()
            ->where('id', $itemId)
            ->whereHas('cart', fn($q) => $q->where('user_id', $user->id))
            ->with('cart')
            ->first();

        if (!$item) {
            return response()->json([
                'success' => false,
                'message' => 'Cart item not found. Please refresh your cart.',
            ], 404);
        }

        $cart = $item->cart;
        $item->delete();

        $cart->load([
            'items.product.store:id,name',
            'items.product.assets' => function ($q) {
                $q->select(['id', 'product_id', 'file_path', 'asset_type', 'is_primary', 'created_at', 'display_order']);
            },
            'items.variation',
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Item removed from cart.',
            'data' => $this->formatCart($cart),
        ]);
    }

    public function clearCart()
    {
        $cart = $this->getOrCreateCart();
        EcommerceCartItem::query()->where('cart_id', $cart->id)->delete();

        return response()->json([
            'success' => true,
            'message' => 'Cart cleared.',
            'data' => $this->formatCart($cart->fresh('items.product.store', 'items.product.assets', 'items.variation')),
        ]);
    }

    public function estimateShippingFee(Request $request)
    {
        $validated = $request->validate([
            'shipping_address' => ['nullable', 'string'],
            'item_ids' => ['nullable', 'array'],
            'item_ids.*' => ['integer', 'exists:ecommerce_cart_items,id'],
            'customer_latitude' => ['nullable', 'numeric', 'between:-90,90'],
            'customer_longitude' => ['nullable', 'numeric', 'between:-180,180'],
            'bulk_trip' => ['nullable', 'boolean'],
        ]);

        $user = Auth::user();
        $cart = null;
        $itemsForCheckout = collect();

        if (!empty($validated['item_ids'])) {
            $requestedItemIds = collect($validated['item_ids'])->map(fn($id) => (int) $id)->unique()->values();
            $itemsForCheckout = EcommerceCartItem::query()
                ->whereIn('id', $requestedItemIds)
                ->whereHas('cart', fn($q) => $q->where('user_id', $user->id))
                ->with(['cart', 'product', 'variation'])
                ->get();

            if ($itemsForCheckout->isEmpty()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Selected cart items are no longer available. Please refresh your cart.',
                ], 422);
            }

            $storeIds = $itemsForCheckout->pluck('cart.store_id')->unique()->values();
            if ($storeIds->count() > 1) {
                return response()->json([
                    'success' => false,
                    'message' => 'Selected items must belong to the same store for checkout.',
                ], 422);
            }
            $cart = $itemsForCheckout->first()?->cart;
        }

        if (!$cart) {
            $existingCart = EcommerceCart::query()
                ->where('user_id', $user->id)
                ->latest('updated_at')
                ->first();
            $cart = $existingCart ?: $this->getOrCreateCart();
            $cart->load('items.product', 'items.variation');
            $itemsForCheckout = $cart->items;
        }

        if ($itemsForCheckout->isEmpty()) {
            return response()->json([
                'success' => false,
                'message' => 'Cart is empty.',
            ], 422);
        }

        $customerLatitude = isset($validated['customer_latitude']) ? (float) $validated['customer_latitude'] : null;
        $customerLongitude = isset($validated['customer_longitude']) ? (float) $validated['customer_longitude'] : null;

        if ($customerLatitude === null || $customerLongitude === null) {
            if (!empty($validated['shipping_address'])) {
                [$resolvedLatitude, $resolvedLongitude] = $this->resolveCoordinatesFromAddress((string) $validated['shipping_address']);
                $customerLatitude = $customerLatitude ?? $resolvedLatitude;
                $customerLongitude = $customerLongitude ?? $resolvedLongitude;
            }
        }

        $fulfillmentBranch = $this->resolveFulfillmentBranch(
            (int) $cart->store_id,
            $itemsForCheckout,
            $customerLatitude,
            $customerLongitude
        );

        if (!$fulfillmentBranch) {
            return response()->json([
                'success' => false,
                'message' => 'No branch can fully fulfill this order right now.',
            ], 422);
        }

        $totalWeight = 0.0;
        foreach ($itemsForCheckout as $item) {
            $itemWeight = (float) ($item->product?->weight_kg ?? 0);
            $totalWeight += $itemWeight * (int) $item->quantity;
        }

        $bulkTripRequested = (bool) ($validated['bulk_trip'] ?? false);
        $bulkTrip = $bulkTripRequested;
        $bulkDiscountRate = $bulkTrip ? $this->resolveBulkTripDiscountRate($cart->store_id) : 0.0;

        if ($customerLatitude === null || $customerLongitude === null) {
            return response()->json([
                'success' => false,
                'message' => 'Unable to determine customer coordinates for delivery rate lookup.',
            ], 422);
        }

        $originLatitude = is_numeric($fulfillmentBranch->latitude) ? (float) $fulfillmentBranch->latitude : null;
        $originLongitude = is_numeric($fulfillmentBranch->longitude) ? (float) $fulfillmentBranch->longitude : null;

        if ($originLatitude === null || $originLongitude === null) {
            return response()->json([
                'success' => false,
                'message' => 'Fulfillment branch does not have valid coordinates for delivery rate lookup.',
            ], 422);
        }

        $distanceKm = $this->haversineKm(
            $originLatitude,
            $originLongitude,
            (float) $customerLatitude,
            (float) $customerLongitude
        );

        $subtotal = 0.0;
        foreach ($itemsForCheckout as $item) {
            $subtotal += (float) $item->unit_price * (int) $item->quantity;
        }
        $fallback = $this->computeStoreDeliveryFeeFallback($cart->store_id, $subtotal, $distanceKm, $totalWeight);
        if (!($fallback['delivery_available'] ?? true)) {
            return response()->json([
                'success' => false,
                'message' => 'The delivery address is outside this store\'s maximum delivery distance.',
            ], 422);
        }
        $discountAmount = $bulkTrip ? round(((float) $fallback['shipping_fee']) * $bulkDiscountRate, 2) : 0.0;
        $finalFee = round(((float) $fallback['shipping_fee']) - $discountAmount, 2);

        return response()->json([
            'success' => true,
            'data' => [
                'shipping_fee' => (float) $finalFee,
                'distance_km' => round($distanceKm, 2),
                'bulk_trip_allowed' => true,
                'fallback_used' => true,
                'fallback_reason' => 'Using store delivery fee settings.',
                'breakdown' => [
                    'base_fee' => (float) ($fallback['base_fee'] ?? 0),
                    'distance_fee' => (float) ($fallback['distance_fee'] ?? 0),
                    'weight_fee' => (float) ($fallback['weight_fee'] ?? 0),
                    'distance_km' => round($distanceKm, 2),
                    'weight_kg' => round($totalWeight, 2),
                    'bulk_trip' => $bulkTrip,
                    'bulk_discount_rate' => $bulkTrip ? $bulkDiscountRate : 0.0,
                    'bulk_discount_amount' => $discountAmount,
                    'minimum_applied' => (bool) ($fallback['minimum_applied'] ?? false),
                ],
            ],
        ]);
    }

    public function checkout(Request $request)
    {
        $validated = $request->validate([
            'shipping_name' => ['required', 'string', 'max:120'],
            'shipping_phone' => ['nullable', 'string', 'max:50'],
            'shipping_email' => ['nullable', 'email', 'max:120'],
            'shipping_address' => ['required', 'string'],
            'payment_method' => ['required', Rule::in(['cod', 'bank_transfer', 'card', 'e_wallet'])],
            'shipping_fee' => ['nullable', 'numeric', 'min:0'],
            'discount_amount' => ['nullable', 'numeric', 'min:0'],
            'notes' => ['nullable', 'string'],
            'item_ids' => ['nullable', 'array'],
            'item_ids.*' => ['integer', 'exists:ecommerce_cart_items,id'],
            'voucher_code' => ['nullable', 'string', 'max:40'],
            'customer_latitude' => ['nullable', 'numeric', 'between:-90,90'],
            'customer_longitude' => ['nullable', 'numeric', 'between:-180,180'],
            'bulk_trip' => ['nullable', 'boolean'],
        ]);

        $user = Auth::user();
        $cart = null;
        $itemsForCheckout = collect();

        if (!empty($validated['item_ids'])) {
            $requestedItemIds = collect($validated['item_ids'])->map(fn($id) => (int) $id)->unique()->values();
            $itemsForCheckout = EcommerceCartItem::query()
                ->whereIn('id', $requestedItemIds)
                ->whereHas('cart', fn($q) => $q->where('user_id', $user->id))
                ->with(['cart', 'product', 'variation'])
                ->get();

            if ($itemsForCheckout->count() !== $requestedItemIds->count()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Some selected cart items are invalid.',
                ], 422);
            }

            $storeIds = $itemsForCheckout->pluck('cart.store_id')->unique()->values();
            if ($storeIds->count() > 1) {
                return response()->json([
                    'success' => false,
                    'message' => 'Selected items must belong to the same store for checkout.',
                ], 422);
            }
            $cart = $itemsForCheckout->first()?->cart;
        }

        if (!$cart) {
            $existingCart = EcommerceCart::query()
                ->where('user_id', $user->id)
                ->latest('updated_at')
                ->first();
            $cart = $existingCart ?: $this->getOrCreateCart();
            $cart->load('items.product', 'items.variation');
            $itemsForCheckout = $cart->items;
        }

        if ($itemsForCheckout->isEmpty()) {
            return response()->json([
                'success' => false,
                'message' => 'Cart is empty.',
            ], 422);
        }

        $invalidVariationItem = $itemsForCheckout->first(function ($item) {
            if (!is_null($item->variation_id)) {
                return false;
            }

            return ProductVariation::query()
                ->where('product_id', (int) $item->product_id)
                ->where('is_active', true)
                ->exists();
        });

        if ($invalidVariationItem) {
            return response()->json([
                'success' => false,
                'message' => 'Please select a variation for all products that have variations before checkout.',
            ], 422);
        }

        $shippingFee = (float) ($validated['shipping_fee'] ?? 0);
        $previewSubtotal = 0;
        foreach ($itemsForCheckout as $item) {
            $previewSubtotal += (float) $item->unit_price * (int) $item->quantity;
        }

        $voucherDiscount = 0.0;
        $appliedVoucherCode = null;
        if (!empty($validated['voucher_code'])) {
            $voucherResult = $this->validateVoucherCode(
                (string) $validated['voucher_code'],
                (float) $previewSubtotal + $shippingFee
            );
            $voucherDiscount = (float) $voucherResult['discount_amount'];
            $appliedVoucherCode = (string) $voucherResult['voucher']['code'];
        }

        $customerLatitude = isset($validated['customer_latitude']) ? (float) $validated['customer_latitude'] : null;
        $customerLongitude = isset($validated['customer_longitude']) ? (float) $validated['customer_longitude'] : null;

        if ($customerLatitude === null || $customerLongitude === null) {
            [$resolvedLatitude, $resolvedLongitude] = $this->resolveCoordinatesFromAddress((string) $validated['shipping_address']);
            $customerLatitude = $customerLatitude ?? $resolvedLatitude;
            $customerLongitude = $customerLongitude ?? $resolvedLongitude;
        }

        $fulfillmentBranch = $this->resolveFulfillmentBranch(
            (int) $cart->store_id,
            $itemsForCheckout,
            $customerLatitude,
            $customerLongitude
        );

        if (!$fulfillmentBranch) {
            return response()->json([
                'success' => false,
                'message' => 'No branch can fully fulfill this order right now.',
            ], 422);
        }

        // Compute shipping fee from delivery zones/rates based on branch->customer distance and order weight.
        $totalWeight = 0.0;
        foreach ($itemsForCheckout as $item) {
            $itemWeight = (float) ($item->product?->weight_kg ?? 0);
            $totalWeight += $itemWeight * (int) $item->quantity;
        }

        // Allow fallback to a provided shipping_fee if coordinates or branch coords are missing.
        $providedShippingFee = array_key_exists('shipping_fee', $validated) ? (float) $validated['shipping_fee'] : null;
        $canLookupRates = true;

        if ($customerLatitude === null || $customerLongitude === null) {
            if (!is_null($providedShippingFee)) {
                $shippingFee = $providedShippingFee;
                $canLookupRates = false;
            } else {
                return response()->json([
                    'success' => false,
                    'message' => 'Unable to determine customer coordinates for delivery rate lookup.',
                ], 422);
            }
        }

        $originLatitude = is_numeric($fulfillmentBranch->latitude) ? (float) $fulfillmentBranch->latitude : null;
        $originLongitude = is_numeric($fulfillmentBranch->longitude) ? (float) $fulfillmentBranch->longitude : null;

        if ($canLookupRates) {
            if ($originLatitude === null || $originLongitude === null) {
                if (!is_null($providedShippingFee)) {
                    $shippingFee = $providedShippingFee;
                    $canLookupRates = false;
                } else {
                    return response()->json([
                        'success' => false,
                        'message' => 'Fulfillment branch does not have valid coordinates for delivery rate lookup.',
                    ], 422);
                }
            }
        }

        if ($canLookupRates) {
            $distanceKm = $this->haversineKm(
                (float) $originLatitude,
                (float) $originLongitude,
                (float) $customerLatitude,
                (float) $customerLongitude
            );

            // Always recalculate on the server when coordinates are available.
            // The client-provided amount is only a fallback when routing data is unavailable.
            $fallback = $this->computeStoreDeliveryFeeFallback($cart->store_id, $previewSubtotal, $distanceKm, $totalWeight);
            if (!($fallback['delivery_available'] ?? true)) {
                return response()->json([
                    'success' => false,
                    'message' => 'The delivery address is outside this store\'s maximum delivery distance.',
                ], 422);
            }
            $shippingFee = (float) $fallback['shipping_fee'];
        }

        $bulkTripRequested = (bool) ($validated['bulk_trip'] ?? false);
        $bulkTrip = $bulkTripRequested;
        if ($bulkTrip) {
            $bulkDiscountRate = $this->resolveBulkTripDiscountRate($cart->store_id);
            $shippingFee = round($shippingFee * (1 - $bulkDiscountRate), 2);
        }

        $order = DB::transaction(function () use ($validated, $cart, $user, $itemsForCheckout, $shippingFee, $voucherDiscount, $appliedVoucherCode, $fulfillmentBranch, $customerLatitude, $customerLongitude) {
            $subtotal = 0;
            $taxAmount = 0;
            $discountAmount = max((float) ($validated['discount_amount'] ?? 0), $voucherDiscount);
            $isPaymongo = in_array($validated['payment_method'], ['card', 'e_wallet'], true);

            $order = EcommerceOrder::create([
                'store_id' => $cart->store_id,
                'assigned_branch_id' => $fulfillmentBranch->id,
                'user_id' => $user->id,
                'pending_cart_id' => $isPaymongo ? $cart->id : null,
                'order_number' => $this->generateOrderNumber(),
                'status' => 'pending',
                'payment_method' => $validated['payment_method'],
                'payment_status' => 'unpaid',
                'shipping_name' => $validated['shipping_name'],
                'shipping_phone' => $validated['shipping_phone'] ?? null,
                'shipping_email' => $validated['shipping_email'] ?? null,
                'shipping_address' => $validated['shipping_address'],
                'customer_latitude' => $customerLatitude,
                'customer_longitude' => $customerLongitude,
                'notes' => trim((string) (($validated['notes'] ?? '') . ($appliedVoucherCode ? " Voucher: {$appliedVoucherCode}" : ''))) ?: null,
                'placed_at' => now(),
            ]);

            EcommerceOrderDelivery::query()->create([
                'order_id' => $order->id,
                'store_id' => $order->store_id,
                'status' => 'pending',
                'notes' => 'Awaiting Sales approval for dispatch.',
                'created_by' => $user->id,
                'updated_by' => $user->id,
            ]);

            // For Online Payment methods, defer order item creation and inventory reservation until payment is confirmed.
            // This prevents "products ordered" being stored/consumed when Online Payment checkout is cancelled/expired.
            if ($isPaymongo) {
                $snapshotItems = $itemsForCheckout->map(function ($item) {
                    return [
                        'cart_item_id' => (int) $item->id,
                        'product_id' => (int) $item->product_id,
                        'variation_id' => $item->variation_id ? (int) $item->variation_id : null,
                        'variation_name' => $item->variation_name ? (string) $item->variation_name : null,
                        'quantity' => (int) $item->quantity,
                        'unit_price' => (float) $item->unit_price,
                        'tax_rate' => self::VAT_RATE,
                    ];
                })->values()->all();

                foreach ($itemsForCheckout as $item) {
                    $subtotal += (float) $item->unit_price * (int) $item->quantity;
                }

                $taxAmount = $this->extractIncludedVat(max(0, $subtotal - $discountAmount));
                $totalAmount = round($subtotal + $shippingFee - $discountAmount, 2);
                $commission = $this->commissionService->calculate(
                    (int) $cart->store_id,
                    $subtotal,
                    $discountAmount,
                    $totalAmount
                );
                $order->update(array_merge([
                    'subtotal' => round($subtotal, 2),
                    'tax_amount' => round($taxAmount, 2),
                    'shipping_fee' => round($shippingFee, 2),
                    'discount_amount' => round($discountAmount, 2),
                    'total_amount' => $totalAmount,
                    'pending_snapshot' => [
                        'items' => $snapshotItems,
                    ],
                ], $commission));

                return $order->fresh();
            }

            foreach ($itemsForCheckout as $item) {
                $inventory = BranchInventory::query()
                    ->where('store_id', $cart->store_id)
                    ->where('branch_id', $fulfillmentBranch->id)
                    ->where('product_id', $item->product_id)
                    ->when($item->variation_id, fn($q) => $q->where('variation_id', $item->variation_id))
                    ->where('quantity_available', '>=', $item->quantity)
                    ->orderByDesc('quantity_available')
                    ->lockForUpdate()
                    ->first();

                if (!$inventory) {
                    throw new \RuntimeException("Insufficient stock for {$item->product?->product_name}");
                }

                $lineSubtotal = (float) $item->unit_price * (int) $item->quantity;
                // Selling prices are VAT-inclusive; VAT is extracted rather
                // than added on top of the displayed customer price.
                $lineTax = $this->extractIncludedVat($lineSubtotal);
                $lineTotal = $lineSubtotal;

                $subtotal += $lineSubtotal;
                $taxAmount += $lineTax;

                $order->items()->create([
                    'product_id' => $item->product_id,
                    'branch_inventory_id' => $inventory->id,
                    'product_name' => $item->variation_name
                        ? (($item->product?->product_name ?? 'Product') . ' - ' . $item->variation_name)
                        : ($item->product?->product_name ?? 'Product'),
                    'sku' => $item->variation?->variation_sku ?? $item->product?->sku,
                    'quantity' => (int) $item->quantity,
                    'unit_price' => (float) $item->unit_price,
                    'tax_rate' => self::VAT_RATE,
                    'line_subtotal' => round($lineSubtotal, 2),
                    'line_tax' => $lineTax,
                    'line_total' => round($lineTotal, 2),
                ]);

                $inventory->quantity_reserved = max(0, (int) $inventory->quantity_reserved - (int) $item->quantity);
                $inventory->quantity_on_hand = max(0, (int) $inventory->quantity_on_hand - (int) $item->quantity);
                $inventory->quantity_available = max(0, (int) $inventory->quantity_available - (int) $item->quantity);
                $inventory->updateStockStatus();
            }

            $taxAmount = $this->extractIncludedVat(max(0, $subtotal - $discountAmount));
            $totalAmount = round($subtotal + $shippingFee - $discountAmount, 2);
            $commission = $this->commissionService->calculate(
                (int) $cart->store_id,
                $subtotal,
                $discountAmount,
                $totalAmount
            );
            $order->update(array_merge([
                'subtotal' => round($subtotal, 2),
                'tax_amount' => round($taxAmount, 2),
                'shipping_fee' => round($shippingFee, 2),
                'discount_amount' => round($discountAmount, 2),
                'total_amount' => $totalAmount,
            ], $commission));

            EcommerceCartItem::query()
                ->where('cart_id', $cart->id)
                ->whereIn('id', $itemsForCheckout->pluck('id')->values())
                ->delete();

            return $order->fresh('items');
        });

        try {
            $customerItems = $order->relationLoaded('items') ? $order->items : collect();
            $this->notify((int) $user->id, [
                'store_id' => (int) $order->store_id,
                'branch_id' => (int) ($order->assigned_branch_id ?? 0) ?: null,
                'module' => 'ecommerce',
                'entity_type' => 'ecommerce_order',
                'entity_id' => (int) $order->id,
                'action' => 'created',
                'title' => 'Order placed successfully',
                'message' => "Your order {$order->order_number} has been placed and is awaiting confirmation.",
                'severity' => 'success',
                'link' => "/orders/{$order->id}",
                'data' => [
                    'order_id' => (int) $order->id,
                    'order_number' => (string) $order->order_number,
                    'items' => $customerItems->map(fn ($item) => [
                        'id' => (int) $item->id,
                        'product_id' => (int) $item->product_id,
                        'product_name' => (string) $item->product_name,
                        'quantity' => (int) $item->quantity,
                    ])->values()->all(),
                ],
            ]);

            $this->notifyUsersByPermissions(
                (int) $order->store_id,
                ['sales.ecommerce-orders.view', 'sales.ecommerce-orders.manage', 'sales.orders.view'],
                [
                    'store_id' => (int) $order->store_id,
                    'branch_id' => (int) ($order->assigned_branch_id ?? 0) ?: null,
                    'module' => 'sales',
                    'entity_type' => 'ecommerce_order',
                    'entity_id' => (int) $order->id,
                    'action' => 'created',
                    'title' => 'New Ecommerce Order Received',
                    'message' => "Ecommerce order {$order->order_number} has been placed.",
                    'severity' => 'info',
                    'link' => "/sales/ecommerce-orders/{$order->id}",
                ],
                [(int) $user->id]
            );
        } catch (\Throwable $exception) {
            report($exception);
        }

        return response()->json([
            'success' => true,
            'message' => 'Order placed successfully.',
            'data' => $order->fresh(['assignedBranch:id,name,branch_code,city,province,latitude,longitude']),
        ]);
    }

    private function resolveCoordinatesFromAddress(string $shippingAddress): array
    {
        $address = trim($shippingAddress);
        if ($address === '') {
            return [null, null];
        }

        try {
            $query = http_build_query([
                'q' => $address,
                'format' => 'jsonv2',
                'limit' => 1,
            ]);

            $context = stream_context_create([
                'http' => [
                    'method' => 'GET',
                    'timeout' => 6,
                    'header' => "Accept: application/json\r\nUser-Agent: FurnitureStoresPlatform/1.0 (checkout-geocoder)\r\n",
                ],
            ]);

            $rawBody = @file_get_contents('https://nominatim.openstreetmap.org/search?' . $query, false, $context);
            if (!is_string($rawBody) || $rawBody === '') {
                return [null, null];
            }

            $payload = json_decode($rawBody, true);
            $first = is_array($payload) ? collect($payload)->first() : null;
            if (!is_array($first) || !isset($first['lat'], $first['lon'])) {
                return [null, null];
            }

            $latitude = is_numeric($first['lat']) ? (float) $first['lat'] : null;
            $longitude = is_numeric($first['lon']) ? (float) $first['lon'] : null;

            return [$latitude, $longitude];
        } catch (\Throwable $exception) {
            return [null, null];
        }
    }

    public function orders(Request $request)
    {
        $user = Auth::user();
        $ordersQuery = EcommerceOrder::query()
            ->with([
                'store:id,name',
                'assignedBranch:id,name,branch_code,city,province,latitude,longitude',
                'delivery',
                'cancellationRequests',
                'items.product.assets',
                'items.product.category',
                'items.returnRequests.financeRefund',
            ])
            ->withCount('items')
            ->where('user_id', $user->id);

        if ($request->filled('store_id')) {
            $ordersQuery->where('store_id', (int) $request->input('store_id'));
        }

        $orders = $ordersQuery
            ->orderByDesc('created_at')
            ->paginate((int) $request->input('per_page', 10));

        $orders->getCollection()->transform(fn(EcommerceOrder $order) => $this->formatOrder($order));

        return response()->json([
            'success' => true,
            'data' => $orders,
        ]);
    }

    public function orderShow(int $id)
    {
        $user = Auth::user();
        $order = EcommerceOrder::query()
            ->with([
                'store:id,name',
                'assignedBranch:id,name,branch_code,city,province,latitude,longitude',
                'cancellationRequests',
                'items.product.assets',
                'items.product.category',
                'items.returnRequests',
                'items.returnRequests.financeRefund',
                'items.returnRequests.investigationTicket.assignees.user:id,fname,lname',
                'items.review',
                'delivery.logs:id,delivery_id,order_id,event_type,status_from,status_to,message,meta,created_by,created_at',
                'delivery.logs.creator:id,fname,lname',
            ])
            ->withCount('items')
            ->where('user_id', $user->id)
            ->findOrFail($id);

        return response()->json([
            'success' => true,
            'data' => $this->formatOrder($order),
        ]);
    }

    public function requestOrderCancellation(Request $request, int $id)
    {
        $validated = $request->validate([
            'reason' => ['required', 'string', 'max:1000'],
            'details' => ['nullable', 'string', 'max:2000'],
        ]);

        $user = Auth::user();
        $order = EcommerceOrder::query()
            ->where('user_id', $user->id)
            ->findOrFail($id);

        if (strtolower((string) $order->status) !== 'pending') {
            return response()->json([
                'success' => false,
                'message' => 'Only pending orders can request cancellation.',
            ], 422);
        }

        $hasActiveRequest = EcommerceOrderCancellation::query()
            ->where('order_id', $order->id)
            ->whereIn('status', ['pending_verification', 'approved'])
            ->exists();

        if ($hasActiveRequest) {
            return response()->json([
                'success' => false,
                'message' => 'A cancellation request already exists for this order.',
            ], 422);
        }

        $requestRecord = EcommerceOrderCancellation::query()->create([
            'order_id' => $order->id,
            'store_id' => $order->store_id,
            'user_id' => $user->id,
            'reason' => $validated['reason'],
            'details' => $validated['details'] ?? null,
            'status' => 'pending_verification',
        ]);

        $order->update(['status' => 'pending_cancellation']);

        return response()->json([
            'success' => true,
            'message' => 'Cancellation request submitted. Store verification is pending.',
            'data' => $requestRecord,
        ], 201);
    }

    public function requestOrderReturn(Request $request, int $itemId)
    {
        $validated = $request->validate([
            'reason' => ['required', 'string', 'max:1000'],
            'details' => ['nullable', 'string', 'max:2000'],
            'requested_quantity' => ['required', 'integer', 'min:1'],
            'evidence_images' => ['required', 'array', 'min:1', 'max:5'],
            'evidence_images.*' => ['required', 'file', 'mimes:jpg,jpeg,png,webp', 'max:4096'],
        ]);

        $user = Auth::user();
        $orderItem = \App\Models\Ecommerce\EcommerceOrderItem::query()
            ->with('order')
            ->whereHas('order', function ($query) use ($user) {
                $query->where('user_id', $user->id);
            })
            ->whereKey($itemId)
            ->first();

        if (!$orderItem) {
            return response()->json([
                'success' => false,
                'message' => 'Order item not found. Please refresh your orders and try again.',
            ], 404);
        }

        $storeIdForStorage = (int) ($orderItem->order?->store_id ?? 0);

        $orderStatus = strtolower((string) $orderItem->order?->status);
        if (!in_array($orderStatus, ['delivered', 'completed'], true)) {
            return response()->json([
                'success' => false,
                'message' => 'Returns are only allowed for delivered orders.',
            ], 422);
        }

        $deliveredAt = $orderItem->order?->delivery?->delivered_at
            ?? $orderItem->order?->updated_at;
        if (!$deliveredAt || $deliveredAt->lt(now()->subDays(7))) {
            return response()->json([
                'success' => false,
                'message' => 'The 7-day return window has ended.',
            ], 422);
        }

        $requestedQuantity = (int) ($validated['requested_quantity'] ?? 1);
        if ($requestedQuantity < 1) {
            return response()->json([
                'success' => false,
                'message' => 'Requested return quantity must be at least 1.',
            ], 422);
        }
        if ($requestedQuantity > (int) $orderItem->quantity) {
            return response()->json([
                'success' => false,
                'message' => 'Requested return quantity exceeds purchased quantity.',
            ], 422);
        }

        $hasActiveReturn = EcommerceOrderReturn::query()
            ->where('order_item_id', $orderItem->id)
            ->whereIn('status', ['pending_verification', 'approved', 'received', 'refund_pending', 'refunded', 'replaced'])
            ->exists();

        if ($hasActiveReturn) {
            return response()->json([
                'success' => false,
                'message' => 'A return request already exists for this item.',
            ], 422);
        }

        $evidenceUrls = [];
        if ($request->hasFile('evidence_images')) {
            foreach ($request->file('evidence_images') as $file) {
                $path = $file->store("ecommerce/returns/{$storeIdForStorage}/orders/{$orderItem->order_id}/users/{$user->id}", 'public');
                $evidenceUrls[] = Storage::disk('public')->url($path);
            }
        }

        $returnRecord = EcommerceOrderReturn::query()->create([
            'order_id' => $orderItem->order_id,
            'order_item_id' => $orderItem->id,
            'store_id' => $orderItem->order->store_id,
            'user_id' => $user->id,
            'requested_quantity' => $requestedQuantity,
            'reason' => $validated['reason'],
            'details' => $validated['details'] ?? null,
            'evidence_urls' => $evidenceUrls,
            'status' => 'pending_verification',
        ]);
        $orderItem->order->update(['status' => 'return_pending']);

        try {
            $this->notifyUsersByPermissions(
                (int) $orderItem->order->store_id,
                ['sales.refunds.view', 'sales.refunds.manage', 'sales.ecommerce-orders.view'],
                [
                    'store_id' => (int) $orderItem->order->store_id,
                    'module' => 'sales',
                    'entity_type' => 'ecommerce_order_return',
                    'entity_id' => (int) $returnRecord->id,
                    'action' => 'requested',
                    'title' => 'New Return Request',
                    'message' => "Order {$orderItem->order->order_number} has a new return request pending verification.",
                    'severity' => 'warn',
                    'link' => "/sales/ecommerce-orders/{$orderItem->order_id}",
                ],
                [(int) $user->id]
            );
        } catch (\Throwable $exception) {
            report($exception);
        }

        return response()->json([
            'success' => true,
            'message' => 'Return request submitted. Store verification is pending.',
            'data' => $returnRecord,
        ], 201);
    }

    public function updateRefundPaymentMethod(Request $request, EcommerceOrderReturn $return): JsonResponse
    {
        abort_unless((int) $return->user_id === (int) $request->user()->id, 403, 'Unauthorized access to return request.');

        $validated = $request->validate([
            'refund_method' => ['required', Rule::in(['gcash', 'card'])],
            'refund_account_name' => ['required', 'string', 'max:255'],
            'refund_account_number' => ['required', 'string', 'max:100'],
        ]);

        if ((string) $return->return_type !== 'refund' || !in_array((string) $return->status, ['approved', 'received', 'refund_pending'], true)) {
            return response()->json(['message' => 'Refund payment details are not available for this return.'], 422);
        }

        $return->loadMissing(['order', 'orderItem']);
        $refund = FinanceRefund::query()->firstOrCreate(
            [
                'store_id' => (int) $return->store_id,
                'order_type' => 'ecommerce_return',
                'order_id' => (int) $return->id,
            ],
            [
                'branch_id' => $return->order?->assigned_branch_id,
                'order_number' => $return->order?->order_number,
                'customer_name' => $return->order?->shipping_name,
                'reason' => 'Approved customer return ' . ($return->return_number ?: ('#' . $return->id)),
                'amount' => round((float) ($return->orderItem?->unit_price ?? 0) * (int) ($return->requested_quantity ?: 1), 2),
                'status' => in_array((string) $return->status, ['received', 'refund_pending'], true)
                    ? 'pending'
                    : 'pending_inspection',
                'requested_by' => $request->user()->id,
                'notes' => 'Customer provided the refund destination from Ecommerce Order Details.',
            ]
        );

        if (!in_array((string) $refund->status, ['pending_inspection', 'pending'], true)) {
            return response()->json(['message' => 'This refund can no longer accept payment detail changes.'], 422);
        }

        $refund->update([
            'refund_method' => $validated['refund_method'],
            'refund_account_name' => trim($validated['refund_account_name']),
            'refund_account_number' => trim($validated['refund_account_number']),
        ]);

        return response()->json(['message' => 'Refund payment method saved.', 'data' => $refund->fresh()]);
    }

    public function reviewAttachment(EcommerceProductReview $review)
    {
        abort_unless($review->status === 'published' && $review->attachment_path, 404);
        abort_unless(Storage::disk('public')->exists($review->attachment_path), 404);

        return Storage::disk('public')->response(
            $review->attachment_path,
            null,
            ['Cache-Control' => 'public, max-age=86400'],
            'inline'
        );
    }

    public function submitItemReview(Request $request, int $itemId)
    {
        $validated = $request->validate([
            'rating' => ['required', 'integer', 'min:1', 'max:5'],
            'review_text' => ['nullable', 'string', 'max:2000'],
            'attachment' => ['nullable', 'image', 'mimes:jpg,jpeg,png,webp', 'max:5120'],
        ]);

        $user = Auth::user();

        $orderItem = \App\Models\Ecommerce\EcommerceOrderItem::query()
            ->with('order')
            ->whereHas('order', function ($query) use ($user) {
                $query->where('user_id', $user->id);
            })
            ->whereKey($itemId)
            ->first();

        if (!$orderItem) {
            return response()->json([
                'success' => false,
                'message' => 'Order item not found. Please refresh your orders and try again.',
            ], 404);
        }

        $orderStatus = strtolower((string) $orderItem->order?->status);
        if (!in_array($orderStatus, ['delivered', 'completed'], true)) {
            return response()->json([
                'success' => false,
                'message' => 'Reviews are only allowed for delivered orders.',
            ], 422);
        }

        $existingReview = EcommerceProductReview::query()
            ->where('order_item_id', $orderItem->id)
            ->where('user_id', $user->id)
            ->first();
        $previousAttachmentPath = $existingReview?->attachment_path;
        $reviewValues = [
            'order_id' => $orderItem->order_id,
            'product_id' => $orderItem->product_id,
            'store_id' => $orderItem->order->store_id,
            'rating' => (int) $validated['rating'],
            'review_text' => $validated['review_text'] ?? null,
            'status' => 'published',
        ];

        if ($request->hasFile('attachment')) {
            $reviewValues['attachment_path'] = $request->file('attachment')->store(
                "ecommerce/reviews/{$orderItem->order->store_id}/{$orderItem->product_id}",
                'public'
            );
        }

        $review = EcommerceProductReview::query()->updateOrCreate(
            [
                'order_item_id' => $orderItem->id,
                'user_id' => $user->id,
            ],
            $reviewValues
        );

        if ($request->hasFile('attachment') && $previousAttachmentPath && $previousAttachmentPath !== $review->attachment_path) {
            Storage::disk('public')->delete($previousAttachmentPath);
        }

        // Keep Sales > Reviews in sync with ecommerce customer reviews.
        $existingSalesReview = SalesReview::query()
            ->where('store_id', (int) $orderItem->order->store_id)
            ->where('order_type', 'ecommerce_order')
            ->where('order_id', (int) $orderItem->order_id)
            ->where('product_id', (int) $orderItem->product_id)
            ->where('created_by', (int) $user->id)
            ->first();

        $hasReply = !empty($existingSalesReview?->reply);
        SalesReview::query()->updateOrCreate(
            [
                'store_id' => (int) $orderItem->order->store_id,
                'order_type' => 'ecommerce_order',
                'order_id' => (int) $orderItem->order_id,
                'product_id' => (int) $orderItem->product_id,
                'created_by' => (int) $user->id,
            ],
            [
                'branch_id' => $orderItem->order->assigned_branch_id ?? null,
                'customer_name' => trim(($user->fname ?? '') . ' ' . ($user->lname ?? '')) ?: ($orderItem->order->shipping_name ?? 'Customer'),
                'customer_contact' => $orderItem->order->shipping_phone ?? ($user->phone_number ?? null),
                'rating' => (int) $validated['rating'],
                'message' => $validated['review_text'] ?? null,
                // preserve replied status if sales already replied
                'status' => $hasReply ? 'replied' : 'pending',
            ]
        );

        $ratingSummary = EcommerceProductReview::query()
            ->where('product_id', (int) $orderItem->product_id)
            ->where('store_id', (int) $orderItem->order->store_id)
            ->where('status', 'published')
            ->selectRaw('COALESCE(AVG(rating), 0) as average_rating, COUNT(*) as total_reviews')
            ->first();

        return response()->json([
            'success' => true,
            'message' => 'Review submitted successfully.',
            'data' => [
                'review' => $review->fresh(),
                'product_rating' => [
                    'product_id' => (int) $orderItem->product_id,
                    'average_rating' => round((float) ($ratingSummary?->average_rating ?? 0), 2),
                    'total_reviews' => (int) ($ratingSummary?->total_reviews ?? 0),
                ],
            ],
        ]);
    }

    public function dssRecommendations(Request $request)
    {
        $storeId = $this->resolveStoreId($request);

        $validated = $request->validate([
            'budget_min' => ['required', 'numeric', 'min:0'],
            'budget_max' => ['required', 'numeric', 'min:0'],
            'category_id' => ['nullable', 'integer', 'exists:categories,id'],
            'length_cm' => ['nullable', 'numeric', 'min:0'],
            'width_cm' => ['nullable', 'numeric', 'min:0'],
            'height_cm' => ['nullable', 'numeric', 'min:0'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:50'],
        ]);

        $budgetMin = (float) min($validated['budget_min'], $validated['budget_max']);
        $budgetMax = (float) max($validated['budget_min'], $validated['budget_max']);
        $targetDimensions = [
            'length_cm' => isset($validated['length_cm']) ? (float) $validated['length_cm'] : null,
            'width_cm' => isset($validated['width_cm']) ? (float) $validated['width_cm'] : null,
            'height_cm' => isset($validated['height_cm']) ? (float) $validated['height_cm'] : null,
        ];

        $query = Product::query()
            ->with(['category:id,category_name', 'assets:id,product_id,file_path,asset_type,is_primary,created_at,display_order'])
            ->where('store_id', $storeId)
            ->where('product_type', 'finished_good')
            ->where('is_active', true)
            ->whereNull('deleted_at')
            ->whereHas('store', function ($storeQuery) {
                $storeQuery->whereIn('status', ['active', 'verified']);
            })
            ->whereHas('inventory', function ($inventoryQuery) use ($storeId) {
                $inventoryQuery->where('store_id', $storeId)
                    ->where('quantity_available', '>', 0)
                    ->where('stock_status', '!=', 'out_of_stock');
            });

        if (!empty($validated['category_id'])) {
            $query->where('category_id', (int) $validated['category_id']);
        }

        $products = $query->get();

        if ($products->isEmpty() && !$request->filled('store_id') && !Auth::user()?->store_id) {
            $fallbackStoreId = Product::query()
                ->select('products.store_id')
                ->join('branch_inventory', 'branch_inventory.product_id', '=', 'products.id')
                ->where('products.product_type', 'finished_good')
                ->where('products.is_active', true)
                ->whereNull('products.deleted_at')
                ->where('branch_inventory.quantity_available', '>', 0)
                ->where('branch_inventory.stock_status', '!=', 'out_of_stock')
                ->distinct()
                ->value('products.store_id');

            if ($fallbackStoreId) {
                $storeId = (int) $fallbackStoreId;
                $query = Product::query()
                    ->with(['category:id,category_name', 'assets:id,product_id,file_path,asset_type,is_primary,created_at,display_order'])
                    ->where('store_id', $storeId)
                    ->where('product_type', 'finished_good')
                    ->where('is_active', true)
                    ->whereNull('deleted_at')
                    ->whereHas('store', function ($storeQuery) {
                        $storeQuery->whereIn('status', ['active', 'verified']);
                    })
                    ->whereHas('inventory', function ($inventoryQuery) use ($storeId) {
                        $inventoryQuery->where('store_id', $storeId)
                            ->where('quantity_available', '>', 0)
                            ->where('stock_status', '!=', 'out_of_stock');
                    });

                if (!empty($validated['category_id'])) {
                    $query->where('category_id', (int) $validated['category_id']);
                }

                $products = $query->get();
            }
        }

        $scored = $products->map(function (Product $product) use ($budgetMin, $budgetMax, $targetDimensions, $storeId) {
            $price = (float) ($product->discounted_price ?? $product->base_price ?? 0);
            $budgetScore = $this->calculateBudgetScore($price, $budgetMin, $budgetMax);
            $dimensionScore = $this->calculateDimensionScore($product, $targetDimensions);
            $categoryScore = 1.0; // category filter is exact when provided.

            $weightedScore = round(($budgetScore * 0.45) + ($dimensionScore * 0.40) + ($categoryScore * 0.15), 4);

            $inventory = BranchInventory::query()
                ->where('store_id', $storeId)
                ->where('product_id', $product->id)
                ->orderByDesc('quantity_available')
                ->first();

            return [
                'id' => $product->id,
                'sku' => $product->sku,
                'product_name' => $product->product_name,
                'category' => $product->category?->category_name,
                'price' => round($price, 2),
                'image' => $this->selectBestProductImage($product)?->url,
                'quantity_available' => (int) ($inventory?->quantity_available ?? 0),
                'score' => $weightedScore,
                'score_breakdown' => [
                    'budget_score' => round($budgetScore, 4),
                    'dimension_score' => round($dimensionScore, 4),
                    'category_score' => round($categoryScore, 4),
                ],
            ];
        })->sortByDesc('score')->values();

        $perPage = (int) ($validated['per_page'] ?? 12);
        $page = max((int) $request->input('page', 1), 1);
        $offset = ($page - 1) * $perPage;
        $paginated = $scored->slice($offset, $perPage)->values();

        return response()->json([
            'success' => true,
            'data' => [
                'current_page' => $page,
                'per_page' => $perPage,
                'total' => $scored->count(),
                'last_page' => (int) ceil(max($scored->count(), 1) / $perPage),
                'data' => $paginated,
            ],
        ]);
    }

    public function dssTrendingByMovement(Request $request)
    {
        $storeId = $this->resolveStoreId($request);

        $validated = $request->validate([
            'per_page' => ['nullable', 'integer', 'min:1', 'max:50'],
            'category_id' => ['nullable', 'integer', 'exists:categories,id'],
            'search' => ['nullable', 'string', 'max:255'],
        ]);

        $query = Product::query()
            ->with(['category:id,category_name', 'assets:id,product_id,file_path,asset_type,is_primary,created_at,display_order'])
            ->where('store_id', $storeId)
            ->where('product_type', 'finished_good')
            ->where('is_active', true)
            ->whereNull('deleted_at')
            ->whereHas('store', function ($storeQuery) {
                $storeQuery->whereIn('status', ['active', 'verified']);
            })
            ->whereHas('inventory', function ($inventoryQuery) use ($storeId) {
                $inventoryQuery->where('store_id', $storeId)
                    ->where('quantity_available', '>', 0)
                    ->where('stock_status', '!=', 'out_of_stock');
            });

        if (!empty($validated['category_id'])) {
            $query->where('category_id', (int) $validated['category_id']);
        }

        if (!empty($validated['search'])) {
            $search = trim((string) $validated['search']);
            $query->where(function ($q) use ($search) {
                $q->where('product_name', 'like', "%{$search}%")
                    ->orWhere('sku', 'like', "%{$search}%");
            });
        }

        if (Schema::hasTable('ecommerce_order_items')) {
            $query
                ->withSum(['items as sold_all_time' => function ($itemQuery) use ($storeId) {
                    $itemQuery->whereHas('order', function ($orderQuery) use ($storeId) {
                        $orderQuery->where('store_id', $storeId)
                            ->whereNotIn('status', ['cancelled', 'canceled']);
                    });
                }], 'quantity')
                ->withSum(['items as sold_last_30_days' => function ($itemQuery) use ($storeId) {
                    $itemQuery->whereHas('order', function ($orderQuery) use ($storeId) {
                        $orderQuery->where('store_id', $storeId)
                            ->whereNotIn('status', ['cancelled', 'canceled'])
                            ->where('created_at', '>=', now()->subDays(30));
                    });
                }], 'quantity')
                ->withSum(['items as sold_last_7_days' => function ($itemQuery) use ($storeId) {
                    $itemQuery->whereHas('order', function ($orderQuery) use ($storeId) {
                        $orderQuery->where('store_id', $storeId)
                            ->whereNotIn('status', ['cancelled', 'canceled'])
                            ->where('created_at', '>=', now()->subDays(7));
                    });
                }], 'quantity');
        }

        $products = $query->get();

        $scored = $products->map(function (Product $product) use ($storeId) {
            $inventory = BranchInventory::query()
                ->where('store_id', $storeId)
                ->where('product_id', $product->id)
                ->orderByDesc('quantity_available')
                ->first();

            $soldAllTime = (int) ($product->sold_all_time ?? 0);
            $soldLast30 = (int) ($product->sold_last_30_days ?? 0);
            $soldLast7 = (int) ($product->sold_last_7_days ?? 0);
            $soldOlderThan30 = max(0, $soldAllTime - $soldLast30);
            $soldFrom8To30 = max(0, $soldLast30 - $soldLast7);

            // Weighted movement score, favoring recent product movement.
            $movementScore = round(
                ($soldLast7 * 3.0) +
                ($soldFrom8To30 * 1.5) +
                ($soldOlderThan30 * 0.3),
                2
            );

            return [
                'id' => $product->id,
                'sku' => $product->sku,
                'product_name' => $product->product_name,
                'category' => $product->category?->category_name,
                'price' => round((float) ($product->discounted_price ?? $product->base_price ?? 0), 2),
                'image' => $this->selectBestProductImage($product)?->url,
                'quantity_available' => (int) ($inventory?->quantity_available ?? 0),
                'movement_score' => $movementScore,
                'movement' => [
                    'sold_last_7_days' => $soldLast7,
                    'sold_last_30_days' => $soldLast30,
                    'sold_all_time' => $soldAllTime,
                ],
            ];
        })->sortByDesc('movement_score')->values();

        $perPage = (int) ($validated['per_page'] ?? 12);
        $page = max((int) $request->input('page', 1), 1);
        $offset = ($page - 1) * $perPage;
        $paginated = $scored->slice($offset, $perPage)->values();

        return response()->json([
            'success' => true,
            'data' => [
                'current_page' => $page,
                'per_page' => $perPage,
                'total' => $scored->count(),
                'last_page' => (int) ceil(max($scored->count(), 1) / $perPage),
                'data' => $paginated,
            ],
        ]);
    }

    public function chatThreads(Request $request)
    {
        $user = Auth::user();

        $threads = EcommerceChatThread::query()
            ->with([
                'store:id,name',
                'messages' => fn($q) => $q->select(['id', 'thread_id', 'message', 'created_at'])->latest('created_at')->limit(1),
            ])
            ->withCount([
                'messages as unread_count' => fn($q) => $q->where('sender_role', 'store')->whereNull('read_at'),
            ])
            ->where('customer_user_id', $user->id)
            ->orderByDesc('last_message_at')
            ->orderByDesc('updated_at')
            ->paginate((int) $request->input('per_page', 20));

        $threads->getCollection()->transform(function (EcommerceChatThread $thread) use ($user) {
            $lastMessage = $thread->messages->first();

            return [
                'id' => $thread->id,
                'store_id' => $thread->store_id,
                'store_name' => $thread->store?->name ?? 'Store',
                'last_message' => $lastMessage?->message,
                'last_message_at' => $thread->last_message_at ?? $lastMessage?->created_at,
                'unread_count' => (int) ($thread->unread_count ?? 0),
            ];
        });

        return response()->json(['success' => true, 'data' => $threads]);
    }

    public function chatMessages(Request $request, int $storeId)
    {
        $user = Auth::user();
        $store = Store::query()->whereIn('status', ['active', 'verified'])->findOrFail($storeId);

        $thread = EcommerceChatThread::query()->firstOrCreate([
            'store_id' => $storeId,
            'customer_user_id' => $user->id,
        ]);

        $messages = EcommerceChatMessage::query()
            ->with('sender:id,fname,lname')
            ->where('thread_id', $thread->id)
            ->orderByDesc('created_at')
            ->paginate((int) $request->input('per_page', 40));

        EcommerceChatMessage::query()
            ->where('thread_id', $thread->id)
            ->where('sender_role', 'store')
            ->whereNull('read_at')
            ->update(['read_at' => now()]);

        return response()->json([
            'success' => true,
            'data' => $messages,
            'thread_id' => $thread->id,
            'store' => [
                'id' => $store->id,
                'name' => $store->name,
            ],
        ]);
    }

    public function sendChatMessage(Request $request, int $storeId)
    {
        $validated = $request->validate([
            'message' => ['required', 'string', 'max:2000'],
            'order_id' => ['nullable', 'integer', 'exists:ecommerce_orders,id'],
        ]);

        $user = Auth::user();
        Store::query()->whereIn('status', ['active', 'verified'])->findOrFail($storeId);

        $thread = EcommerceChatThread::query()->firstOrCreate([
            'store_id' => $storeId,
            'customer_user_id' => $user->id,
        ]);

        $messageBody = trim((string) $validated['message']);
        if ($this->containsProfanity($messageBody)) {
            return response()->json([
                'success' => false,
                'message' => 'Please avoid profanity in chat messages.',
            ], 422);
        }

        $message = EcommerceChatMessage::query()->create([
            'thread_id' => $thread->id,
            'sender_user_id' => $user->id,
            'sender_role' => 'customer',
            'message' => $messageBody,
            'order_id' => $validated['order_id'] ?? null,
        ]);

        $thread->update(['last_message_at' => $message->created_at]);

        try {
            $this->notifyUsersByPermissions(
                (int) $storeId,
                ['sales.chats.view', 'sales.chats.manage'],
                [
                    'store_id' => (int) $storeId,
                    'module' => 'sales',
                    'entity_type' => 'ecommerce_chat_thread',
                    'entity_id' => (int) $thread->id,
                    'action' => 'new_message',
                    'title' => 'New Customer Chat Message',
                    'message' => 'A customer sent a new message in chat.',
                    'severity' => 'info',
                    'link' => '/CRM/chats',
                ],
                [(int) $user->id]
            );
        } catch (\Throwable $exception) {
            report($exception);
        }

        return response()->json(['success' => true, 'data' => $message], 201);
    }

    public function updateChatMessage(Request $request, int $storeId, int $messageId)
    {
        $validated = $request->validate([
            'message' => ['required', 'string', 'max:2000'],
        ]);

        $user = Auth::user();
        Store::query()->whereIn('status', ['active', 'verified'])->findOrFail($storeId);

        $thread = EcommerceChatThread::query()->firstOrCreate([
            'store_id' => $storeId,
            'customer_user_id' => $user->id,
        ]);

        $message = EcommerceChatMessage::query()
            ->where('thread_id', $thread->id)
            ->where('id', $messageId)
            ->firstOrFail();

        if ((int) $message->sender_user_id !== (int) $user->id || (string) $message->sender_role !== 'customer') {
            return response()->json([
                'success' => false,
                'message' => 'You can only edit your own sent messages.',
            ], 403);
        }

        $messageBody = trim((string) $validated['message']);
        if ($this->containsProfanity($messageBody)) {
            return response()->json([
                'success' => false,
                'message' => 'Please avoid profanity in chat messages.',
            ], 422);
        }

        $message->update(['message' => $messageBody]);

        return response()->json([
            'success' => true,
            'data' => $message->fresh(),
        ]);
    }

    public function unsendChatMessage(Request $request, int $storeId, int $messageId)
    {
        $user = Auth::user();
        Store::query()->whereIn('status', ['active', 'verified'])->findOrFail($storeId);

        $thread = EcommerceChatThread::query()->firstOrCreate([
            'store_id' => $storeId,
            'customer_user_id' => $user->id,
        ]);

        $message = EcommerceChatMessage::query()
            ->where('thread_id', $thread->id)
            ->where('id', $messageId)
            ->firstOrFail();

        if ((int) $message->sender_user_id !== (int) $user->id || (string) $message->sender_role !== 'customer') {
            return response()->json([
                'success' => false,
                'message' => 'You can only unsend your own sent messages.',
            ], 403);
        }

        $message->update([
            'message' => '[Message unsent]',
            'order_id' => null,
        ]);

        return response()->json([
            'success' => true,
            'data' => $message->fresh(),
        ]);
    }

    public function reportViolation(Request $request): JsonResponse
    {
        if (!Schema::hasTable('violation_reports')) {
            return response()->json([
                'success' => false,
                'message' => 'Violation reporting is not available right now.',
            ], 503);
        }

        $validated = $request->validate([
            'store_id' => ['required', 'integer', 'exists:stores,id'],
            'reason' => ['required', 'string', 'max:255'],
            'details' => ['nullable', 'string', 'max:2000'],
            'evidence_images' => ['nullable', 'array', 'max:5'],
            'evidence_images.*' => ['file', 'mimes:jpg,jpeg,png,webp', 'max:4096'],
        ]);

        $user = Auth::user();

        $evidenceUrls = [];
        if ($request->hasFile('evidence_images')) {
            foreach ($request->file('evidence_images') as $file) {
                $path = $file->store("violation-reports/{$validated['store_id']}", 'public');
                $evidenceUrls[] = Storage::disk('public')->url($path);
            }
        }

        $report = ViolationReport::query()->create([
            'store_id' => (int) $validated['store_id'],
            'reporter_user_id' => $user->id,
            'reporter_type' => 'customer',
            'report_reason' => trim((string) $validated['reason']),
            'report_details' => isset($validated['details']) ? trim((string) $validated['details']) : null,
            'evidence_urls' => $evidenceUrls,
            'status' => 'pending',
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Violation report submitted. We will review it shortly.',
            'data' => $report,
        ], 201);
    }

    public function addressTemplates()
    {
        $user = Auth::user();

        $templates = EcommerceAddressTemplate::query()
            ->where('user_id', $user->id)
            ->orderByDesc('is_default')
            ->orderByDesc('updated_at')
            ->get();

        return response()->json([
            'success' => true,
            'data' => $templates,
        ]);
    }

    public function storeAddressTemplate(Request $request)
    {
        $validated = $request->validate([
            'full_name' => ['required', 'string', 'max:120'],
            'contact_number' => ['required', 'string', 'max:40'],
            'province' => ['required', 'string', 'max:120'],
            'city' => ['required', 'string', 'max:120'],
            'barangay' => ['required', 'string', 'max:120'],
            'address_line' => ['required', 'string', 'max:255'],
            'latitude' => ['nullable', 'numeric', 'between:-90,90'],
            'longitude' => ['nullable', 'numeric', 'between:-180,180'],
            'is_default' => ['nullable', 'boolean'],
        ]);

        $user = Auth::user();

        if (!empty($validated['is_default'])) {
            EcommerceAddressTemplate::query()
                ->where('user_id', $user->id)
                ->update(['is_default' => false]);
        }

        $template = EcommerceAddressTemplate::query()->create([
            'user_id' => $user->id,
            'full_name' => $validated['full_name'],
            'contact_number' => $validated['contact_number'],
            'province' => $validated['province'],
            'city' => $validated['city'],
            'barangay' => $validated['barangay'],
            'address_line' => $validated['address_line'],
            'latitude' => $validated['latitude'] ?? null,
            'longitude' => $validated['longitude'] ?? null,
            'is_default' => (bool) ($validated['is_default'] ?? false),
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Address template saved.',
            'data' => $template,
        ], 201);
    }

    public function updateAddressTemplate(Request $request, int $id)
    {
        $validated = $request->validate([
            'full_name' => ['required', 'string', 'max:120'],
            'contact_number' => ['required', 'string', 'max:40'],
            'province' => ['required', 'string', 'max:120'],
            'city' => ['required', 'string', 'max:120'],
            'barangay' => ['required', 'string', 'max:120'],
            'address_line' => ['required', 'string', 'max:255'],
            'latitude' => ['nullable', 'numeric', 'between:-90,90'],
            'longitude' => ['nullable', 'numeric', 'between:-180,180'],
            'is_default' => ['nullable', 'boolean'],
        ]);

        $user = Auth::user();
        $template = EcommerceAddressTemplate::query()
            ->where('user_id', $user->id)
            ->findOrFail($id);

        if (!empty($validated['is_default'])) {
            EcommerceAddressTemplate::query()
                ->where('user_id', $user->id)
                ->update(['is_default' => false]);
        }

        $template->update([
            'full_name' => $validated['full_name'],
            'contact_number' => $validated['contact_number'],
            'province' => $validated['province'],
            'city' => $validated['city'],
            'barangay' => $validated['barangay'],
            'address_line' => $validated['address_line'],
            'latitude' => $validated['latitude'] ?? $template->latitude,
            'longitude' => $validated['longitude'] ?? $template->longitude,
            'is_default' => (bool) ($validated['is_default'] ?? $template->is_default),
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Address template updated.',
            'data' => $template->fresh(),
        ]);
    }

    public function validateVoucher(Request $request)
    {
        $validated = $request->validate([
            'code' => ['required', 'string', 'max:40'],
            'amount' => ['nullable', 'numeric', 'min:0'],
        ]);

        $amount = (float) ($validated['amount'] ?? 0);
        $result = $this->validateVoucherCode((string) $validated['code'], $amount);

        return response()->json([
            'success' => true,
            'message' => 'Voucher is valid.',
            'data' => $result,
        ]);
    }

    private function getOrCreateCart(?int $storeId = null): EcommerceCart
    {
        $user = Auth::user();
        $storeId = $storeId ?: $this->resolveAuthenticatedStoreId();

        return EcommerceCart::query()->firstOrCreate([
            'store_id' => $storeId,
            'user_id' => $user->id,
        ]);
    }

    private function formatCart(EcommerceCart $cart, array $favoriteMap = []): array
    {
        $items = $cart->items->map(function (EcommerceCartItem $item) use ($favoriteMap) {
            $lineSubtotal = (float) $item->unit_price * (int) $item->quantity;
            // Unit price is VAT-inclusive, so expose the included VAT without
            // increasing the amount payable by the customer.
            $lineTax = $this->extractIncludedVat($lineSubtotal);
            $lineTotal = $lineSubtotal;

            $productId = (int) $item->product_id;

            return [
                'id' => $item->id,
                'product_id' => $item->product_id,
                'product_name' => $item->product?->product_name,
                'sku' => $item->product?->sku,
                'unit_of_measurement' => $item->product?->unit_of_measurement,
                'store_name' => $item->product?->store?->store_name ?? $item->product?->store?->name ?? 'Store',
                'variation_id' => $item->variation_id,
                'variation_name' => $item->variation_name ?: $item->variation?->variation_name,
                'variation_sku' => $item->variation?->variation_sku,
                'image' => $item->product ? $this->selectBestProductImage($item->product)?->url : null,
                'is_favorite' => isset($favoriteMap[$productId]),
                'quantity' => (int) $item->quantity,
                'unit_price' => (float) $item->unit_price,
                'tax_rate' => self::VAT_RATE,
                'line_subtotal' => round($lineSubtotal, 2),
                'line_tax' => $lineTax,
                'line_total' => round($lineTotal, 2),
            ];
        })->values();

        $subtotal = round($items->sum('line_subtotal'), 2);
        $taxAmount = round($items->sum('line_tax'), 2);
        $totalAmount = round($items->sum('line_total'), 2);

        return [
            'id' => $cart->id,
            'items' => $items,
            'summary' => [
                'subtotal' => $subtotal,
                'tax_amount' => $taxAmount,
                'total_amount' => $totalAmount,
                'items_count' => (int) $items->sum('quantity'),
            ],
        ];
    }

    private function extractIncludedVat(float $vatInclusiveAmount): float
    {
        if ($vatInclusiveAmount <= 0) {
            return 0.0;
        }

        return round(
            $vatInclusiveAmount - ($vatInclusiveAmount / (1 + (self::VAT_RATE / 100))),
            2
        );
    }

    private function generateOrderNumber(): string
    {
        $datePart = now()->format('Ymd');
        $last = EcommerceOrder::query()
            ->whereDate('created_at', now()->toDateString())
            ->lockForUpdate()
            ->count();

        return sprintf('ECO-%s-%04d', $datePart, $last + 1);
    }

    private function resolveStoreId(Request $request): int
    {
        $user = Auth::user();
        if ($user?->store_id) {
            return (int) $user->store_id;
        }

        $requestedStoreId = (int) $request->input('store_id');
        if ($requestedStoreId > 0) {
            return $requestedStoreId;
        }

        return (int) Store::query()
            ->whereIn('status', ['active', 'verified'])
            ->value('id');
    }

    private function resolveAuthenticatedStoreId(): int
    {
        $user = Auth::user();
        if ($user?->store_id) {
            return (int) $user->store_id;
        }

        return (int) Store::query()
            ->whereIn('status', ['active', 'verified'])
            ->value('id');
    }

    private function toAssetUrl(?string $path): ?string
    {
        if (!$path) {
            return null;
        }

        if (str_starts_with($path, 'http://') || str_starts_with($path, 'https://')) {
            return $path;
        }

        $normalized = ltrim((string) $path, '/');
        if (str_starts_with($normalized, 'storage/')) {
            $normalized = preg_replace('#^storage/#', '', $normalized);
        }

        $normalized = ltrim((string) $normalized, '/');
        if ($normalized === '') {
            return null;
        }

        if (Storage::disk('public')->exists($normalized)) {
            return Storage::disk('public')->url($normalized);
        }

        if (Storage::disk('local')->exists($normalized)) {
            return Storage::disk('local')->url($normalized);
        }

        $publicStoragePath = public_path('storage/' . $normalized);
        if (is_file($publicStoragePath)) {
            return '/storage/' . $normalized;
        }

        $publicDirectPath = public_path($normalized);
        if (is_file($publicDirectPath)) {
            return '/' . $normalized;
        }

        return null;
    }

    private function selectBestProductImage(Product $product)
    {
        $imageAssets = $product->assets
            ->filter(fn($a) => $this->isImageAsset($a->asset_type) && !empty($a->file_path) && !is_null($a->created_at));

        if ($imageAssets->isNotEmpty()) {
            return $imageAssets
                ->sortBy([['is_primary', 'desc'], ['display_order', 'asc'], ['created_at', 'desc']])
                ->first();
        }

        // Fallback for legacy rows with missing created_at
        return $product->assets
            ->filter(fn($a) => $this->isImageAsset($a->asset_type) && !empty($a->file_path))
            ->sortBy([['is_primary', 'desc'], ['display_order', 'asc']])
            ->first();
    }

    private function isImageAsset(?string $assetType): bool
    {
        return in_array($assetType, ['Image_Main', 'Image_Gallery', 'Image_360'], true);
    }

    private function selectBest3DModel(Product $product)
    {
        $modelAssets = $product->assets
            ->filter(fn($a) => $a->asset_type === '3D_Model' && !empty($a->file_path) && !is_null($a->created_at));

        if ($modelAssets->isNotEmpty()) {
            return $modelAssets
                ->sortBy([['is_primary', 'desc'], ['display_order', 'asc'], ['created_at', 'desc']])
                ->first();
        }

        return $product->assets
            ->filter(fn($a) => $a->asset_type === '3D_Model' && !empty($a->file_path))
            ->sortBy([['is_primary', 'desc'], ['display_order', 'asc']])
            ->first();
    }

    private function formatOrder(EcommerceOrder $order): array
    {
        $orderStatus = strtolower((string) $order->status);
        $latestCancellation = $order->relationLoaded('cancellationRequests')
            ? $order->cancellationRequests->sortByDesc('created_at')->first()
            : null;
        $latestReturn = null;
        if ($order->relationLoaded('items')) {
            $latestReturn = $order->items
                ->flatMap(function ($item) {
                    if (!$item->relationLoaded('returnRequests')) {
                        return collect();
                    }

                    return $item->returnRequests;
                })
                ->sortByDesc('created_at')
                ->first();
        }

        $primaryStatus = $this->resolvePrimaryStatus($orderStatus, $latestCancellation, $latestReturn);

        return [
            'id' => $order->id,
            'store_id' => $order->store_id,
            'order_number' => $order->order_number,
            'status' => $order->status,
            'primary_status' => $primaryStatus,
            'can_cancel' => $orderStatus === 'pending' && (!$latestCancellation || $latestCancellation->status === 'rejected'),
            'cancellation_request' => $latestCancellation ? [
                'id' => $latestCancellation->id,
                'status' => $latestCancellation->status,
                'reason' => $latestCancellation->reason,
                'details' => $latestCancellation->details,
                'review_notes' => $latestCancellation->review_notes,
                'created_at' => $latestCancellation->created_at,
            ] : null,
            'payment_method' => $order->payment_method,
            'payment_status' => $order->payment_status,
            'shipping_name' => $order->shipping_name,
            'shipping_phone' => $order->shipping_phone,
            'shipping_email' => $order->shipping_email,
            'shipping_address' => $order->shipping_address,
            'customer_latitude' => $order->customer_latitude,
            'customer_longitude' => $order->customer_longitude,
            'assigned_branch' => $order->assignedBranch ? [
                'id' => $order->assignedBranch->id,
                'name' => $order->assignedBranch->name,
                'branch_code' => $order->assignedBranch->branch_code,
                'city' => $order->assignedBranch->city,
                'province' => $order->assignedBranch->province,
                'latitude' => $order->assignedBranch->latitude,
                'longitude' => $order->assignedBranch->longitude,
            ] : null,
            'subtotal' => (float) $order->subtotal,
            'tax_amount' => (float) $order->tax_amount,
            'shipping_fee' => (float) $order->shipping_fee,
            'discount_amount' => (float) $order->discount_amount,
            'total_amount' => (float) $order->total_amount,
            'placed_at' => $order->placed_at,
            'created_at' => $order->created_at,
            'store_name' => $order->store?->name ?? 'Store',
            'items_count' => (int) $order->items_count,
            'delivery' => $order->delivery ? [
                'id' => $order->delivery->id,
                'status' => $order->delivery->status,
                'delivered_at' => $order->delivery->delivered_at,
                'tracking_number' => $order->delivery->tracking_number,
                'courier_name' => $order->delivery->courier_name,
                'courier_contact' => $order->delivery->courier_contact,
                'current_latitude' => $order->delivery->current_latitude,
                'current_longitude' => $order->delivery->current_longitude,
                'current_address' => $order->delivery->current_address,
                'estimated_delivery_at' => $order->delivery->estimated_delivery_at,
                'proof_photo_url' => $order->delivery->proof_of_delivery_path ? Storage::disk('public')->url($order->delivery->proof_of_delivery_path) : null,
                'proof_signature_url' => $order->delivery->proof_signature_path ? Storage::disk('public')->url($order->delivery->proof_signature_path) : null,
            ] : null,
            'timeline' => $this->formatOrderTimeline($order),
            'items' => $order->items->map(function ($item) use ($orderStatus, $primaryStatus, $order) {
                $latestReturn = $item->relationLoaded('returnRequests')
                    ? $item->returnRequests->sortByDesc('created_at')->first()
                    : null;
                $review = $item->relationLoaded('review') ? $item->review : null;
                $eligibleAfterDelivery = in_array($orderStatus, ['delivered', 'completed'], true);
                $deliveredAt = $order->delivery?->delivered_at ?? $order->updated_at;
                $withinReturnWindow = $deliveredAt && !$deliveredAt->lt(now()->subDays(7));
                return [
                    'id' => $item->id,
                    'product_id' => $item->product_id,
                    'product_name' => $item->product_name,
                    'sku' => $item->sku,
                    'description' => $item->product?->description,
                    'unit_of_measurement' => $item->product?->unit_of_measurement,
                    'brand' => $item->product?->brand,
                    'category_name' => $item->product?->category?->category_name,
                    'weight_kg' => $item->product?->weight_kg !== null ? (float) $item->product->weight_kg : null,
                    'dimensions' => $item->product ? [
                        'length_cm' => $item->product->length_cm !== null ? (float) $item->product->length_cm : null,
                        'width_cm' => $item->product->width_cm !== null ? (float) $item->product->width_cm : null,
                        'height_cm' => $item->product->height_cm !== null ? (float) $item->product->height_cm : null,
                    ] : null,
                    'quantity' => (int) $item->quantity,
                    'unit_price' => (float) $item->unit_price,
                    'line_subtotal' => (float) $item->line_subtotal,
                    'line_tax' => (float) $item->line_tax,
                    'line_total' => (float) $item->line_total,
                    'image' => $item->product ? $this->selectBestProductImage($item->product)?->url : null,
                    'can_return' => $eligibleAfterDelivery && $withinReturnWindow && (!$latestReturn || $latestReturn->status === 'rejected'),
                    'can_review' => $eligibleAfterDelivery && !$review
                        && (!$latestReturn || $latestReturn->status === 'rejected'),
                    'return_request' => $latestReturn ? [
                        'id' => $latestReturn->id,
                        'status' => $latestReturn->status,
                        'reason' => $latestReturn->reason,
                        'details' => $latestReturn->details,
                        'review_notes' => $latestReturn->review_notes,
                        'requested_quantity' => (int) $latestReturn->requested_quantity,
                        'return_type' => $latestReturn->return_type,
                        'refund_eta' => $latestReturn->return_type === 'refund' ? 'less than 30 days after Finance sends the refund' : null,
                        'refund_method_required' => $latestReturn->return_type === 'refund'
                            && !$latestReturn->financeRefund?->refund_method,
                        'created_at' => $latestReturn->created_at,
                        'refund' => $latestReturn->financeRefund ? [
                            'id' => (int) $latestReturn->financeRefund->id,
                            'status' => $latestReturn->financeRefund->status,
                            'refund_method' => $latestReturn->financeRefund->refund_method,
                            'refund_account_name' => $latestReturn->financeRefund->refund_account_name,
                            'refund_account_number' => $latestReturn->financeRefund->refund_account_number,
                            'payout_provider' => $latestReturn->financeRefund->payout_provider,
                            'payout_reference' => $latestReturn->financeRefund->payout_reference,
                            'sent_at' => $latestReturn->financeRefund->sent_at,
                        ] : null,
                        'investigation_ticket' => $latestReturn->investigationTicket ? [
                            'id' => (int) $latestReturn->investigationTicket->id,
                            'reference_number' => $latestReturn->investigationTicket->reference_number,
                            'status' => $latestReturn->investigationTicket->status,
                            'expected_investigation_date' => $latestReturn->investigationTicket->expected_investigation_date,
                            'notes' => $latestReturn->investigationTicket->notes,
                            'created_at' => $latestReturn->investigationTicket->created_at,
                            'assignees' => $latestReturn->investigationTicket->assignees->map(fn ($employee) => [
                                'id' => (int) $employee->id,
                                'name' => trim(($employee->user?->fname ?? '') . ' ' . ($employee->user?->lname ?? '')) ?: 'Assigned investigator',
                            ])->values(),
                        ] : null,
                    ] : null,
                    'review' => $review ? [
                        'id' => $review->id,
                        'rating' => (int) $review->rating,
                        'review_text' => $review->review_text,
                        'created_at' => $review->created_at,
                    ] : null,
                ];
            })->values(),
        ];
    }

    private function resolvePrimaryStatus(
        string $orderStatus,
        ?EcommerceOrderCancellation $latestCancellation,
        ?EcommerceOrderReturn $latestReturn
    ): string {
        // Normalize the customer-facing order status to a small set of timeline states.
        $baseStatus = match (true) {
            in_array($orderStatus, ['cancelled', 'canceled'], true) => 'cancelled',
            in_array($orderStatus, ['return_pending', 'return_approved', 'return_received', 'refund_pending', 'refunded', 'replaced'], true) => $orderStatus,
            in_array($orderStatus, ['delivered', 'completed'], true) => 'delivered',
            in_array($orderStatus, ['packed', 'shipped', 'in_transit', 'out_for_delivery', 'on_delivery'], true) => 'in_transit',
            in_array($orderStatus, ['processing', 'confirmed', 'ready_for_dispatch'], true) => 'packing',
            default => 'pending',
        };

        if ($latestCancellation) {
            $cancelStatus = strtolower((string) $latestCancellation->status);
            if ($cancelStatus === 'approved') {
                return 'cancelled';
            }
            if ($cancelStatus === 'pending_verification') {
                return 'cancel_pending';
            }
        }

        if ($latestReturn) {
            $returnStatus = strtolower((string) $latestReturn->status);
            if ($returnStatus === 'refunded') {
                return 'refunded';
            }
            if ($returnStatus === 'refund_pending') {
                return 'return_processing';
            }
            if ($returnStatus === 'replaced') {
                return 'replaced';
            }
            if ($returnStatus === 'received') {
                return 'return_received';
            }
            if ($returnStatus === 'approved') {
                return 'return_approved';
            }
            if ($returnStatus === 'pending_verification') {
                return 'return_pending';
            }
        }

        return $baseStatus;
    }

    private function formatOrderTimeline(EcommerceOrder $order): array
    {
        $timeline = [[
            'type' => 'order_created',
            'title' => 'Order placed',
            'description' => 'Your order was placed successfully.',
            'status_to' => (string) $order->status,
            'actor' => 'Customer',
            'created_at' => $order->placed_at ?? $order->created_at,
        ]];

        $deliveryLogs = collect($order->delivery?->logs ?? [])->sortBy('created_at');
        foreach ($deliveryLogs as $log) {
            $actorName = trim((string) (($log->creator?->fname ?? '') . ' ' . ($log->creator?->lname ?? '')));
            $timeline[] = [
                'type' => $log->event_type ?: 'update',
                'title' => $this->timelineTitleFromLog($log->event_type, $log->status_to),
                'description' => $log->message ?: 'Order updated.',
                'status_from' => $log->status_from,
                'status_to' => $log->status_to,
                'meta' => $log->meta,
                'actor' => $actorName !== '' ? $actorName : 'Store',
                'created_at' => $log->created_at,
            ];
        }

        if ($order->relationLoaded('cancellationRequests')) {
            foreach ($order->cancellationRequests as $request) {
                $timeline[] = [
                    'type' => 'cancellation_request',
                    'title' => 'Cancellation requested',
                    'description' => $request->reason ?: 'Cancellation request submitted.',
                    'status_to' => $request->status,
                    'actor' => 'Customer',
                    'created_at' => $request->created_at,
                ];
            }
        }

        return collect($timeline)
            ->sortByDesc('created_at')
            ->values()
            ->all();
    }

    private function timelineTitleFromLog(?string $eventType, ?string $statusTo): string
    {
        if ($eventType === 'status_updated' && $statusTo) {
            return 'Status: ' . str($statusTo)->replace('_', ' ')->title();
        }

        return match ($eventType) {
            'created' => 'Delivery created',
            'driver_assigned' => 'Driver assigned',
            'proof_uploaded' => 'Proof uploaded',
            default => 'Order update',
        };
    }

    private function defaultStoreStats(): array
    {
        return [
            'products_count' => 0,
            'categories_count' => 0,
            'rating_avg' => 0,
            'rating_count' => 0,
            'followers_count' => 0,
            'badges' => [
                'response_rate' => 0,
                'cancellation_rate' => 0,
                'avg_shipping_time_hours' => 0,
            ],
        ];
    }

    private function buildFollowMapForUser($storeIds): array
    {
        $user = Auth::user();

        if (!$user) {
            $token = request()?->bearerToken();
            if ($token) {
                $accessToken = PersonalAccessToken::findToken($token);
                $tokenable = $accessToken?->tokenable;
                if ($tokenable) {
                    $user = $tokenable;
                }
            }
        }

        if (!$user || $storeIds->isEmpty() || !Schema::hasTable('ecommerce_store_follows')) {
            return [];
        }

        return EcommerceStoreFollow::query()
            ->where('user_id', $user->id)
            ->whereIn('store_id', $storeIds)
            ->pluck('store_id')
            ->mapWithKeys(fn($storeId) => [(int) $storeId => true])
            ->toArray();
    }

    private function buildStoreStatsMap($storeIds): array
    {
        if ($storeIds->isEmpty()) {
            return [];
        }
        $hasReviewsTable = Schema::hasTable('ecommerce_product_reviews');
        $hasFollowsTable = Schema::hasTable('ecommerce_store_follows');
        $hasOrdersTable = Schema::hasTable('ecommerce_orders');

        $productsStats = Product::query()
            ->selectRaw('store_id, COUNT(*) as products_count, COUNT(DISTINCT category_id) as categories_count')
            ->whereIn('store_id', $storeIds)
            ->where('product_type', 'finished_good')
            ->where('is_active', true)
            ->whereNull('deleted_at')
            ->whereHas('inventory', function ($query) {
                $query->where('quantity_available', '>', 0)
                    ->where('stock_status', '!=', 'out_of_stock');
            })
            ->groupBy('store_id')
            ->get()
            ->keyBy('store_id');

        $reviewStats = $hasReviewsTable
            ? EcommerceProductReview::query()
            ->selectRaw('store_id, COALESCE(AVG(rating), 0) as rating_avg, COUNT(*) as rating_count')
            ->whereIn('store_id', $storeIds)
            ->where('status', 'published')
            ->groupBy('store_id')
            ->get()
            ->keyBy('store_id')
            : collect();

        $followStats = $hasFollowsTable
            ? EcommerceStoreFollow::query()
            ->selectRaw('store_id, COUNT(*) as followers_count')
            ->whereIn('store_id', $storeIds)
            ->groupBy('store_id')
            ->get()
            ->keyBy('store_id')
            : collect();

        $orderStats = $hasOrdersTable
            ? EcommerceOrder::query()
            ->selectRaw("
                store_id,
                COUNT(*) as total_orders,
                SUM(CASE WHEN status <> 'pending' THEN 1 ELSE 0 END) as responded_orders,
                SUM(CASE WHEN status IN ('cancelled', 'canceled') THEN 1 ELSE 0 END) as cancelled_orders,
                AVG(CASE WHEN status IN ('delivered', 'completed') THEN TIMESTAMPDIFF(HOUR, COALESCE(placed_at, created_at), updated_at) END) as avg_shipping_hours
            ")
            ->whereIn('store_id', $storeIds)
            ->groupBy('store_id')
            ->get()
            ->keyBy('store_id')
            : collect();

        $result = [];
        foreach ($storeIds as $storeId) {
            $storeId = (int) $storeId;
            $base = $this->defaultStoreStats();

            $product = $productsStats->get($storeId);
            $review = $reviewStats->get($storeId);
            $follow = $followStats->get($storeId);
            $orders = $orderStats->get($storeId);

            $totalOrders = (int) ($orders->total_orders ?? 0);
            $responded = (int) ($orders->responded_orders ?? 0);
            $cancelled = (int) ($orders->cancelled_orders ?? 0);

            $responseRate = $totalOrders > 0 ? round(($responded / $totalOrders) * 100, 2) : 0;
            $cancellationRate = $totalOrders > 0 ? round(($cancelled / $totalOrders) * 100, 2) : 0;
            $avgShippingHours = round((float) ($orders->avg_shipping_hours ?? 0), 2);

            $result[$storeId] = [
                'products_count' => (int) ($product->products_count ?? 0),
                'categories_count' => (int) ($product->categories_count ?? 0),
                'rating_avg' => round((float) ($review->rating_avg ?? 0), 2),
                'rating_count' => (int) ($review->rating_count ?? 0),
                'followers_count' => (int) ($follow->followers_count ?? 0),
                'badges' => [
                    'response_rate' => $responseRate,
                    'cancellation_rate' => $cancellationRate,
                    'avg_shipping_time_hours' => $avgShippingHours,
                ],
            ];
        }

        return $result;
    }

    private function resolveFulfillmentBranch(
        int $storeId,
        $itemsForCheckout,
        ?float $customerLatitude = null,
        ?float $customerLongitude = null
    ): ?Branch {
        $candidateBranches = Branch::query()
            ->where('store_id', $storeId)
            ->where('status', 'active')
            ->get(['id', 'name', 'city', 'province', 'latitude', 'longitude']);

        if ($candidateBranches->isEmpty()) {
            return null;
        }

        $branchScores = [];
        foreach ($candidateBranches as $branch) {
            $allItemsAvailable = true;
            $totalAvailable = 0;

            foreach ($itemsForCheckout as $item) {
                $inventory = BranchInventory::query()
                    ->where('store_id', $storeId)
                    ->where('branch_id', $branch->id)
                    ->where('product_id', $item->product_id)
                    ->when($item->variation_id, fn($q) => $q->where('variation_id', $item->variation_id))
                    ->first();

                $available = (int) ($inventory?->quantity_available ?? 0);
                $totalAvailable += $available;
                if ($available < (int) $item->quantity) {
                    $allItemsAvailable = false;
                    break;
                }
            }

            if (!$allItemsAvailable) {
                continue;
            }

            $distance = null;
            if (!is_null($customerLatitude) && !is_null($customerLongitude) && !is_null($branch->latitude) && !is_null($branch->longitude)) {
                $distance = $this->haversineKm(
                    $customerLatitude,
                    $customerLongitude,
                    (float) $branch->latitude,
                    (float) $branch->longitude
                );
            }

            $branchScores[] = [
                'branch' => $branch,
                'distance' => $distance,
                'stock_score' => $totalAvailable,
            ];
        }

        if (empty($branchScores)) {
            return null;
        }

        usort($branchScores, function (array $a, array $b) {
            $aDistance = $a['distance'];
            $bDistance = $b['distance'];

            if (!is_null($aDistance) && !is_null($bDistance) && $aDistance !== $bDistance) {
                return $aDistance <=> $bDistance;
            }

            // Fallback to stock score desc.
            return $b['stock_score'] <=> $a['stock_score'];
        });

        return $branchScores[0]['branch'];
    }

    private function haversineKm(float $lat1, float $lon1, float $lat2, float $lon2): float
    {
        $earthRadius = 6371.0;
        $dLat = deg2rad($lat2 - $lat1);
        $dLon = deg2rad($lon2 - $lon1);

        $a = sin($dLat / 2) * sin($dLat / 2) +
            cos(deg2rad($lat1)) * cos(deg2rad($lat2)) *
            sin($dLon / 2) * sin($dLon / 2);
        $c = 2 * atan2(sqrt($a), sqrt(1 - $a));

        return $earthRadius * $c;
    }

    private function calculateBudgetScore(float $price, float $budgetMin, float $budgetMax): float
    {
        if ($price >= $budgetMin && $price <= $budgetMax) {
            return 1.0;
        }

        $distance = $price < $budgetMin
            ? ($budgetMin - $price)
            : ($price - $budgetMax);

        $range = max(1.0, $budgetMax - $budgetMin);
        return max(0.0, 1.0 - ($distance / ($range * 2)));
    }

    private function calculateDimensionScore(Product $product, array $targetDimensions): float
    {
        $fields = ['length_cm', 'width_cm', 'height_cm'];
        $scores = [];

        foreach ($fields as $field) {
            $target = $targetDimensions[$field] ?? null;
            $actual = $product->{$field};
            if (is_null($target) || $target <= 0 || is_null($actual) || (float) $actual <= 0) {
                continue;
            }

            $targetValue = (float) $target;
            $actualValue = (float) $actual;
            $diffPercent = abs($actualValue - $targetValue) / $targetValue;
            $scores[] = max(0.0, 1.0 - min($diffPercent, 1.0));
        }

        if (empty($scores)) {
            return 0.75;
        }

        return array_sum($scores) / count($scores);
    }

    private function validateVoucherCode(string $code, float $orderAmount): array
    {
        $storeId = $this->resolveAuthenticatedStoreId();
        $normalized = strtoupper(trim($code));

        $voucher = EcommerceVoucher::query()
            ->where('code', $normalized)
            ->where('is_active', true)
            ->where(function ($query) use ($storeId) {
                $query->whereNull('store_id')->orWhere('store_id', $storeId);
            })
            ->where(function ($query) {
                $query->whereNull('starts_at')->orWhere('starts_at', '<=', now());
            })
            ->where(function ($query) {
                $query->whereNull('ends_at')->orWhere('ends_at', '>=', now());
            })
            ->first();

        if (!$voucher) {
            abort(response()->json([
                'success' => false,
                'message' => 'Voucher does not exist or is inactive.',
            ], 422));
        }

        $minimum = (float) ($voucher->min_order_amount ?? 0);
        if ($minimum > 0 && $orderAmount < $minimum) {
            abort(response()->json([
                'success' => false,
                'message' => "Minimum order amount is PHP {$minimum} for this voucher.",
            ], 422));
        }

        $discount = $voucher->discount_type === 'percent'
            ? ($orderAmount * ((float) $voucher->discount_value / 100))
            : (float) $voucher->discount_value;

        if (!is_null($voucher->max_discount_amount)) {
            $discount = min($discount, (float) $voucher->max_discount_amount);
        }

        $discount = round(max(0, min($discount, $orderAmount)), 2);

        return [
            'voucher' => [
                'id' => $voucher->id,
                'code' => $voucher->code,
                'discount_type' => $voucher->discount_type,
                'discount_value' => (float) $voucher->discount_value,
            ],
            'discount_amount' => $discount,
        ];
    }

    private function computeStoreDeliveryFeeFallback(int $storeId, float $subtotal, float $distanceKm, float $totalWeightKg = 0): array
    {
        $setting = StoreDeliveryFeeSetting::query()->where('store_id', $storeId)->first();
        if (!$setting) {
            $setting = new StoreDeliveryFeeSetting([
                'store_id' => $storeId,
                'is_active' => true,
                'base_fee' => 100,
                'per_km_fee' => 10,
                'per_kg_fee' => 0,
                'min_delivery_fee' => 80,
                'free_shipping_min_order' => null,
                'bulky_item_surcharge' => 0,
                'remote_area_surcharge' => 0,
                'max_delivery_distance_km' => null,
                'bulk_discount_rate' => 10,
            ]);
        }

        $maxDistance = $setting->max_delivery_distance_km;
        if (!is_null($maxDistance) && $distanceKm > (float) $maxDistance) {
            return [
                'delivery_available' => false,
                'shipping_fee' => 0.0,
                'free_shipping_applied' => false,
                'base_fee' => 0.0,
                'distance_fee' => 0.0,
                'weight_fee' => 0.0,
                'minimum_applied' => false,
            ];
        }

        if (!(bool) $setting->is_active) {
            return [
                'shipping_fee' => 0.0,
                'delivery_available' => true,
                'free_shipping_applied' => false,
                'base_fee' => 0.0,
                'distance_fee' => 0.0,
                'weight_fee' => 0.0,
                'minimum_applied' => false,
            ];
        }

        $freeThreshold = $setting->free_shipping_min_order;
        if (!is_null($freeThreshold) && $subtotal >= (float) $freeThreshold) {
            return [
                'shipping_fee' => 0.0,
                'delivery_available' => true,
                'free_shipping_applied' => true,
                'base_fee' => (float) $setting->base_fee,
                'distance_fee' => round($distanceKm * (float) $setting->per_km_fee, 2),
                'weight_fee' => round($totalWeightKg * (float) $setting->per_kg_fee, 2),
                'minimum_applied' => false,
            ];
        }

        $base = (float) $setting->base_fee;
        $distanceFee = $distanceKm * (float) $setting->per_km_fee;
        $weightFee = $totalWeightKg * (float) $setting->per_kg_fee;
        $raw = $base + $distanceFee + $weightFee;
        $min = (float) $setting->min_delivery_fee;
        $applied = max($raw, $min);

        return [
            'shipping_fee' => round($applied, 2),
            'delivery_available' => true,
            'free_shipping_applied' => false,
            'base_fee' => round($base, 2),
            'distance_fee' => round($distanceFee, 2),
            'weight_fee' => round($weightFee, 2),
            'minimum_applied' => $applied > $raw,
        ];
    }

    private function resolveBulkTripDiscountRate(int $storeId): float
    {
        $setting = StoreDeliveryFeeSetting::query()->where('store_id', $storeId)->first();
        $ratePercent = $setting?->bulk_discount_rate;

        if (!is_numeric($ratePercent)) {
            $ratePercent = 10;
        }

        $ratePercent = max(5, min(25, (float) $ratePercent));

        return $ratePercent / 100;
    }

    private function containsProfanity(string $message): bool
    {
        static $pattern = null;
        if (!is_string($pattern) || $pattern === '') {
            $words = [
                // English
                'fuck',
                'fucking',
                'fucker',
                'shit',
                'bitch',
                'asshole',
                'bastard',
                'dick',
                'pussy',
                'motherfucker',
                'cunt',
                'damn',
                'abnormal',
                'adik',
                'ahas',
                'abusado',
                'amputa',
                'baboy',
                'bading',
                'baliw',
                'balasubas',
                'bastos',
                'bastos-na-bastos',
                'bastos-na-walanghiya',
                'bastardo',
                'basura',
                'basura-ka',
                'bayag',
                'bobong-bobo',
                'bobo',
                'bogo',
                'burat',
                'buhay-hayop',
                'buhay-na-demonyo',
                'buwisit',
                'bilat',
                'bwisit',
                'bwisit-na-gago',
                'bunganga',
                'demonyita',
                'demonyo',
                'demonyo-ka-talaga',
                'dugyot',
                'duwag',
                'duwag-na-duwag',
                'gago',
                'gaga',
                'gagi',
                'gago-ka-talaga',
                'gago-amputa',
                'gago-ulol',
                'gunggong',
                'hambog',
                'hampaslupa',
                'hayop',
                'hayop-ka',
                'hayop-ka-talaga',
                'hayup',
                'hindot',
                'hinayupak',
                'hinayupak-ka',
                'hinayupak-ka-talaga',
                'hudas',
                'hudas-barabas',
                'impyerno',
                'inutil',
                'itits',
                'insulto',
                'ipokrito',
                'iyot',
                'iyot-ka',
                'judas',
                'kupaloid',
                'kupal',
                'kantot',
                'kantutan',
                'lapastangan',
                'leche',
                'leche-ka',
                'leche-ka-talaga',
                'lecheng-buhay',
                'letse-flan',
                'lintik',
                'lintik-ka',
                'lintik-na-buhay',
                'loko',
                'loko-loko',
                'lupang-ina',
                'makapal-na-mukha',
                'makasarili',
                'malandi',
                'malaswa',
                'malibog',
                'mangmang',
                'mangmang-na-mangmang',
                'manloloko',
                'manyak',
                'manyakis',
                'nimal',
                'ogag',
                'ogag-ka',
                'ogag-na-ogag',
                'pakyo',
                'pakyu-pakyu',
                'pakyut',
                'palahula',
                'pangit',
                'patay-gutom',
                'peste',
                'poke',
                'poki',
                'pakshet',
                'pambihira',
                'puta',
                'putanginamo',
                'putangina',
                'putragis',
                'saksakan',
                'shet',
                'shit',
                'tangina-gago',
                'tangina-mo',
                'tangnamo',
                'ulupong',
            ];

            // Match whole terms, allowing hyphenated phrases. Use Unicode-safe boundaries.
            $escaped = array_map(static fn ($w) => preg_quote($w, '/'), $words);
            $alternation = implode('|', $escaped);
            $pattern = '/(?<![\\pL\\pN_])(?:' . $alternation . ')(?![\\pL\\pN_])/iu';
        }

        return preg_match($pattern, $message) === 1;
    }
}


