<?php
declare(strict_types=1);

use FinancingBridge\Api\ApiError;
use FinancingBridge\Api\Client;
use FinancingBridge\Api\Response;
use FinancingBridge\Api\RetryPolicy;
use FinancingBridge\Api\Transport;
use FinancingBridge\Api\TransportFailure;
use FinancingBridge\Service\FinancingError;
use PHPUnit\Framework\TestCase;

/** Replays a scripted list of responses (or failures) and records requests. */
final class ScriptedTransport implements Transport
{
    /** @var list<array{method: string, url: string, headers: array<string, string>, body: ?string}> */
    public array $sent = [];

    /** @param list<Response|TransportFailure> $script */
    public function __construct(private array $script)
    {
    }

    public function send(string $method, string $url, array $headers, ?string $body, int $timeoutSeconds): Response
    {
        $this->sent[] = compact('method', 'url', 'headers', 'body');
        $next = array_shift($this->script) ?? throw new LogicException('script exhausted');
        if ($next instanceof TransportFailure) {
            throw $next;
        }
        return $next;
    }
}

final class ClientTest extends TestCase
{
    /** @var list<int> */
    private array $sleeps = [];

    private function client(ScriptedTransport $t): Client
    {
        $retry = new RetryPolicy(4, 200, 4000, function (int $us): void {
            $this->sleeps[] = $us;
        });
        return new Client($t, 'https://uat.example/api/', 'key-123', $retry);
    }

    private static function json(int $status, array $data, array $headers = []): Response
    {
        return new Response($status, (string) json_encode($data), $headers);
    }

    public function testSendsAuthJsonAndIdempotencyKey(): void
    {
        $t = new ScriptedTransport([self::json(201, ['po_id' => 'po_1', 'status' => 'pending_delivery'])]);
        $this->client($t)->createPurchaseOrder('apr_1', 'ORD-1', 1000, 'EGP', [['sku' => 'A', 'name' => 'A', 'quantity' => 1, 'unit_price' => 1000]], 'finb-key');
        $req = $t->sent[0];
        self::assertSame('POST', $req['method']);
        self::assertSame('https://uat.example/api/v1/purchase-orders', $req['url']);
        self::assertSame('Bearer key-123', $req['headers']['Authorization']);
        self::assertSame('finb-key', $req['headers']['Idempotency-Key']);
        self::assertSame(1000, json_decode((string) $req['body'], true)['amount']);
    }

    public function testRetries5xxAndNetworkFailuresWithTheSameKey(): void
    {
        $t = new ScriptedTransport([
            new TransportFailure('timeout'),
            self::json(503, ['error' => ['code' => 'unavailable']]),
            self::json(200, ['po_id' => 'po_1', 'status' => 'delivered']),
        ]);
        $this->client($t)->confirmDelivery('po_1', '7731', 'finb-k');
        self::assertCount(3, $t->sent);
        self::assertSame(['finb-k'], array_values(array_unique(array_map(fn ($r) => $r['headers']['Idempotency-Key'], $t->sent))));
        self::assertCount(2, $this->sleeps);
    }

    public function testBusinessErrorsAreNotRetried(): void
    {
        $t = new ScriptedTransport([self::json(422, ['error' => ['code' => 'limit_exceeded', 'message' => 'x']], ['x-request-id' => 'req_9'])]);
        try {
            $this->client($t)->verifyApproval('482913', '01012345678', 'finb-v');
            self::fail('expected ApiError');
        } catch (ApiError $e) {
            self::assertSame('limit_exceeded', $e->errorCode);
            self::assertSame('req_9', $e->requestId);
            self::assertFalse($e->isRetryable());
        }
        self::assertCount(1, $t->sent);
    }

    public function testGivesUpAfterMaxAttempts(): void
    {
        $fail = self::json(503, ['error' => ['code' => 'unavailable']]);
        $t = new ScriptedTransport([$fail, $fail, $fail, $fail]);
        $this->expectException(ApiError::class);
        try {
            $this->client($t)->getPurchaseOrder('po_1');
        } finally {
            self::assertCount(4, $t->sent);
        }
    }

    public function testRetryAfterIsHonoured(): void
    {
        $t = new ScriptedTransport([
            self::json(429, ['error' => ['code' => 'rate_limited']], ['retry-after' => '2']),
            self::json(200, ['po_id' => 'po_1', 'status' => 'pending_delivery']),
        ]);
        $this->client($t)->getPurchaseOrder('po_1');
        self::assertSame([2_000_000], $this->sleeps);
    }

    public function testPathSegmentsAreEncoded(): void
    {
        $t = new ScriptedTransport([self::json(200, ['po_id' => 'x', 'status' => 'pending_delivery'])]);
        $this->client($t)->getPurchaseOrder('po_1/../../admin');
        self::assertSame('https://uat.example/api/v1/purchase-orders/po_1%2F..%2F..%2Fadmin', $t->sent[0]['url']);
    }

    public function testUserFacingMessagesNeverLeakCodes(): void
    {
        $e = FinancingError::fromApi(new ApiError('invalid_delivery_code', 422, 'code 7731 wrong'));
        self::assertStringNotContainsString('7731', $e->getMessage());
        self::assertSame(422, $e->httpStatus);
        self::assertSame(503, FinancingError::fromApi(new ApiError('network_error', 0, 'timeout'))->httpStatus);
    }
}
