<?php
declare(strict_types=1);

namespace FinancingBridge\Store;

use FinancingBridge\Security\Redactor;

/** Append-only trail of every step, redacted before it is written. */
final class AuditLog
{
    /** @param array<string, mixed> $context */
    public function write(?int $orderId, string $level, string $message, array $context = []): void
    {
        global $wpdb;
        $wpdb->insert($wpdb->prefix . 'finb_log', [
            'order_id' => $orderId,
            'level' => $level,
            'message' => Redactor::text(mb_substr($message, 0, 255)),
            'context' => (string) wp_json_encode(Redactor::context($context)),
            'created_at' => current_time('mysql', true),
        ]);
    }

    /** @return list<array<string, mixed>> */
    public function forOrder(int $orderId): array
    {
        global $wpdb;
        /** @var list<array<string, mixed>> */
        return $wpdb->get_results($wpdb->prepare(
            "SELECT level, message, context, created_at FROM {$wpdb->prefix}finb_log WHERE order_id = %d ORDER BY id",
            $orderId
        ), ARRAY_A) ?: [];
    }
}
