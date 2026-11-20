<?php
declare(strict_types=1);

namespace FinancingBridge\Store;

/** Someone else (a webhook, another admin) changed the order first. */
final class ConcurrentUpdate extends \RuntimeException
{
}
