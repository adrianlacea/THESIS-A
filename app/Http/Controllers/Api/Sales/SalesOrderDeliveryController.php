<?php

namespace App\Http\Controllers\Api\Sales;

use App\Http\Controllers\Controller;
use App\Models\Core\User;
use App\Models\Ecommerce\EcommerceOrderDelivery;
use App\Models\Sales\SalesOrder;
use App\Models\Sales\SalesOrderDelivery;
use App\Models\Sales\SalesOrderDeliveryLog;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;

class SalesOrderDeliveryController extends Controller
{
    private const DELIVERY_STATUSES = [
        'assigned',
        'packed',
        'in_transit',
        'out_for_delivery',
        'delivered',
        'failed_delivery',
        'cancelled',
    ];

    public function index(Request $request): JsonResponse
    {
        // A few older orders can already be marked Ready for Dispatch without
        // having an order_deliveries row (for example, after a status update
        // made before the delivery queue was introduced). Reconcile those
        // orders before reading the queue so Sales and Logistics stay in sync.
        $this->ensureReadyOrdersAreQueued($request);

        $status = $request->filled('status') ? (string) $request->input('status') : null;
        $search = trim((string) $request->input('search', ''));

        $salesQuery = SalesOrderDelivery::query()
            ->with([
                'order:id,branch_id,order_number,status,total_amount,shipping_fee,customer_name,customer_phone,notes,delivery_required,delivery_address,delivery_notes,delivery_province,delivery_city,delivery_barangay,delivery_address_line,delivery_latitude,delivery_longitude,delivery_email',
                'driver:id,fname,lname,email',
            ]);

        $this->applyTenantScope($request, $salesQuery);

        if ($status) {
            $salesQuery->where('status', $status);
        }

        if ($search !== '') {
            $salesQuery->where(function ($q) use ($search) {
                $q->where('tracking_number', 'like', "%{$search}%")
                    ->orWhere('courier_name', 'like', "%{$search}%")
                    ->orWhereHas('order', function ($orderQuery) use ($search) {
                        $orderQuery->where('order_number', 'like', "%{$search}%")
                            ->orWhere('customer_name', 'like', "%{$search}%");
                    });
            });
        }

        $ecomQuery = EcommerceOrderDelivery::query()
            ->with([
                'order:id,order_number,shipping_name,shipping_phone,shipping_address',
                'driver:id,fname,lname,email',
            ]);

        $user = $request->user();
        if (!$user->hasRole('super_admin')) {
            $ecomQuery->where('store_id', (int) $user->store_id);
        } elseif ($request->filled('store_id')) {
            $ecomQuery->where('store_id', (int) $request->input('store_id'));
        }

        if ($status) {
            $ecomQuery->where('status', $status);
        }

        if ($search !== '') {
            $ecomQuery->where(function ($q) use ($search) {
                $q->where('tracking_number', 'like', "%{$search}%")
                    ->orWhere('courier_name', 'like', "%{$search}%")
                    ->orWhereHas('order', function ($orderQuery) use ($search) {
                        $orderQuery->where('order_number', 'like', "%{$search}%")
                            ->orWhere('shipping_name', 'like', "%{$search}%");
                    });
            });
        }

        $salesRows = $salesQuery->orderByDesc('created_at')->get()->map(function (SalesOrderDelivery $delivery) {
            return [
                'id' => $delivery->id,
                'source' => 'sales',
                'channel' => 'In-Store',
                'tracking_number' => $delivery->tracking_number,
                'status' => $delivery->status,
                'scheduled_delivery_at' => $delivery->scheduled_delivery_at,
                'created_at' => $delivery->created_at,
                'driver' => $delivery->driver,
                'order_id' => $delivery->sales_order_id,
                'order_number' => $delivery->order?->order_number,
                'customer_name' => $delivery->order?->customer_name,
            ];
        });

        $ecomRows = $ecomQuery->orderByDesc('created_at')->get()->map(function (EcommerceOrderDelivery $delivery) {
            return [
                'id' => $delivery->id,
                'source' => 'ecommerce',
                'channel' => 'Online',
                'tracking_number' => $delivery->tracking_number,
                'status' => $delivery->status,
                'scheduled_delivery_at' => $delivery->estimated_delivery_at,
                'created_at' => $delivery->created_at,
                'driver' => $delivery->driver,
                'order_id' => $delivery->order_id,
                'order_number' => $delivery->order?->order_number,
                'customer_name' => $delivery->order?->shipping_name,
            ];
        });

        $deliveries = $salesRows->merge($ecomRows)
            ->sortByDesc('created_at')
            ->values();

        return response()->json(['success' => true, 'data' => $deliveries]);
    }

