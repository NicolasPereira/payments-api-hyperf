<?php

declare(strict_types=1);
/**
 * This file is part of Hyperf.
 *
 * @link     https://www.hyperf.io
 * @document https://hyperf.wiki
 * @contact  group@hyperf.io
 * @license  https://github.com/hyperf/hyperf/blob/master/LICENSE
 */

namespace HyperfTest\Unit\Domain\Transfer;

use App\Domain\Transfer\Exception\TransferValidationException;
use App\Domain\Transfer\ValueObject\TransferValue;
use PHPUnit\Framework\TestCase;
use TypeError;

/**
 * T032 — Money/TransferValue parsing.
 * @internal
 * @coversNothing
 */
final class MoneyTest extends TestCase
{
    public function testAcceptsExactString(): void
    {
        $this->assertSame('10.00', TransferValue::fromString('10.00')->amount());
    }

    public function testRejectsZero(): void
    {
        $this->expectException(TransferValidationException::class);
        TransferValue::fromString('0.00');
    }

    public function testRejectsNegative(): void
    {
        $this->expectException(TransferValidationException::class);
        TransferValue::fromString('-10.00');
    }

    public function testRejectsMoreThanTwoDecimals(): void
    {
        $this->expectException(TransferValidationException::class);
        TransferValue::fromString('10.001');
    }

    public function testRejectsOneDecimal(): void
    {
        $this->expectException(TransferValidationException::class);
        TransferValue::fromString('10.0');
    }

    public function testRejectsNonStringNumber(): void
    {
        $this->expectException(TypeError::class);
        // @phpstan-ignore-next-line
        TransferValue::fromString(10.0);
    }
}
