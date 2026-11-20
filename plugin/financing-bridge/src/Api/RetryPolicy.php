<?php
declare(strict_types=1);

namespace FinancingBridge\Api;

/**
 * Exponential backoff with full jitter, honouring Retry-After.
 *
 * Only safe requests are retried: reads, and writes that carry an
 * Idempotency-Key, so a retry after a lost response can never create a
 * second purchase order or a second delivery confirmation.
 */
final class RetryPolicy
{
    /** @var callable(int): void */
    private $sleeper;

    /** @param callable(int): void|null $sleeper receives microseconds; injectable for tests */
    public function __construct(
        public readonly int $maxAttempts = 4,
        public readonly int $baseDelayMs = 200,
        public readonly int $maxDelayMs = 4000,
        ?callable $sleeper = null,
    ) {
        $this->sleeper = $sleeper ?? static function (int $us): void {
            usleep($us);
        };
    }

    public function delayMs(int $attempt, ?string $retryAfter = null): int
    {
        if ($retryAfter !== null && ctype_digit($retryAfter)) {
            return min((int) $retryAfter * 1000, $this->maxDelayMs);
        }
        $cap = min($this->maxDelayMs, $this->baseDelayMs * (2 ** ($attempt - 1)));
        return random_int(0, $cap);
    }

    public function wait(int $attempt, ?string $retryAfter = null): void
    {
        ($this->sleeper)($this->delayMs($attempt, $retryAfter) * 1000);
    }
}