    private function ensureReadyOrdersAreQueued(Request $request): void
    {
        $query = SalesOrder::query()
            ->where('status', 'ready_for_dispatch')
            ->where('delivery_required', true)
            ->whereDoesntHave('delivery');

        $this->applyTenantScope($request, $query);
        $orders = $query->get();
        if ($orders->isEmpty()) {
            return;
        }

        DB::transaction(function () use ($orders, $request): void {
            foreach ($orders as $order) {
                $delivery = SalesOrderDelivery::query()->firstOrCreate(
                    ['sales_order_id' => $order->id],
                    [
                        'store_id' => $order->store_id,
                        'branch_id' => $order->branch_id,
                        'status' => 'ready_for_dispatch',
                        'notes' => 'Order is ready for logistics assignment.',
                        'created_by' => $request->user()->id,
                        'updated_by' => $request->user()->id,
                    ]
                );

                if ($delivery->wasRecentlyCreated) {
                    SalesOrderDeliveryLog::query()->create([
                        'delivery_id' => $delivery->id,
                        'sales_order_id' => $order->id,
                        'store_id' => $order->store_id,
                        'event_type' => 'created',
                        'status_to' => 'ready_for_dispatch',
                        'message' => 'Ready for Dispatch order queued for logistics assignment.',
                        'created_by' => $request->user()->id,
                    ]);
                }
            }
        });
    }

    public function show(Request $request, int $id): JsonResponse
    {
        $query = SalesOrderDelivery::query()
            ->with([
                'order:id,branch_id,order_number,status,total_amount,shipping_fee,customer_name,customer_phone,notes,delivery_required,delivery_address,delivery_notes,delivery_province,delivery_city,delivery_barangay,delivery_address_line,delivery_latitude,delivery_longitude,delivery_email,created_at',
                'order.items:id,order_id,product_name,sku,quantity,unit_price,line_total',
                'driver:id,fname,lname,email',
                'logs:id,delivery_id,sales_order_id,event_type,status_from,status_to,message,meta,created_by,created_at',
                'logs.creator:id,fname,lname,email',
            ]);

        $this->applyTenantScope($request, $query);
        $delivery = $query->findOrFail($id);

        $data = $delivery->toArray();
        $data['proof_photo_url'] = $delivery->proof_of_delivery_path ? Storage::disk('public')->url($delivery->proof_of_delivery_path) : null;
        $data['proof_signature_url'] = $delivery->proof_signature_path ? Storage::disk('public')->url($delivery->proof_signature_path) : null;

        return response()->json(['success' => true, 'data' => $data]);
    }

    public function updateStatus(Request $request, int $id): JsonResponse
    {
        $validated = $request->validate([
            'status' => ['required', Rule::in(self::DELIVERY_STATUSES)],
            'failed_reason' => 'nullable|string|max:1000',
            'notes' => 'nullable|string|max:1000',
        ]);

        $query = SalesOrderDelivery::query()->with('order');
        $this->applyTenantScope($request, $query);
        $delivery = $query->findOrFail($id);
        $previousStatus = (string) $delivery->status;

        $delivery->status = $validated['status'];
        $delivery->failed_reason = $validated['failed_reason'] ?? $delivery->failed_reason;
        $delivery->notes = $validated['notes'] ?? $delivery->notes;
        $delivery->updated_by = $request->user()->id;

        if ($validated['status'] === 'in_transit' && !$delivery->dispatched_at) {
            $delivery->dispatched_at = now();
        }
        if ($validated['status'] === 'out_for_delivery' && !$delivery->out_for_delivery_at) {
            $delivery->out_for_delivery_at = now();
        }
        if ($validated['status'] === 'delivered' && !$delivery->delivered_at) {
            $delivery->delivered_at = now();
        }

        $delivery->save();

        $this->logEvent(
            $delivery,
            'status_updated',
            "Delivery status updated from {$previousStatus} to {$validated['status']}.",
            $request->user()->id,
            $previousStatus,
            $validated['status']
        );

        // Sales POS orders have their own limited status enum; avoid mutating it here.

        return response()->json([
            'success' => true,
            'message' => 'Delivery updated successfully.',
            'data' => $delivery->fresh(['order', 'driver', 'logs.creator']),
        ]);
    }

