<?php

namespace App\Services\Payment;

use App\Models\PaymongoIntent;
use Carbon\Carbon;
use GuzzleHttp\Client;
use Illuminate\Support\Facades\Log;

class PaymongoService
{
    protected Client $client;

    public function __construct()
    {
        $verify = true;
        $certDir = storage_path('certs');
        $certPath = $certDir . DIRECTORY_SEPARATOR . 'cacert.pem';

        // Ensure cert directory exists
        if (!is_dir($certDir)) {
            @mkdir($certDir, 0755, true);
        }

        // If cert missing, attempt to download the CA bundle (best-effort)
        if (!file_exists($certPath)) {
            try {
                $remote = 'https://curl.se/ca/cacert.pem';
                $contents = @file_get_contents($remote);
                if ($contents && strlen($contents) > 1000) {
                    @file_put_contents($certPath, $contents);
                }
            } catch (\Throwable $e) {
                // ignore download failures; fallback to system CA verification
                Log::warning('PaymongoService: failed to auto-download cacert.pem: ' . $e->getMessage());
            }
        }

        if (file_exists($certPath)) {
            $verify = $certPath;
        }

        $this->client = new Client([
            'base_uri' => config('paymongo.endpoint'),
            'auth' => [config('paymongo.secret'), ''],
            'http_errors' => false,
            'verify' => $verify,
        ]);
    }

    public function isConfigured(): bool
    {
        $secret = trim((string) config('paymongo.secret'));
        $public = trim((string) config('paymongo.public'));

        return preg_match('/^sk_(test|live)_/', $secret) === 1
            && preg_match('/^pk_(test|live)_/', $public) === 1;
    }

    public function createIntent(array $payload): array
    {
        try {
            $response = $this->client->post('/payment_intents', [
                'json' => $payload,
            ]);

            return json_decode($response->getBody()->getContents(), true);
        } catch (\Throwable $e) {
            Log::error('PaymongoService: createIntent exception', ['error' => $e->getMessage()]);
            return [
                'errors' => [[
                    'detail' => 'Unable to connect to Online Payment. Please try again later.',
                ]],
            ];
        }
    }

    public function retrieveIntent(string $intentId): array
    {
        try {
            $response = $this->client->get("/payment_intents/{$intentId}");
            return json_decode($response->getBody()->getContents(), true);
        } catch (\Throwable $e) {
            Log::warning('PaymongoService: retrieveIntent exception', [
                'intent_id' => $intentId,
                'error' => $e->getMessage(),
            ]);

            return [
                'errors' => [[
                    'detail' => 'Unable to connect to Online Payment. Please try again later.',
                ]],
            ];
        }
    }

    public function createRefund(string $paymentId, int $amount, string $reason = 'requested_by_customer'): array
    {
        try {
            $response = $this->client->post('/refunds', [
                'json' => ['data' => ['attributes' => [
                    'amount' => $amount,
                    'currency' => 'PHP',
                    'payment_id' => $paymentId,
                    'reason' => $reason,
                ]]],
            ]);
            $status = (int) $response->getStatusCode();
            $decoded = json_decode($response->getBody()->getContents(), true) ?: [];
            $decoded['_http_status'] = $status;
            return $decoded;
        } catch (\Throwable $e) {
            Log::error('PaymongoService: createRefund exception', ['payment_id' => $paymentId, 'error' => $e->getMessage()]);
            return ['errors' => [['detail' => 'Unable to connect to PayMongo for the refund.']], '_http_status' => 500];
        }
    }

    public function createPaymentMethod(array $payload): array
    {
        try {
            $response = $this->client->post('/payment_methods', [
                'json' => $payload,
            ]);

            return json_decode($response->getBody()->getContents(), true);
        } catch (\Throwable $e) {
            Log::error('PaymongoService: createPaymentMethod exception', ['error' => $e->getMessage()]);
            return [
                'errors' => [[
                    'detail' => 'Unable to connect to Online Payment. Please try again later.',
                ]],
            ];
        }
    }

    public function attachIntent(string $intentId, array $payload): array
    {
        try {
            $response = $this->client->post("/payment_intents/{$intentId}/attach", [
                'json' => $payload,
            ]);

            return json_decode($response->getBody()->getContents(), true);
        } catch (\Throwable $e) {
            Log::warning('PaymongoService: attachIntent exception', [
                'intent_id' => $intentId,
                'error' => $e->getMessage(),
            ]);

            return [
                'errors' => [[
                    'detail' => 'Unable to connect to Online Payment. Please try again later.',
                ]],
            ];
        }
    }

