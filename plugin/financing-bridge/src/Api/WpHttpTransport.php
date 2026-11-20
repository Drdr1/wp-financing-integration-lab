<?php
declare(strict_types=1);

namespace FinancingBridge\Api;

/** Transport over the WordPress HTTP API (wp_remote_request). */
final class WpHttpTransport implements Transport
{
    public function send(string $method, string $url, array $headers, ?string $body, int $timeoutSeconds): Response
    {
        $args = [
            'method' => $method,
            'headers' => $headers,
            'timeout' => (float) $timeoutSeconds,
            'redirection' => 0,
            'sslverify' => true,
            'user-agent' => 'FinancingBridge/' . FINB_VERSION . '; ' . home_url('/'),
        ];
        if ($body !== null) {
            $args['body'] = $body;
        }
        $result = wp_remote_request($url, $args);
        if (is_wp_error($result)) {
            throw new TransportFailure($result->get_error_message());
        }
        $headers = [];
        foreach (wp_remote_retrieve_headers($result) as $name => $value) {
            $headers[strtolower((string) $name)] = is_array($value) ? implode(', ', $value) : (string) $value;
        }
        return new Response((int) wp_remote_retrieve_response_code($result), (string) wp_remote_retrieve_body($result), $headers);
    }
}
