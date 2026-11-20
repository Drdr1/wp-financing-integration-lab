<?php
declare(strict_types=1);

namespace FinancingBridge\Domain;

final class InvalidTransition extends \DomainException
{
    public function __construct(public readonly State $from, public readonly State $to)
    {
        parent::__construct(sprintf('Order cannot move from %s to %s.', $from->value, $to->value));
    }
}