    public function createCheckoutSession(array $payload): array
    {
        try {
            $response = $this->client->post('/checkout_sessions', [
                'json' => $payload,
            ]);

            $status = (int) $response->getStatusCode();
            $body = (string) $response->getBody()->getContents();
            $decoded = json_decode($body, true);

            if ($status >= 400) {
                Log::error('PaymongoService: createCheckoutSession failed', [
                    'http_status' => $status,
                    'errors' => data_get($decoded, 'errors', []),
                    'response_body' => $body,
                    'payload' => $payload,
                ]);
            }

            return $decoded;
        } catch (\Throwable $e) {
            Log::error('PaymongoService: createCheckoutSession exception', [
                'error' => $e->getMessage(),
                'payload' => $payload,
            ]);
            return [
                'errors' => [[
                    'detail' => 'Unable to connect to Online Payment. Please try again later.',
                ]],
            ];
        }
    }

    public function retrieveCheckoutSession(string $sessionId): array
    {
        try {
            $response = $this->client->get("/checkout_sessions/{$sessionId}");
            $status = (int) $response->getStatusCode();
            $body = (string) $response->getBody()->getContents();
            $decoded = json_decode($body, true);

            if ($status >= 400) {
                Log::error('PaymongoService: retrieveCheckoutSession failed', [
                    'http_status' => $status,
                    'session_id' => $sessionId,
                    'errors' => data_get($decoded, 'errors', []),
                    'response_body' => $body,
                ]);
            }

            return $decoded;
        } catch (\Throwable $e) {
            Log::warning('PaymongoService: retrieveCheckoutSession exception', [
                'session_id' => $sessionId,
                'error' => $e->getMessage(),
            ]);

            return [
                'errors' => [[
                    'detail' => 'Unable to connect to Online Payment. Please try again later.',
                ]],
            ];
        }
    }

    public function verifySignature(string $payload, string $signature): bool
    {
        if (!$signature || !config('paymongo.webhook_secret')) {
            return false;
        }

        // Online Payment-Signature format: "t=timestamp,te=v1_signature"
        $segments = collect(explode(',', $signature))
            ->mapWithKeys(function (string $part) {
                [$key, $value] = array_pad(explode('=', trim($part), 2), 2, null);
                return $key ? [$key => $value] : [];
            });

        $timestamp = (string) ($segments['t'] ?? '');
        $te = (string) ($segments['te'] ?? '');
        if (!$timestamp || !$te) {
            return false;
        }

        $signedPayload = "{$timestamp}.{$payload}";
        $computed = hash_hmac('sha256', $signedPayload, (string) config('paymongo.webhook_secret'));

        return hash_equals($computed, $te);
    }

    public function logIntent(array $attributes): PaymongoIntent
    {
        $intent = PaymongoIntent::firstOrNew(['payment_intent_id' => $attributes['payment_intent_id']]);
        $intent->fill($attributes);
        $intent->webhook_payload = $attributes['webhook_payload'] ?? null;
        $intent->save();
        return $intent;
    }

    public function updateStatusFromWebhook(array $event): ?PaymongoIntent
    {
        $type = (string) data_get($event, 'data.attributes.type', '');
        $resource = data_get($event, 'data.attributes.data', []);

        // payment.paid and payment.failed wrap the payment resource.
        $intentId = data_get($resource, 'attributes.payment_intent_id')
            ?? data_get($resource, 'attributes.payment_intent.id')
            ?? data_get($resource, 'id')
            ?? data_get($event, 'data.id');

        if (!$intentId) {
            return null;
        }

        $existing = PaymongoIntent::query()->where('payment_intent_id', $intentId)->first();
        if (!$existing && (!data_get($resource, 'attributes.metadata.payable_type') || !data_get($resource, 'attributes.metadata.payable_id'))) {
            Log::warning('Online Payment webhook ignored: missing payable mapping.', [
                'event_type' => $type,
                'intent_id' => $intentId,
            ]);
            return null;
        }

        $statusFromEvent = match ($type) {
            'payment.paid' => 'succeeded',
            'payment.failed' => 'failed',
            default => data_get($resource, 'attributes.status') ?? data_get($event, 'data.attributes.status'),
        };

        return $this->logIntent([
            'store_id' => $existing?->store_id,
            'payment_intent_id' => $intentId,
            'status' => $statusFromEvent ?? $existing?->status,
            'amount' => data_get($resource, 'attributes.amount') ?? $existing?->amount ?? 0,
            'currency' => data_get($resource, 'attributes.currency') ?? $existing?->currency ?? 'PHP',
            'client_key' => data_get($resource, 'attributes.client_key') ?? $existing?->client_key,
            'description' => data_get($resource, 'attributes.description') ?? $existing?->description,
            'statement_descriptor' => data_get($resource, 'attributes.statement_descriptor') ?? $existing?->statement_descriptor,
            'payment_method_allowed' => $existing?->payment_method_allowed,
            'payment_method_id' => data_get($resource, 'attributes.payment_method_id') ?? $existing?->payment_method_id,
            'metadata' => data_get($resource, 'attributes.metadata') ?? $existing?->metadata,
            'payable_type' => $existing?->payable_type ?? data_get($resource, 'attributes.metadata.payable_type'),
            'payable_id' => $existing?->payable_id ?? data_get($resource, 'attributes.metadata.payable_id'),
            'webhook_payload' => $event,
            'updated_at' => Carbon::now(),
        ]);
    }
}
