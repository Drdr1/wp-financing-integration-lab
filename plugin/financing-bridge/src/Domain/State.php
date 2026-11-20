<?php
declare(strict_types=1);

namespace FinancingBridge\Domain;

/**
 * Lifecycle of one store order paid through the financing platform.
 *
 *   new -> approved -> pending_delivery -> delivered
 *            |              |-> cancelled
 *            |              |-> expired
 *            |-> cancelled
 *
 * needs_review is where an order goes when the platform and the store
 * disagree (for example a "cancelled" event for an order already delivered,
 * or the customer locking the delivery code). A person decides; code never
 * guesses with money.
 */
enum State: string
{
    case New = 'new';
    case Approved = 'approved';
    case PendingDelivery = 'pending_delivery';
    case Delivered = 'delivered';
    case Cancelled = 'cancelled';
    case Expired = 'expired';
    case NeedsReview = 'needs_review';

    public function isFinal(): bool
    {
        return in_array($this, [self::Delivered, self::Cancelled, self::Expired], true);
    }
}
