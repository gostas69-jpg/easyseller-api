<?php

declare(strict_types=1);

namespace EasySeller\SiteSdk\Tests;

use EasySeller\SiteSdk\Money;
use PHPUnit\Framework\TestCase;

final class MoneyTest extends TestCase
{
    public function testAmountHasTwoDigits(): void
    {
        self::assertSame('6500.00', Money::amount(6500));
        self::assertSame('337.43', Money::amount(337.425000001));
        self::assertSame('0.00', Money::amount(-0.001));
    }

    public function testCostWithoutVatGivesZero(): void
    {
        self::assertSame(['amount' => '6500.00', 'vat' => '0.00'], Money::cost(6500, false));
    }

    public function testCostWithVatGivesVatInside(): void
    {
        // Казпочта: 3 200 ₸ с НДС 16 % — внутри 441,38 ₸
        self::assertSame(['amount' => '3200.00', 'vat' => '441.38'], Money::cost(3200, true, 16));
    }

    public function testUnknownVatStaysNull(): void
    {
        self::assertSame(['amount' => '3200.00', 'vat' => null], Money::cost(3200, null));
        self::assertSame(['amount' => '3200.00', 'vat' => null], Money::cost(3200, true));
    }

    public function testDeliveryCostKeepsEstimate(): void
    {
        self::assertSame(['amount' => '2640.00', 'vat' => '0.00', 'estimate' => false], Money::deliveryCost(2640, false, null, false));
    }

    public function testPaymentFee(): void
    {
        self::assertSame(['amount' => '34.58', 'vat' => '0.00'], Money::paymentFee(3640, 0.95, true, false));
        self::assertSame(['amount' => '0.00', 'vat' => '0.00'], Money::paymentFee(3640, 0.95, false, false));
    }
}
