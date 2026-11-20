<?php
declare(strict_types=1);

namespace FinancingBridge\Http;

use FinancingBridge\Domain\InvalidTransition;
use FinancingBridge\Service\FinancingError;
use FinancingBridge\Service\FinancingService;
use FinancingBridge\Store\Order;
use FinancingBridge\Store\OrderRepository;

/**
 * Staff-facing REST API for the checkout or back office:
 *
 *   POST /financing/v1/orders/{ref}/verify          {amount, currency, approval_code, customer_phone}
 *   POST /financing/v1/orders/{ref}/purchase-order  {items: [...]}
 *   POST /financing/v1/orders/{ref}/deliver         {delivery_code}
 *   POST /financing/v1/orders/{ref}/cancel          {reason}
 *   GET  /financing/v1/orders/{ref}
 *
 * Requires the finb_manage capability (given to administrators on activation).
 */
final class OrdersController
{
    public function __construct(private readonly FinancingService $service, private readonly OrderRepository $orders)
    {
    }

    public function register(): void
    {
        $ref = '(?P<ref>[A-Za-z0-9._-]{1,64})';
        $perm = static fn (): bool => current_user_can('finb_manage');
        $routes = [
            'verify' => fn (\WP_REST_Request $r) => $this->service->verifyApproval(
                $r['ref'],
                (int) $r['amount'],
                (string) ($r['currency'] ?? 'EGP'),
                (string) $r['approval_code'],
                (string) $r['customer_phone']
            ),
            'purchase-order' => fn (\WP_REST_Request $r) => $this->service->createPurchaseOrder($r['ref'], FinancingService::items($r['items'])),
            'deliver' => fn (\WP_REST_Request $r) => $this->service->confirmDelivery($r['ref'], (string) $r['delivery_code']),
            'cancel' => fn (\WP_REST_Request $r) => $this->service->cancel($r['ref'], (string) ($r['reason'] ?? 'merchant cancelled')),
        ];
        foreach ($routes as $action => $fn) {
            register_rest_route('financing/v1', "/orders/{$ref}/{$action}", [
                'methods' => 'POST',
                'permission_callback' => $perm,
                'callback' => fn (\WP_REST_Request $r) => $this->respond(static fn () => $fn($r)),
            ]);
        }
        register_rest_route('financing/v1', "/orders/{$ref}", [
            'methods' => 'GET',
            'permission_callback' => $perm,
            'callback' => function (\WP_REST_Request $r): \WP_REST_Response {
                $order = $this->orders->findByRef((string) $r['ref']);
                return $order === null
                    ? new \WP_REST_Response(['error' => 'unknown_order'], 404)
                    : new \WP_REST_Response(self::export($order), 200);
            },
        ]);
    }

    private function respond(callable $action): \WP_REST_Response
    {
        try {
            return new \WP_REST_Response(self::export($action()), 200);
        } catch (FinancingError $e) {
            return new \WP_REST_Response(['error' => $e->errorCode, 'message' => $e->getMessage()], $e->httpStatus);
        } catch (InvalidTransition $e) {
            return new \WP_REST_Response(['error' => 'wrong_state', 'message' => $e->getMessage()], 409);
        }
    }

    /** @return array<string, mixed> */
    private static function export(Order $o): array
    {
        return [
            'order' => $o->merchantOrderRef,
            'state' => $o->state->value,
            'amount' => $o->amountMinor,
            'currency' => $o->currency,
            'po_id' => $o->poId,
            'delivery_attempts' => $o->deliveryAttempts,
            'review_reason' => $o->reviewReason,
        ];
    }
}
