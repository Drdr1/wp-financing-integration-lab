<?php
declare(strict_types=1);

namespace FinancingBridge\Cli;

use FinancingBridge\Domain\InvalidTransition;
use FinancingBridge\Service\FinancingError;
use FinancingBridge\Service\FinancingService;
use FinancingBridge\Store\AuditLog;
use FinancingBridge\Store\Order;
use FinancingBridge\Store\OrderRepository;

/**
 * Operate the integration from the command line (support, testing, UAT).
 *
 * ## EXAMPLES
 *
 *     wp financing verify ORD-1001 --amount=1500000 --code=482913 --phone=01012345678
 *     wp financing po ORD-1001 --items='[{"sku":"TV-55","name":"TV","quantity":1,"unit_price":1500000}]'
 *     wp financing deliver ORD-1001 --code=7731
 *     wp financing cancel ORD-1001 --reason="customer request"
 *     wp financing reconcile
 *     wp financing show ORD-1001
 */
final class Command
{
    public function __construct(
        private readonly FinancingService $service,
        private readonly OrderRepository $orders,
        private readonly AuditLog $log,
    ) {
    }

    /**
     * Verify the customer's approval code for an order.
     *
     * <order>
     * : Merchant order reference.
     *
     * --amount=<minor>
     * : Order total in minor units (piastres).
     *
     * --code=<approval_code>
     * : Approval code from the customer.
     *
     * --phone=<phone>
     * : Customer phone registered with the financing platform.
     *
     * [--currency=<currency>]
     * : ISO currency. Default EGP.
     *
     * @param list<string> $args
     * @param array<string, string> $assoc
     */
    public function verify(array $args, array $assoc): void
    {
        $this->run(fn () => $this->service->verifyApproval($args[0], (int) $assoc['amount'], $assoc['currency'] ?? 'EGP', $assoc['code'], $assoc['phone']));
    }

    /**
     * Create the purchase order (the platform then texts the customer a delivery code).
     *
     * <order>
     * : Merchant order reference.
     *
     * --items=<json>
     * : JSON list of {sku, name, quantity, unit_price}.
     *
     * @param list<string> $args
     * @param array<string, string> $assoc
     */
    public function po(array $args, array $assoc): void
    {
        $this->run(fn () => $this->service->createPurchaseOrder($args[0], FinancingService::items(json_decode($assoc['items'], true))));
    }

    /**
     * Confirm delivery with the code the customer received.
     *
     * <order>
     * : Merchant order reference.
     *
     * --code=<delivery_code>
     * : Delivery code read out by the customer.
     *
     * @param list<string> $args
     * @param array<string, string> $assoc
     */
    public function deliver(array $args, array $assoc): void
    {
        $this->run(fn () => $this->service->confirmDelivery($args[0], $assoc['code']));
    }

    /**
     * Cancel an undelivered order.
     *
     * <order>
     * : Merchant order reference.
     *
     * [--reason=<reason>]
     * : Reason sent to the platform.
     *
     * @param list<string> $args
     * @param array<string, string> $assoc
     */
    public function cancel(array $args, array $assoc): void
    {
        $this->run(fn () => $this->service->cancel($args[0], $assoc['reason'] ?? 'merchant cancelled'));
    }

    /**
     * Ask the platform for the status of every order awaiting delivery.
     *
     * @param list<string> $args
     */
    public function reconcile(array $args): void
    {
        $results = $this->service->reconcile();
        \WP_CLI::line((string) wp_json_encode($results));
    }

    /**
     * Show an order and its audit trail as JSON.
     *
     * <order>
     * : Merchant order reference.
     *
     * @param list<string> $args
     */
    public function show(array $args): void
    {
        $order = $this->orders->findByRef($args[0]);
        if ($order === null) {
            \WP_CLI::error('unknown_order');
            return;
        }
        \WP_CLI::line((string) wp_json_encode($this->export($order) + ['log' => $this->log->forOrder($order->id)]));
    }

    private function run(callable $action): void
    {
        try {
            $order = $action();
        } catch (FinancingError $e) {
            \WP_CLI::line((string) wp_json_encode(['ok' => false, 'error' => $e->errorCode, 'message' => $e->getMessage()]));
            \WP_CLI::halt(1);
        } catch (InvalidTransition $e) {
            \WP_CLI::line((string) wp_json_encode(['ok' => false, 'error' => 'wrong_state', 'message' => $e->getMessage()]));
            \WP_CLI::halt(1);
        }
        \WP_CLI::line((string) wp_json_encode(['ok' => true] + $this->export($order)));
    }

    /** @return array<string, mixed> */
    private function export(Order $o): array
    {
        return [
            'order' => $o->merchantOrderRef,
            'state' => $o->state->value,
            'amount' => $o->amountMinor,
            'currency' => $o->currency,
            'approval_id' => $o->approvalId,
            'po_id' => $o->poId,
            'delivery_attempts' => $o->deliveryAttempts,
            'review_reason' => $o->reviewReason,
        ];
    }
}
