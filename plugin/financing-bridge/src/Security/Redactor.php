<?php
declare(strict_types=1);

namespace FinancingBridge\Security;

/**
 * Keeps customer data out of logs and the audit trail.
 *
 * Approval codes, delivery codes, phone numbers and national IDs are
 * credentials or personal data: they are masked by key wherever they appear
 * in a context array, and digit runs that look like phones or national IDs
 * are masked inside free text too.
 */
final class Redactor
{
    private const SECRET_KEYS = [
        'approval_code', 'delivery_code', 'otp', 'customer_phone', 'phone', 'mobile',
        'national_id', 'nid', 'authorization', 'api_key', 'password', 'secret',
    ];

    /**
     * @param array<mixed> $context
     * @return array<mixed>
     */
    public static function context(array $context): array
    {
        $out = [];
        foreach ($context as $key => $value) {
            if (is_string($key) && in_array(strtolower($key), self::SECRET_KEYS, true)) {
                $out[$key] = self::mask(is_scalar($value) ? (string) $value : '');
            } elseif (is_array($value)) {
                $out[$key] = self::context($value);
            } elseif (is_string($value)) {
                $out[$key] = self::text($value);
            } else {
                $out[$key] = $value;
            }
        }
        return $out;
    }

    /** Keep only the last 2 characters, so support can match "ends in 42". */
    public static function mask(string $value): string
    {
        $len = strlen($value);
        return $len <= 2 ? str_repeat('*', $len) : str_repeat('*', $len - 2) . substr($value, -2);
    }

    /** Mask long digit runs (phones: 10-15 digits, Egyptian national IDs: 14). */
    public static function text(string $text): string
    {
        return (string) preg_replace_callback(
            '/(?<!\d)\+?\d{10,15}(?!\d)/',
            static fn (array $m): string => self::mask($m[0]),
            $text
        );
    }
}
