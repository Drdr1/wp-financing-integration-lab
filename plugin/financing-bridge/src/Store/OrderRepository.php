<?php
declare(strict_types=1);

namespace FinancingBridge\Store;

use FinancingBridge\Domain\State;
use FinancingBridge\Domain\StateMachine;

/**
 * Orders with optimistic locking.
 *
 * Every state change is an UPDATE ... WHERE id = ? AND state = ? AND
 * version = ?. If a webhook and an admin act on the same order at the same
 * moment, exactly one update matches; the other gets ConcurrentUpdate and
 * re-reads, instead of silently overwriting what the first one did.
 */
final class OrderRepository
{
    private function table(): string
    {
        global $wpdb;
        return $wpdb->prefix . 'finb_orders';
    }

    public function create(string $merchantOrderRef, int $amountMinor, string $currency): Order
    {
        global $wpdb;
        $now = current_time('mysql', true);
        $wpdb->suppress_errors(true);
        $ok = $wpdb->insert($this->table(), [
            'merchant_order_ref' => $merchantOrderRef,
            'state' => State::New->value,
            'amount_minor' => $amountMinor,
            'currency' => $currency,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        $wpdb->suppress_errors(false);
        if ($ok === false) {
            $existing = $this->findByRef($merchantOrderRef);
            if ($existing !== null) {
                return $existing;
            }
            throw new \RuntimeException('Could not create order: ' . $wpdb->last_error);
        }
        return $this->get((int) $wpdb->insert_id);
    }

    public function get(int $id): Order
    {
        global $wpdb;
        $row = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$this->table()} WHERE id = %d", $id), ARRAY_A);
        if (!is_array($row)) {
            throw new \OutOfBoundsException("Order {$id} not found.");
        }
        return Order::fromRow($row);
    }

    public function findByRef(string $ref): ?Order
    {
        global $wpdb;
        $row = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$this->table()} WHERE merchant_order_ref = %s", $ref), ARRAY_A);
        return is_array($row) ? Order::fromRow($row) : null;
    }

    public function findByPo(string $poId): ?Order
    {
        global $wpdb;
        $row = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$this->table()} WHERE po_id = %s", $poId), ARRAY_A);
        return is_array($row) ? Order::fromRow($row) : null;
    }

    /** @return list<Order> */
    public function openWithPo(int $limit = 100): array
    {
        global $wpdb;
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM {$this->table()} WHERE state = %s AND po_id IS NOT NULL ORDER BY updated_at LIMIT %d",
            State::PendingDelivery->value,
            $limit
        ), ARRAY_A) ?: [];
        return array_values(array_map([Order::class, 'fromRow'], $rows));
    }

    /** @return list<Order> */
    public function recent(int $limit = 50): array
    {
        global $wpdb;
        $rows = $wpdb->get_results($wpdb->prepare("SELECT * FROM {$this->table()} ORDER BY id DESC LIMIT %d", $limit), ARRAY_A) ?: [];
        return array_values(array_map([Order::class, 'fromRow'], $rows));
    }

    /**
     * Move an order to a new state, optionally setting fields, if and only if
     * nobody changed it since it was read.
     *
     * @param array<string, scalar|null> $fields
     */
    public function transition(Order $order, State $to, array $fields = []): Order
    {
        StateMachine::assertMove($order->state, $to);
        return $this->update($order, ['state' => $to->value] + $fields);
    }

    /**
     * Update fields without a state change (e.g. counting a failed delivery code).
     *
     * @param array<string, scalar|null> $fields
     */
    public function update(Order $order, array $fields): Order
    {
        global $wpdb;
        $allowed = ['state', 'approval_id', 'customer_ref', 'po_id', 'delivery_attempts', 'review_reason'];
        if (array_diff(array_keys($fields), $allowed)) {
            throw new \InvalidArgumentException('Unknown order field.');
        }
        $fields['version'] = $order->version + 1;
        $fields['updated_at'] = current_time('mysql', true);
        $changed = $wpdb->update(
            $this->table(),
            $fields,
            ['id' => $order->id, 'state' => $order->state->value, 'version' => $order->version]
        );
        if ($changed !== 1) {
            throw new ConcurrentUpdate("Order {$order->merchantOrderRef} changed while it was being updated.");
        }
        return $this->get($order->id);
    }

    /** Record a webhook event ID. Returns false if it was already seen (a replay or retry). */
    public function recordEvent(string $eventId, string $type, string $poId, string $outcome): bool
    {
        global $wpdb;
        $wpdb->suppress_errors(true);
        $ok = $wpdb->insert($wpdb->prefix . 'finb_events', [
            'event_id' => $eventId,
            'type' => $type,
            'po_id' => $poId,
            'outcome' => $outcome,
            'received_at' => current_time('mysql', true),
        ]);
        $wpdb->suppress_errors(false);
        return $ok === 1;
    }

    public function setEventOutcome(string $eventId, string $outcome): void
    {
        global $wpdb;
        $wpdb->update($wpdb->prefix . 'finb_events', ['outcome' => $outcome], ['event_id' => $eventId]);
    }
}
