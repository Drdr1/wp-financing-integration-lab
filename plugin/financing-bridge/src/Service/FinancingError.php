<?php
declare(strict_types=1);

namespace FinancingBridge\Service;

use FinancingBridge\Api\ApiError;

/**
 * An error the store can show or act on. Messages are safe to display:
 * they never contain codes, phone numbers or platform internals.
 */
final class FinancingError extends \RuntimeException
{
    private const MESSAGES = [
        'invalid_code' => 'The approval code is not valid.',
        'approval_expired' => 'The approval code has expired. The customer needs a new one from the financing app.',
        'approval_used' => 'This approval code has already been used.',
        'phone_mismatch' => 'The phone number does not match the approval.',
        'limit_exceeded' => 'The customer\'s approved limit does not cover this order.',
        'invalid_delivery_code' => 'The delivery code is not correct.',
        'delivery_code_locked' => 'Too many wrong delivery codes. The order is flagged for review.',
        'already_delivered' => 'The order was already delivered on the platform.',
        'already_cancelled' => 'The order was already cancelled on the platform.',
        'network_error' => 'The financing platform did not respond. Nothing was changed; please try again.',
    ];

    public function __construct(public readonly string $errorCode, string $message, public readonly int $httpStatus = 422)
    {
        parent::__construct($message);
    }

    public static function fromApi(ApiError $e): self
    {
        $message = self::MESSAGES[$e->errorCode]
            ?? ($e->isRetryable() ? self::MESSAGES['network_error'] : 'The financing platform refused the request (' . $e->errorCode . ').');
        return new self($e->errorCode, $message, $e->isRetryable() ? 503 : 422);
    }
}
