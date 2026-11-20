<?php
declare(strict_types=1);

namespace FinancingBridge\Store;

use FinancingBridge\Domain\State;

final class Order
{
    public function __construct(
        public readonly int $id,
        public readonly string $merchantOrderRef,
        public readonly State $state,
        public readonly int $amountMinor,
        public readonly string $currency,
        public readonly ?string $approvalId,
        public readonly ?string $customerRef,
        public readonly ?string $poId,
        public readonly int $deliveryAttempts,
        public readonly ?string $reviewReason,
        public readonly int $version,
    ) {
    }

    /** @param array<string, mixed> $row */
    public static function fromRow(array $row): self
    {
        return new self(
            (int) $row['id'],
            (string) $row['merchant_order_ref'],
            State::from((string) $row['state']),
            (int) $row['amount_minor'],
            (string) $row['currency'],
            $row['approval_id'] !== null ? (string) $row['approval_id'] : null,
            $row['customer_ref'] !== null ? (string) $row['customer_ref'] : null,
            $row['po_id'] !== null ? (string) $row['po_id'] : null,
            (int) $row['delivery_attempts'],
            $row['review_reason'] !== null ? (string) $row['review_reason'] : null,
            (int) $row['version'],
        );
    }

    /** Stable per order and action: the same action always sends the same key. */
    public function idempotencyKey(string $action): string
    {
        return 'finb-' . hash('sha256', $this->merchantOrderRef . '|' . $action . '|' . ($this->poId ?? $this->approvalId ?? ''));
    }
}
