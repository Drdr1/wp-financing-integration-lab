<?php
declare(strict_types=1);

namespace FinancingBridge\Http;

use FinancingBridge\Security\Signature;
use FinancingBridge\Security\SignatureResult;
use FinancingBridge\Service\FinancingService;
use FinancingBridge\Store\AuditLog;

/**
 * POST /wp-json/financing/v1/webhook
 *
 * Public route, authenticated by signature only. The body is verified as raw
 * bytes before it is parsed; anything unsigned, stale or malformed gets 401
 * and changes nothing. Valid events are answered 200 even when they are
 * duplicates, so the platform stops retrying them.
 */
final class WebhookController
{
    public function __construct(
        private readonly Signature $signature,
        private readonly FinancingService $service,
        private readonly AuditLog $log,
    ) {
    }

    public function register(): void
    {
        register_rest_route('financing/v1', '/webhook', [
            'methods' => 'POST',
            'callback' => [$this, 'handle'],
            'permission_callback' => '__return_true', // authenticated by HMAC in handle()
        ]);
    }

    public function handle(\WP_REST_Request $request): \WP_REST_Response
    {
        $body = (string) $request->get_body();
        $result = $this->signature->verify(
            (string) $request->get_header('x_fin_timestamp'),
            $body,
            (string) $request->get_header('x_fin_signature')
        );
        if ($result !== SignatureResult::Valid) {
            $this->log->write(null, 'warning', 'Webhook rejected: ' . $result->value, [
                'event_id' => (string) $request->get_header('x_fin_event_id'),
            ]);
            return new \WP_REST_Response(['error' => 'invalid_signature'], 401);
        }

        $event = json_decode($body, true);
        if (
            !is_array($event)
            || !is_string($event['id'] ?? null)
            || !is_string($event['type'] ?? null)
            || !is_string($event['data']['po_id'] ?? null)
            || $event['id'] !== (string) $request->get_header('x_fin_event_id')
        ) {
            return new \WP_REST_Response(['error' => 'malformed_event'], 400);
        }

        /** @var array{id: string, type: string, data: array{po_id: string}} $event */
        $outcome = $this->service->applyEvent($event);
        return new \WP_REST_Response(['received' => true, 'outcome' => $outcome], 200);
    }
}
