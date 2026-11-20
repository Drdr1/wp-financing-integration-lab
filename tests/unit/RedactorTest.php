<?php
declare(strict_types=1);

use FinancingBridge\Security\Redactor;
use PHPUnit\Framework\TestCase;

final class RedactorTest extends TestCase
{
    public function testSecretKeysAreMaskedAtAnyDepth(): void
    {
        $out = Redactor::context([
            'approval_code' => '482913',
            'nested' => ['delivery_code' => '7731', 'customer_phone' => '01012345678'],
            'po_id' => 'po_abc',
        ]);
        self::assertSame('****13', $out['approval_code']);
        self::assertSame('**31', $out['nested']['delivery_code']);
        self::assertSame('*********78', $out['nested']['customer_phone']);
        self::assertSame('po_abc', $out['po_id']);
    }

    public function testPhonesAndNationalIdsInFreeTextAreMasked(): void
    {
        self::assertSame('call *********78 now', Redactor::text('call 01012345678 now'));
        self::assertSame('id ************12', Redactor::text('id 29001011234512'));
        self::assertSame('amount 1500000 EGP', Redactor::text('amount 1500000 EGP'));
    }

    public function testShortValuesAreFullyMasked(): void
    {
        self::assertSame('**', Redactor::mask('12'));
    }
}
