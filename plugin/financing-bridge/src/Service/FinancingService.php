<?php
declare(strict_types=1);

namespace FinancingBridge\Service;

use FinancingBridge\Api\ApiError;
use FinancingBridge\Api\Client;
use FinancingBridge\Domain\State;
use FinancingBridge\Domain\StateMachine;
use FinancingBridge\Store\AuditLog;
use FinancingBridge\Store\ConcurrentUpdate;
use FinancingBridge\Store\Order;
use FinancingBridge\Store\OrderRepository;

/**
 * The four business actions, plus webhook handling and reconciliation.
 *
 * Rule for every action: call the platform first, change local state only
 * after the platform confirmed, and log both sides. If the platform call
 * fails, the order stays exactly where it was.
 */
final class FinancingService
{
    public const MAX_DELIVERY_ATTEMPTS = 3;

    private const EVENT_TARGETS = [
        'purchase_order.delivered' => State::Delivered,
        'purchase_order.cancelled' => State::Cancelled,
        'purchase_order.expired' => State::Expired,
    ];

    public function __construct(
        private readonly Client $api,
        private readonly OrderRepository $orders,
        private readonly AuditLog $log,
    ) {
    }

    /**
     * Validate line items from a request or the CLI before anything is sent.
     *
     * @return list<array{sku: string, name: string, quantity: int, unit_price: int}>
     */
    public static function items(mixed $input): array
    {
        if (!is_array($input) || $input === [] || count($input) > 100) {
            throw new FinancingError('invalid_items', 'Items must be a non-empty list.');
        }
        $items = [];
        foreach (array_values($input) as $i) {
            if (
                !is_array($i)
                || !is_string($i['sku'] ?? null) || $i['sku'] === ''
                || !is_string($i['name'] ?? null)
                || !is_int($i['quantity'] ?? null) || $i['quantity'] < 1
                || !is_int($i['unit_price'] ?? null) || $i['unit_price'] < 0
            ) {
                throw new FinancingError('invalid_items', 'Each item needs sku, name, a positive integer quantity and an integer unit_price in minor units.');
            }
            $items[] = ['sku' => $i['sku'], 'name' => $i['name'], 'quantity' => $i['quantity'], 'unit_price' => $i['unit_price']];
        }
        return $items;
    }

    /** Step 1: the customer gives the approval code from the financing app. */
    public function verifyApproval(string $merchantOrderRef, int $amountMinor, string $currency, string $approvalCode, string $customerPhone): Order
    {
        $order = $this->orders->create($merchantOrderRef, $amountMinor, $currency);
        if ($order->state !== State::New) {
            throw new FinancingError('already_verified', "Order {$merchantOrderRef} is already {$order->state->value}.", 409);
        }
        $ctx = ['approval_code' => $approvalCode, 'customer_phone' => $customerPhone];
        try {
            $approval = $this->api->verifyApproval($approvalCode, $customerPhone, $order->idempotencyKey('verify|' . hash('sha256', $approvalCode)));
        } catch (ApiError $e) {
            $this->log->write($order->id, 'warning', 'Approval code rejected: ' . $e->errorCode, $ctx + ['request_id' => $e->requestId]);
            throw FinancingError::fromApi($e);
        }
        if ($approval['currency'] !== $currency) {
            $this->log->write($order->id, 'warning', 'Approval currency mismatch', ['approval_currency' => $approval['currency']]);
            throw new FinancingError('currency_mismatch', 'The approval is in a different currency.');
        }
        if ($approval['available_limit'] < $amountMinor) {
            $this->log->write($order->id, 'warning', 'Approved limit below order amount', ['limit' => $approval['available_limit'], 'amount' => $amountMinor]);
            throw new FinancingError('limit_exceeded', 'The customer\'s approved limit does not cover this order.');
        }
        $order = $this->orders->transition($order, State::Approved, [
            'approval_id' => $approval['approval_id'],
            'customer_ref' => $approval['customer_ref'],
        ]);
        $this->log->write($order->id, 'info', 'Approval verified', $ctx + ['approval_id' => $approval['approval_id'], 'expires_at' => $approval['expires_at']]);
        return $order;
    }

