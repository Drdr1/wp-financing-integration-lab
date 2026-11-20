<?php
declare(strict_types=1);

namespace FinancingBridge\Security;

/**
 * Webhook signatures: X-Fin-Signature: sha256=<hex HMAC of "timestamp.body">.
 *
 * The timestamp is part of what is signed, so an attacker cannot replay an
 * old, validly signed body with a fresh timestamp, and requests older than
 * the tolerance window are refused. Event IDs are also stored, so a replay
 * inside the window is a no-op.
 */
final class Signature
{
    public const TOLERANCE_SECONDS = 300;

    public function __construct(private readonly string $secret)
    {
        if (strlen($secret) < 32) {
            throw new \InvalidArgumentException('Webhook secret must be at least 32 characters.');
        }
    }

    public function sign(string $timestamp, string $body): string
    {
        return 'sha256=' . hash_hmac('sha256', $timestamp . '.' . $body, $this->secret);
    }

    public function verify(string $timestamp, string $body, string $header, ?int $now = null): SignatureResult
    {
        $now ??= time();
        if (!ctype_digit($timestamp)) {
            return SignatureResult::Malformed;
        }
        if (abs($now - (int) $timestamp) > self::TOLERANCE_SECONDS) {
            return SignatureResult::Stale;
        }
        if (!str_starts_with($header, 'sha256=')) {
            return SignatureResult::Malformed;
        }
        // hash_equals: constant-time, so the signature can't be guessed byte by byte.
        return hash_equals($this->sign($timestamp, $body), $header) ? SignatureResult::Valid : SignatureResult::Invalid;
    }
}
