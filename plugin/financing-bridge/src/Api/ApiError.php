<?php
declare(strict_types=1);

namespace FinancingBridge\Api;

/**
 * A request the platform answered with an error, or that never got an answer.
 *
 * `code` is the platform's machine-readable error (invalid_code, expired,
 * limit_exceeded, ...) so callers branch on it, never on message text.
 */
final class ApiError extends \RuntimeException
{
    public function __construct(
        public readonly string $errorCode,
        public readonly int $httpStatus,
        string $message,
        public readonly ?string $requestId = null,
    ) {
        parent::__construct($message);
    }

    public static function fromResponse(Response $r): self
    {
        $data = $r->json();
        $error = is_array($data['error'] ?? null) ? $data['error'] : [];
        return new self(
            (string) ($error['code'] ?? 'http_' . $r->status),
            $r->status,
            (string) ($error['message'] ?? 'Financing platform returned HTTP ' . $r->status),
            $r->headers['x-request-id'] ?? null,
        );
    }

    public function isRetryable(): bool
    {
        return $this->httpStatus === 0 || $this->httpStatus === 429 || $this->httpStatus >= 500;
    }
}
