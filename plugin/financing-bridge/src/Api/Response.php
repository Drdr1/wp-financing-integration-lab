<?php
declare(strict_types=1);

namespace FinancingBridge\Api;

final class Response
{
    /** @param array<string, string> $headers lower-cased names */
    public function __construct(
        public readonly int $status,
        public readonly string $body,
        public readonly array $headers = [],
    ) {
    }

    /** @return array<string, mixed> */
    public function json(): array
    {
        if ($this->body === '') {
            return [];
        }
        $data = json_decode($this->body, true);
        return is_array($data) ? $data : [];
    }
}
