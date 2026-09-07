<?php

declare(strict_types=1);

namespace HyperfTest\Unit\Domain\Transfer;

use App\Domain\Shared\Exception\DomainException;
use App\Domain\Shared\ValueObject\Money;
use App\Domain\Transfer\Exception\TransferValidationException;
use App\Domain\Transfer\ValueObject\TransferValue;
use PHPUnit\Framework\TestCase;

/**
 * T032 Unit test Money parsing/validation
 * (string exata "10.00", rejeita number, zero, negativo, >2 decimais → 422)
 */
final class MoneyTest extends TestCase
{
    public function testValidStringExact10_00(): void
    {
        $money = Money::fromString('10.00');
        self::assertSame('10.00', $money->getAmount());
        self::assertSame(1000, $money->getCents());
        self::assertTrue($money->isPositive());

        $tv = TransferValue::fromString('10.00');
        self::assertSame('10.00', $tv->getAmount());
        self::assertSame(1000, $tv->getCents());
    }

    public function testValidString100_00(): void
    {
        $tv = TransferValue::fromString('100.00');
        self::assertSame('100.00', $tv->getAmount());
        self::assertSame(10000, $tv->getCents());
    }

    public function testRejectNumber10_0(): void
    {
        $this->expectException(TransferValidationException::class);
        TransferValue::fromMixed(10.0);
    }

    public function testRejectNumberInt(): void
    {
        $this->expectException(TransferValidationException::class);
        TransferValue::fromMixed(10);
    }

    public function testRejectZeroString(): void
    {
        $this->expectException(TransferValidationException::class);
        TransferValue::fromString('0.00');
    }

    public function testRejectZeroViaMixed(): void
    {
        $this->expectException(TransferValidationException::class);
        TransferValue::fromMixed('0.00');
    }

    public function testRejectDoubleZero00_00(): void
    {
        $this->expectException(TransferValidationException::class);
        TransferValue::fromString('00.00');
    }

    public function testRejectNegative(): void
    {
        $this->expectException(TransferValidationException::class);
        TransferValue::fromString('-10.00');
    }

    public function testRejectNegativeViaMixed(): void
    {
        $this->expectException(TransferValidationException::class);
        TransferValue::fromMixed('-5.00');
    }

    public function testRejectMoreThan2Decimals(): void
    {
        $this->expectException(TransferValidationException::class);
        TransferValue::fromString('10.001');
    }

    public function testRejectMoreThan2Decimals100_000(): void
    {
        $this->expectException(TransferValidationException::class);
        TransferValue::fromString('10.000');
    }

    public function testRejectOneDecimal(): void
    {
        $this->expectException(TransferValidationException::class);
        TransferValue::fromString('10.0');
    }

    public function testRejectNoDecimal(): void
    {
        $this->expectException(TransferValidationException::class);
        TransferValue::fromString('10');
    }

    public function testRejectEmpty(): void
    {
        $this->expectException(TransferValidationException::class);
        TransferValue::fromString('');
    }

    public function testRejectZeroViaMoneyIsPositiveCheck(): void
    {
        // Direct Money zero is allowed in domain, but TransferValue forbids zero
        $zero = Money::zero();
        self::assertTrue($zero->isZero());
        $this->expectException(TransferValidationException::class);
        TransferValue::fromMoney($zero);
    }

    public function testHttpStatusIs422(): void
    {
        try {
            TransferValue::fromString('0.00');
            self::fail('Expected TransferValidationException');
        } catch (TransferValidationException $e) {
            self::assertSame(422, $e->getHttpStatus());
        }

        try {
            TransferValue::fromMixed(10.0);
            self::fail('Expected TransferValidationException');
        } catch (TransferValidationException $e) {
            self::assertSame(422, $e->getHttpStatus());
            self::assertSame('transfer_validation', $e->getBusinessCode());
        }
    }

    public function testMoneyPatternPreservesExactCents(): void
    {
        $m = Money::fromString('0.01');
        self::assertSame(1, $m->getCents());
        $tv = TransferValue::fromString('0.01');
        self::assertSame(1, $tv->getCents());

        $m2 = Money::fromString('9999999999999.99');
        self::assertSame('9999999999999.99', $m2->getAmount());
    }
}
