<?php

namespace App\Http\Controllers\Api\Finance;

use App\Http\Controllers\Controller;
use App\Models\CRM\EcommerceOrderReturn;
use App\Models\Finance\FinanceRefund;
use App\Models\PaymongoIntent;
use App\Services\Finance\CashflowService;
use App\Services\Payment\PaymongoService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class FinanceRefundController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $query = FinanceRefund::query()->with(['branch:id,name', 'requester:id,fname,lname'])
            ->where('store_id', $request->user()->store_id);

        if ($request->filled('status')) $query->where('status', $request->string('status'));
        if ($request->filled('source') && $request->string('source')->toString() !== 'all') $query->where('order_type', $request->string('source'));
        if ($request->filled('search')) {
            $term = trim($request->string('search')->toString());
            $query->where(fn ($q) => $q->where('order_number', 'like', "%{$term}%")
                ->orWhere('customer_name', 'like', "%{$term}%")->orWhere('reason', 'like', "%{$term}%"));
        }

        return response()->json(['data' => $query->latest()->get()]);
    }

    public function show(Request $request, FinanceRefund $refund): JsonResponse
    {
        $this->authorizeStore($request, $refund);
        $refund->load([
            'branch:id,name,address,contact_number', 'requester:id,fname,lname,email',
            'processor:id,fname,lname,email', 'sender:id,fname,lname,email',
            'ecommerceReturn' => fn ($q) => $q->with([
                'order:id,order_number,shipping_name,shipping_phone,shipping_email,shipping_address,total_amount,status',
                'orderItem:id,order_id,product_id,product_name,sku,quantity,unit_price',
                'pickup:id,return_id,status,destination_branch_id,delivered_at',
                'pickup.destinationBranch:id,name,address',
            ]),
        ]);
        if ($refund->order_type !== 'ecommerce_return') {
            $refund->setRelation('ecommerceReturn', null);
        }
        return response()->json(['data' => $refund]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'order_type' => 'required|in:ecommerce_return,ecommerce,sales,manual', 'order_id' => 'required|integer|min:1',
            'order_number' => 'nullable|string|max:100', 'customer_name' => 'required|string|max:190',
            'reason' => 'required|string|max:2000', 'amount' => 'required|numeric|min:0.01', 'notes' => 'nullable|string|max:2000',
        ]);
        $refund = FinanceRefund::create([...$data, 'store_id' => $request->user()->store_id,
            'branch_id' => $request->user()->employee?->branch_id, 'status' => 'pending', 'requested_by' => $request->user()->id]);
        return response()->json(['message' => 'Refund request created.', 'data' => $refund], 201);
    }

    public function updateStatus(Request $request, FinanceRefund $refund): JsonResponse
    {
        $this->authorizeStore($request, $refund);
        $data = $request->validate(['status' => 'required|in:approved,rejected', 'notes' => 'nullable|string|max:2000']);
        $linkedReturn = null;

        DB::transaction(function () use ($request, $refund, $data, &$linkedReturn) {
            $refund = FinanceRefund::query()->lockForUpdate()->findOrFail($refund->id);
            if ($refund->status !== 'pending') throw ValidationException::withMessages(['status' => ['Only a pending refund can be processed.']]);
            if ($data['status'] === 'approved') {
                if ($refund->order_type === 'ecommerce_return'
                    && (!$refund->refund_method || !$refund->refund_account_name || !$refund->refund_account_number)) {
                    throw ValidationException::withMessages([
                        'refund_method' => ['The customer must provide a refund payment method before Finance can release funds.'],
                    ]);
                }
            }
            $refund->update(['status' => $data['status'], 'notes' => $data['notes'] ?? $refund->notes,
                'processed_by' => $request->user()->id, 'processed_at' => now()]);
            if ($data['status'] === 'rejected' && $refund->order_type === 'ecommerce_return') {
                $linkedReturn = EcommerceOrderReturn::query()->where('store_id', $refund->store_id)->lockForUpdate()->find($refund->order_id);
                if ($linkedReturn && $linkedReturn->status === 'refund_pending') {
                    $linkedReturn->update(['status' => 'received']);
                    $linkedReturn->order()->update(['status' => 'return_received']);
                }
            }
        });

        return response()->json(['message' => $data['status'] === 'approved'
            ? 'Refund approved. Send the money to the customer account, then mark it as sent.'
            : 'Refund rejected.', 'data' => $refund->fresh()]);
    }

    public function markSent(Request $request, FinanceRefund $refund, CashflowService $cashflow, PaymongoService $paymongo): JsonResponse
    {
        $this->authorizeStore($request, $refund);
        $data = $request->validate([
            'payout_reference' => ['nullable', 'string', 'max:190'],
            'provider' => ['nullable', 'in:paymongo,manual'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ]);

        $linkedReturn = null;
        DB::transaction(function () use ($request, $refund, $cashflow, $paymongo, $data, &$linkedReturn): void {
            $refund = FinanceRefund::query()->lockForUpdate()->findOrFail($refund->id);
            if ($refund->status !== 'approved') {
                throw ValidationException::withMessages(['status' => ['Only an approved refund can be marked as sent.']]);
            }
            if (!$refund->refund_method || !$refund->refund_account_name || !$refund->refund_account_number) {
                throw ValidationException::withMessages([
                    'refund_method' => ['The customer refund account details are incomplete.'],
                ]);
            }

            $payoutProvider = (string) ($data['provider'] ?? 'paymongo');
            $payoutReference = trim((string) ($data['payout_reference'] ?? ''));
            $paymongoRefundId = null;

            if ($payoutProvider === 'paymongo' && $refund->order_type === 'ecommerce_return') {
                $linkedReturn = EcommerceOrderReturn::query()
                    ->with('order:id,payment_method')
                    ->where('store_id', $refund->store_id)
                    ->lockForUpdate()
                    ->find($refund->order_id);
                $intent = $linkedReturn?->order
                    ? PaymongoIntent::query()->where('payable_type', 'ecommerce_order')
                        ->where('payable_id', $linkedReturn->order_id)
                        ->where('status', 'succeeded')->latest('id')->first()
                    : null;
                $intentPayload = $intent ? $paymongo->retrieveIntent((string) $intent->payment_intent_id) : [];
                $paymentId = data_get($intentPayload, 'data.attributes.payments.0.id');
                if (!$paymentId) {
                    throw ValidationException::withMessages([
                        'provider' => ['This order has no successful PayMongo payment to refund. Use Manual payout and enter the payout reference.'],
                    ]);
                }
                $paymongoResponse = $paymongo->createRefund((string) $paymentId, (int) round((float) $refund->amount * 100));
                if (data_get($paymongoResponse, 'errors')) {
                    throw ValidationException::withMessages([
                        'provider' => [data_get($paymongoResponse, 'errors.0.detail', 'PayMongo rejected the refund.')],
                    ]);
                }
                $paymongoRefundId = data_get($paymongoResponse, 'data.id');
                $payoutReference = (string) ($paymongoRefundId ?: $intent->payment_intent_id);
            } elseif ($payoutProvider === 'manual' && $payoutReference === '') {
                throw ValidationException::withMessages(['payout_reference' => ['Enter the manual payout or transaction reference.']]);
            }

            try {
                $cashflow->debit(
                    (int) $refund->store_id,
                    (float) $refund->amount,
                    'finance_refund',
                    (int) $refund->id,
                    $request->user()->id,
                    'Refund sent to ' . $refund->refund_account_name,
                    $refund->refund_method,
                    ['payout_reference' => $data['payout_reference']]
                );
            } catch (\RuntimeException $e) {
                throw ValidationException::withMessages(['amount' => [$e->getMessage()]]);
            }

            $refund->update([
                'status' => 'sent',
                'payout_reference' => $payoutReference,
                'payout_provider' => $payoutProvider,
                'paymongo_refund_id' => $paymongoRefundId,
                'sent_by' => $request->user()->id,
                'sent_at' => now(),
                'notes' => $data['notes'] ?? $refund->notes,
            ]);

            if ($refund->order_type === 'ecommerce_return') {
                $linkedReturn = EcommerceOrderReturn::query()
                    ->where('store_id', $refund->store_id)
                    ->lockForUpdate()
                    ->find($refund->order_id);
                if ($linkedReturn && in_array($linkedReturn->status, ['received', 'refund_pending'], true)) {
                    $linkedReturn->update(['status' => 'refunded', 'resolved_at' => now()]);
                    $linkedReturn->order()->update(['status' => 'refunded', 'payment_status' => 'refunded']);
                }
            }
        });

        if ($linkedReturn?->user_id) {
            $this->notify((int) $linkedReturn->user_id, [
                'module' => 'ecommerce',
                'entity_type' => 'ecommerce_order_return',
                'entity_id' => (int) $linkedReturn->id,
                'action' => 'refund_sent',
                'title' => 'Refund sent',
                'message' => 'Finance sent your refund to your selected ' . strtoupper((string) $refund->refund_method) . ' account.',
                'severity' => 'success',
                'store_id' => (int) $refund->store_id,
                'link' => '/orders/' . (int) $linkedReturn->order_id,
                'data' => ['payout_reference' => $data['payout_reference']],
            ]);
        }

        return response()->json(['message' => 'Refund marked as sent to the customer.', 'data' => $refund->fresh()]);
    }

    private function authorizeStore(Request $request, FinanceRefund $refund): void
    {
        abort_unless((int) $refund->store_id === (int) $request->user()->store_id, 403, 'Unauthorized access to refund.');
    }
}
