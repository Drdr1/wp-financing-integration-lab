<?php
declare(strict_types=1);

namespace FinancingBridge\Api;

interface Transport
{
    /**
     * Send one HTTP request. Throws TransportFailure when no response arrived
     * (timeout, connection refused, DNS): the request may or may not have
     * reached the platform.
     *
     * @param array<string, string> $headers
     */
    public function send(string $method, string $url, array $headers, ?string $body, int $timeoutSeconds): Response;
}