    public function drivers(Request $request): JsonResponse
    {
        $user = $request->user();
        if (!$user->store_id && !$user->hasRole('super_admin')) {
            return response()->json(['success' => false, 'message' => 'No store assigned.'], 422);
        }

        $storeId = $user->hasRole('super_admin') && $request->filled('store_id')
            ? (int) $request->input('store_id')
            : (int) $user->store_id;

        $branchId = $this->resolveBranchId($request);
        if (!$branchId && !$user->hasRole('super_admin')) {
            return response()->json(['success' => false, 'message' => 'No branch assigned.'], 422);
        }

        $roleIds = DB::table('role_permissions')
            ->join('permissions', 'role_permissions.permission_id', '=', 'permissions.id')
            ->whereIn('permissions.name', ['logistics.deliveries.view', 'logistics.deliveries.manage'])
            ->pluck('role_permissions.role_id')
            ->unique();

        $drivers = User::query()
            ->with(['role:id,name,display_name', 'employee:id,user_id,branch_id,status'])
            ->where('store_id', $storeId)
            ->where('is_active', true)
            ->when($roleIds->isNotEmpty(), fn ($q) => $q->whereIn('role_id', $roleIds))
            ->when($branchId, fn ($q) => $q->whereHas('employee', fn ($employee) => $employee->where('branch_id', $branchId)))
            ->orderBy('fname')
            ->orderBy('lname')
            ->get()
            ->map(fn (User $driver) => [
                'id' => $driver->id,
                'name' => trim(($driver->fname ?? '') . ' ' . ($driver->lname ?? '')),
                'email' => $driver->email,
                'contact' => $driver->phone_number,
                'branch_id' => $driver->employee?->branch_id,
                'role' => $driver->role?->display_name ?? $driver->role?->name ?? 'N/A',
            ])
            ->values();

        return response()->json(['success' => true, 'data' => $drivers]);
    }

    public function assignDriver(Request $request, int $id): JsonResponse
    {
        $validated = $request->validate([
            'driver_user_id' => 'required|exists:users,id',
        ]);

        $query = SalesOrderDelivery::query()->with('order');
        $this->applyTenantScope($request, $query);
        $delivery = $query->findOrFail($id);

        $roleIds = DB::table('role_permissions')
            ->join('permissions', 'role_permissions.permission_id', '=', 'permissions.id')
            ->whereIn('permissions.name', ['logistics.deliveries.view', 'logistics.deliveries.manage'])
            ->pluck('role_permissions.role_id')
            ->unique();

        $driver = User::query()
            ->where('id', (int) $validated['driver_user_id'])
            ->where('store_id', $delivery->store_id)
            ->where('is_active', true)
            ->when($roleIds->isNotEmpty(), fn ($q) => $q->whereIn('role_id', $roleIds))
            ->firstOrFail();

        $delivery->driver_user_id = $driver->id;
        $delivery->updated_by = $request->user()->id;
        $delivery->save();

        $this->logEvent(
            $delivery,
            'driver_assigned',
            'Driver assigned: ' . trim(($driver->fname ?? '') . ' ' . ($driver->lname ?? '')),
            $request->user()->id
        );

        return response()->json([
            'success' => true,
            'message' => 'Driver assigned successfully.',
            'data' => $delivery->fresh(['order', 'driver']),
        ]);
    }

