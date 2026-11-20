<?php
declare(strict_types=1);

namespace FinancingBridge\Api;

/**
 * Typed client for the financing platform's REST API (v1).
 *
 * Every write carries an Idempotency-Key that the caller derives from the
 * store order, so the same business action always sends the same key: a
 * retry, a double click, or a second worker produce one platform action.
 */
final class Client
{
    public function __construct(
        private readonly Transport $transport,
        private readonly string $baseUrl,
        private readonly string $apiKey,
        private readonly RetryPolicy $retry = new RetryPolicy(),
        private readonly int $timeoutSeconds = 15,
    ) {
    }

    /** @return array{approval_id: string, status: string, available_limit: int, currency: string, expires_at: string, customer_ref: string} */
    public function verifyApproval(string $approvalCode, string $customerPhone, string $idempotencyKey): array
    {
        /** @var array{approval_id: string, status: string, available_limit: int, currency: string, expires_at: string, customer_ref: string} */
        return $this->request('POST', '/v1/approvals/verify', [
            'approval_code' => $approvalCode,
            'customer_phone' => $customerPhone,
        ], $idempotencyKey);
    }

    /**
     * @param list<array{sku: string, name: string, quantity: int, unit_price: int}> $items
     * @return array{po_id: string, status: string}
     */
    public function createPurchaseOrder(string $approvalId, string $merchantOrderRef, int $amountMinor, string $currency, array $items, string $idempotencyKey): array
    {
        /** @var array{po_id: string, status: string} */
        return $this->request('POST', '/v1/purchase-orders', [
            'approval_id' => $approvalId,
            'merchant_order_ref' => $merchantOrderRef,
            'amount' => $amountMinor,
            'currency' => $currency,
            'items' => $items,
        ], $idempotencyKey);
    }

    /** @return array{po_id: string, status: string} */
    public function confirmDelivery(string $poId, string $deliveryCode, string $idempotencyKey): array
    {
        /** @var array{po_id: string, status: string} */
        return $this->request('POST', '/v1/purchase-orders/' . rawurlencode($poId) . '/deliver', [
            'delivery_code' => $deliveryCode,
        ], $idempotencyKey);
    }

    /** @return array{po_id: string, status: string} */
    public function cancelPurchaseOrder(string $poId, string $reason, string $idempotencyKey): array
    {
        /** @var array{po_id: string, status: string} */
        return $this->request('POST', '/v1/purchase-orders/' . rawurlencode($poId) . '/cancel', [
            'reason' => $reason,
        ], $idempotencyKey);
    }

    /** @return array{po_id: string, status: string} */
    public function getPurchaseOrder(string $poId): array
    {
        /** @var array{po_id: string, status: string} */
        return $this->request('GET', '/v1/purchase-orders/' . rawurlencode($poId));
    }

    /**
     * @param array<string, mixed>|null $payload
     * @return array<string, mixed>
     */
    private function request(string $method, string $path, ?array $payload = null, ?string $idempotencyKey = null): array
    {
        $safeToRetry = $method === 'GET' || $idempotencyKey !== null;
        $headers = [
            'Authorization' => 'Bearer ' . $this->apiKey,
            'Accept' => 'application/json',
        ];
        $body = null;
        if ($payload !== null) {
            $headers['Content-Type'] = 'application/json';
            $body = json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
        }
        if ($idempotencyKey !== null) {
            $headers['Idempotency-Key'] = $idempotencyKey;
        }

        $url = rtrim($this->baseUrl, '/') . $path;
        for ($attempt = 1; ; $attempt++) {
            try {
                $response = $this->transport->send($method, $url, $headers, $body, $this->timeoutSeconds);
            } catch (TransportFailure $e) {
                $error = new ApiError('network_error', 0, 'No response from the financing platform: ' . $e->getMessage());
                if ($safeToRetry && $attempt < $this->retry->maxAttempts) {
                    $this->retry->wait($attempt);
                    continue;
                }
                throw $error;
            }

            if ($response->status >= 200 && $response->status < 300) {
                return $response->json();
            }

            $error = ApiError::fromResponse($response);
            if ($error->isRetryable() && $safeToRetry && $attempt < $this->retry->maxAttempts) {
                $this->retry->wait($attempt, $response->headers['retry-after'] ?? null);
                continue;
            }
            throw $error;
        }
    }
}
