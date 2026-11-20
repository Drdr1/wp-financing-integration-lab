<?php
declare(strict_types=1);

namespace FinancingBridge\Domain;

final class StateMachine
{
    /** @var array<string, list<State>> */
    private const ALLOWED = [
        'new' => [State::Approved],
        'approved' => [State::PendingDelivery, State::Cancelled, State::NeedsReview],
        'pending_delivery' => [State::Delivered, State::Cancelled, State::Expired, State::NeedsReview],
        'needs_review' => [State::Delivered, State::Cancelled, State::Expired],
        'delivered' => [],
        'cancelled' => [],
        'expired' => [],
    ];

    public static function canMove(State $from, State $to): bool
    {
        return in_array($to, self::ALLOWED[$from->value], true);
    }

    public static function assertMove(State $from, State $to): void
    {
        if (!self::canMove($from, $to)) {
            throw new InvalidTransition($from, $to);
        }
    }

    /**
     * What a platform event should do to an order in a given state.
     *
     * Returns the target state, null when the event is already reflected
     * (a duplicate or a late confirmation), or NeedsReview when the event
     * contradicts a final state the store already recorded.
     */
    public static function forEvent(State $current, State $eventTarget): ?State
    {
        if ($current === $eventTarget || $current === State::NeedsReview) {
            return null; // review is resolved by a person, not by the next event
        }
        if (self::canMove($current, $eventTarget)) {
            return $eventTarget;
        }
        return $current->isFinal() ? State::NeedsReview : null;
    }
}
