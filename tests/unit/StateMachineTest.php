<?php
declare(strict_types=1);

use FinancingBridge\Domain\InvalidTransition;
use FinancingBridge\Domain\State;
use FinancingBridge\Domain\StateMachine;
use PHPUnit\Framework\TestCase;

final class StateMachineTest extends TestCase
{
    public function testHappyPathIsAllowed(): void
    {
        self::assertTrue(StateMachine::canMove(State::New, State::Approved));
        self::assertTrue(StateMachine::canMove(State::Approved, State::PendingDelivery));
        self::assertTrue(StateMachine::canMove(State::PendingDelivery, State::Delivered));
    }

    public function testFinalStatesNeverMove(): void
    {
        foreach ([State::Delivered, State::Cancelled, State::Expired] as $final) {
            foreach (State::cases() as $to) {
                self::assertFalse(StateMachine::canMove($final, $to), "{$final->value} -> {$to->value}");
            }
        }
    }

    public function testSkippingStepsIsRefused(): void
    {
        $this->expectException(InvalidTransition::class);
        StateMachine::assertMove(State::New, State::Delivered);
    }

    public function testDuplicateEventIsANoOp(): void
    {
        self::assertNull(StateMachine::forEvent(State::Delivered, State::Delivered));
    }

    public function testEventMovesAnOpenOrder(): void
    {
        self::assertSame(State::Cancelled, StateMachine::forEvent(State::PendingDelivery, State::Cancelled));
    }

    public function testContradictingEventOnAFinalOrderNeedsReview(): void
    {
        self::assertSame(State::NeedsReview, StateMachine::forEvent(State::Delivered, State::Cancelled));
        self::assertSame(State::NeedsReview, StateMachine::forEvent(State::Cancelled, State::Delivered));
    }

    public function testEventsDoNotResolveAReview(): void
    {
        self::assertNull(StateMachine::forEvent(State::NeedsReview, State::Delivered));
    }

    public function testEventForAnOrderWithoutPoIsIgnored(): void
    {
        self::assertNull(StateMachine::forEvent(State::New, State::Delivered));
    }
}
