<?php
declare(strict_types=1);

use FinancingBridge\Security\Signature;
use FinancingBridge\Security\SignatureResult;
use PHPUnit\Framework\TestCase;

final class SignatureTest extends TestCase
{
    private const SECRET = 'whsec_0123456789abcdef0123456789abcdef';

    public function testRoundTrip(): void
    {
        $s = new Signature(self::SECRET);
        $body = '{"id":"evt_1"}';
        self::assertSame(SignatureResult::Valid, $s->verify('1700000000', $body, $s->sign('1700000000', $body), 1700000010));
    }

    public function testChangedBodyFails(): void
    {
        $s = new Signature(self::SECRET);
        $sig = $s->sign('1700000000', '{"id":"evt_1"}');
        self::assertSame(SignatureResult::Invalid, $s->verify('1700000000', '{"id":"evt_2"}', $sig, 1700000000));
    }

    public function testTimestampIsSigned(): void
    {
        $s = new Signature(self::SECRET);
        $sig = $s->sign('1700000000', 'b');
        self::assertSame(SignatureResult::Invalid, $s->verify('1700000100', 'b', $sig, 1700000100));
    }

    public function testStaleAndFutureTimestampsFail(): void
    {
        $s = new Signature(self::SECRET);
        self::assertSame(SignatureResult::Stale, $s->verify('1700000000', 'b', $s->sign('1700000000', 'b'), 1700000000 + 301));
        self::assertSame(SignatureResult::Stale, $s->verify('1700000400', 'b', $s->sign('1700000400', 'b'), 1700000000));
    }

    public function testMalformedHeaders(): void
    {
        $s = new Signature(self::SECRET);
        self::assertSame(SignatureResult::Malformed, $s->verify('abc', 'b', 'sha256=00', 1));
        self::assertSame(SignatureResult::Malformed, $s->verify('1700000000', 'b', 'md5=00', 1700000000));
    }

    public function testShortSecretIsRefused(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new Signature('short');
    }
}