    /**
     * Step 2: create the purchase order. The platform then sends the customer
     * a delivery code by SMS.
     *
     * @param list<array{sku: string, name: string, quantity: int, unit_price: int}> $items
     */
    public function createPurchaseOrder(string $merchantOrderRef, array $items): Order
    {
        $order = $this->require($merchantOrderRef);
        if ($order->state === State::PendingDelivery) {
            return $order; // already done; the second click changes nothing
        }
        StateMachine::assertMove($order->state, State::PendingDelivery);
        $total = array_sum(array_map(static fn (array $i): int => $i['quantity'] * $i['unit_price'], $items));
        if ($total !== $order->amountMinor) {
            throw new FinancingError('amount_mismatch', "Items total {$total} does not match the order amount {$order->amountMinor}.");
        }
        try {
            $po = $this->api->createPurchaseOrder(
                (string) $order->approvalId,
                $order->merchantOrderRef,
                $order->amountMinor,
                $order->currency,
                $items,
                $order->idempotencyKey('create_po')
            );
        } catch (ApiError $e) {
            $this->log->write($order->id, 'error', 'Purchase order failed: ' . $e->errorCode, ['request_id' => $e->requestId]);
            throw FinancingError::fromApi($e);
        }
        $order = $this->orders->transition($order, State::PendingDelivery, ['po_id' => $po['po_id']]);
        $this->log->write($order->id, 'info', 'Purchase order created', ['po_id' => $po['po_id'], 'amount' => $order->amountMinor]);
        return $order;
    }

    /** Step 3: at handover, the customer reads the delivery code to the courier or store. */
    public function confirmDelivery(string $merchantOrderRef, string $deliveryCode): Order
    {
        $order = $this->require($merchantOrderRef);
        if ($order->state === State::Delivered) {
            return $order;
        }
        if ($order->state !== State::PendingDelivery) {
            throw new FinancingError('wrong_state', "Order is {$order->state->value}, not awaiting delivery.", 409);
        }
        $attempt = $order->deliveryAttempts + 1;
        try {
            $this->api->confirmDelivery((string) $order->poId, $deliveryCode, $order->idempotencyKey('deliver|' . $attempt));
        } catch (ApiError $e) {
            $this->log->write($order->id, 'warning', 'Delivery code rejected: ' . $e->errorCode, ['delivery_code' => $deliveryCode, 'attempt' => $attempt]);
            if ($e->errorCode === 'invalid_delivery_code' || $e->errorCode === 'delivery_code_locked') {
                $order = $this->orders->update($order, ['delivery_attempts' => $attempt]);
                if ($e->errorCode === 'delivery_code_locked' || $attempt >= self::MAX_DELIVERY_ATTEMPTS) {
                    $this->toReview($order, 'Delivery code locked after ' . $attempt . ' failed attempts');
                }
            }
            throw FinancingError::fromApi($e);
        }
        $order = $this->orders->transition($order, State::Delivered, ['delivery_attempts' => $attempt]);
        $this->log->write($order->id, 'info', 'Delivery confirmed', ['po_id' => $order->poId, 'attempt' => $attempt]);
        return $order;
    }