    public function uploadProof(Request $request, int $id): JsonResponse
    {
        $validated = $request->validate([
            'photo' => 'required|image|max:5120',
            'signature' => 'required|image|max:5120',
            'notes' => 'nullable|string|max:1000',
        ]);

        $query = SalesOrderDelivery::query()->with('order');
        $this->applyTenantScope($request, $query);
        $delivery = $query->findOrFail($id);

        $delivery->proof_of_delivery_path = $request->file('photo')->store('sales/pod/photos', 'public');
        $delivery->proof_signature_path = $request->file('signature')->store('sales/pod/signatures', 'public');
        if (!empty($validated['notes'])) {
            $delivery->notes = trim((string) $validated['notes']);
        }

        $previousStatus = (string) $delivery->status;
        $delivery->status = 'delivered';
        $delivery->delivered_at = $delivery->delivered_at ?: now();
        $delivery->updated_by = $request->user()->id;
        $delivery->save();

        // Do not update Sales POS order status here (enum doesn't include delivery states).

        $proofPhotoUrl = Storage::disk('public')->url($delivery->proof_of_delivery_path);
        $proofSignatureUrl = Storage::disk('public')->url($delivery->proof_signature_path);

        $this->logEvent(
            $delivery,
            'proof_uploaded',
            'Proof of delivery uploaded.',
            $request->user()->id,
            null,
            null,
            [
                'proof_photo_url' => $proofPhotoUrl,
                'proof_signature_url' => $proofSignatureUrl,
            ],
        );

        if ($previousStatus !== 'delivered') {
            $this->logEvent(
                $delivery,
                'status_updated',
                "Delivery status updated from {$previousStatus} to delivered.",
                $request->user()->id,
                $previousStatus,
                'delivered'
            );
        }

        return response()->json([
            'success' => true,
            'message' => 'Proof of delivery uploaded successfully.',
            'data' => [
                'proof_photo_url' => $proofPhotoUrl,
                'proof_signature_url' => $proofSignatureUrl,
                'status' => $delivery->status,
            ],
        ]);
    }

    public function logs(Request $request, int $id): JsonResponse
    {
        $query = SalesOrderDelivery::query();
        $this->applyTenantScope($request, $query);
        $delivery = $query->findOrFail($id);

        $logs = SalesOrderDeliveryLog::query()
            ->with('creator:id,fname,lname,email')
            ->where('delivery_id', $delivery->id)
            ->orderByDesc('created_at')
            ->paginate((int) $request->input('per_page', 20));

        return response()->json(['success' => true, 'data' => $logs]);
    }

    public function addLog(Request $request, int $id): JsonResponse
    {
        $validated = $request->validate([
            'message' => 'required|string|max:1000',
        ]);

        $query = SalesOrderDelivery::query();
        $this->applyTenantScope($request, $query);
        $delivery = $query->findOrFail($id);

        $log = $this->logEvent(
            $delivery,
            'note',
            (string) $validated['message'],
            $request->user()->id
        );

        return response()->json([
            'success' => true,
            'message' => 'Log added.',
            'data' => $log->load('creator:id,fname,lname,email'),
        ], 201);
    }

    private function applyTenantScope(Request $request, $query): void
    {
        $user = $request->user();

        if (!$user->hasRole('super_admin')) {
            $query->where('store_id', $user->store_id);

            $branchId = $this->resolveBranchId($request);
            if ($branchId) {
                $query->where('branch_id', $branchId);
            }

            return;
        }

        if ($request->filled('store_id')) {
            $query->where('store_id', (int) $request->input('store_id'));
        }

        if ($request->filled('branch_id')) {
            $query->where('branch_id', (int) $request->input('branch_id'));
        }
    }

    private function resolveBranchId(Request $request): ?int
    {
        $user = $request->user();

        if ($user->hasRole('super_admin') && $request->filled('branch_id')) {
            return (int) $request->input('branch_id');
        }

        return $user->employee?->branch_id ? (int) $user->employee->branch_id : null;
    }

    private function logEvent(
        SalesOrderDelivery $delivery,
        string $eventType,
        string $message,
        ?int $createdBy = null,
        ?string $statusFrom = null,
        ?string $statusTo = null,
        ?array $meta = null,
    ): SalesOrderDeliveryLog {
        return SalesOrderDeliveryLog::query()->create([
            'delivery_id' => $delivery->id,
            'sales_order_id' => $delivery->sales_order_id,
            'store_id' => $delivery->store_id,
            'event_type' => $eventType,
            'status_from' => $statusFrom,
            'status_to' => $statusTo,
            'message' => $message,
            'meta' => $meta,
            'created_by' => $createdBy,
        ]);
    }
}