    /** Cancel before delivery. After delivery it is a return, which this lab does not cover. */
    public function cancel(string $merchantOrderRef, string $reason): Order
    {
        $order = $this->require($merchantOrderRef);
        if ($order->state === State::Cancelled) {
            return $order;
        }
        if ($order->state === State::Approved) {
            // No purchase order exists yet, so there is nothing to cancel on the platform.
            $order = $this->orders->transition($order, State::Cancelled);
            $this->log->write($order->id, 'info', 'Cancelled before purchase order', ['reason' => $reason]);
            return $order;
        }
        if ($order->state !== State::PendingDelivery) {
            throw new FinancingError('wrong_state', "Order is {$order->state->value}; only undelivered orders can be cancelled.", 409);
        }
        try {
            $this->api->cancelPurchaseOrder((string) $order->poId, $reason, $order->idempotencyKey('cancel'));
        } catch (ApiError $e) {
            $this->log->write($order->id, 'error', 'Cancellation refused: ' . $e->errorCode, ['request_id' => $e->requestId]);
            throw FinancingError::fromApi($e);
        }
        $order = $this->orders->transition($order, State::Cancelled);
        $this->log->write($order->id, 'info', 'Purchase order cancelled', ['po_id' => $order->poId, 'reason' => $reason]);
        return $order;
    }

    /**
     * Apply a verified webhook event. Returns what happened, for the response
     * and the event table: applied, duplicate, ignored, review, unknown_order.
     *
     * @param array{id: string, type: string, data: array{po_id: string}} $event
     */
    public function applyEvent(array $event): string
    {
        $target = self::EVENT_TARGETS[$event['type']] ?? null;
        $poId = $event['data']['po_id'];
        if (!$this->orders->recordEvent($event['id'], $event['type'], $poId, 'processing')) {
            return 'duplicate';
        }
        $outcome = $this->applyTarget($poId, $target, 'webhook ' . $event['type'] . ' ' . $event['id']);
        $this->orders->setEventOutcome($event['id'], $outcome);
        return $outcome;
    }

    /**
     * Catch up on anything a webhook missed: ask the platform for the status
     * of every order still waiting for delivery. Runs hourly from WP-Cron and
     * on demand from WP-CLI.
     *
     * @return array<string, string> po_id => outcome
     */
    public function reconcile(): array
    {
        $results = [];
        foreach ($this->orders->openWithPo() as $order) {
            try {
                $remote = $this->api->getPurchaseOrder((string) $order->poId);
            } catch (ApiError $e) {
                $results[(string) $order->poId] = 'error:' . $e->errorCode;
                continue;
            }
            $target = State::tryFrom($remote['status']);
            $results[(string) $order->poId] = $target === null
                ? 'ignored'
                : $this->applyTarget((string) $order->poId, $target, 'reconciliation');
        }
        return $results;
    }

    private function applyTarget(string $poId, ?State $target, string $source): string
    {
        if ($target === null) {
            return 'ignored';
        }
        for ($try = 0; $try < 3; $try++) {
            $order = $this->orders->findByPo($poId);
            if ($order === null) {
                return 'unknown_order';
            }
            $next = StateMachine::forEvent($order->state, $target);
            if ($next === null) {
                return 'ignored';
            }
            try {
                if ($next === State::NeedsReview) {
                    $this->toReview($order, "Platform says {$target->value} but the store recorded {$order->state->value} ({$source})");
                    return 'review';
                }
                $order = $this->orders->transition($order, $next);
                $this->log->write($order->id, 'info', "Order {$next->value} from {$source}", ['po_id' => $poId]);
                return 'applied';
            } catch (ConcurrentUpdate) {
                continue; // someone changed it first: re-read and decide again
            }
        }
        return 'conflict';
    }

    private function toReview(Order $order, string $reason): void
    {
        if ($order->state->isFinal()) {
            // A final state is never overwritten; the order is flagged for a person instead.
            $this->orders->update($order, ['review_reason' => $reason]);
        } else {
            $this->orders->transition($order, State::NeedsReview, ['review_reason' => $reason]);
        }
        $this->log->write($order->id, 'error', 'Needs review: ' . $reason);
        do_action('finb_needs_review', $order->merchantOrderRef, $reason);
    }

    private function require(string $merchantOrderRef): Order
    {
        $order = $this->orders->findByRef($merchantOrderRef);
        if ($order === null) {
            throw new FinancingError('unknown_order', "No financing record for order {$merchantOrderRef}.");
        }
        return $order;
    }
}
