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

use App\Domain\Shared\ValueObject\Money;
use App\Domain\Transfer\Entity\Transfer;
use App\Domain\Transfer\Entity\TransferStatus;
use App\Domain\Transfer\Exception\MerchantPayerNotAllowedException;
use App\Domain\Transfer\Exception\SelfTransferException;
use App\Domain\Transfer\Exception\TransferValidationException;
use App\Domain\User\Entity\UserType;
use PHPUnit\Framework\TestCase;

/**
 * T033 Unit test Transfer invariants (payer≠payee, payer must be common, value positive).
 * @internal
 * @coversNothing
 */
final class TransferTest extends TestCase
{
    public function testValidCommonToCommonTransfer(): void
    {
        $value = Money::fromString('10.00');
        $transfer = new Transfer($value, 1, 2, TransferStatus::COMPLETED, null, null, null, null, null, null, UserType::COMMON);
        self::assertSame(1, $transfer->getPayerId());
        self::assertSame(2, $transfer->getPayeeId());
        self::assertSame('10.00', $transfer->getValue()->getAmount());
        self::assertSame(TransferStatus::COMPLETED, $transfer->getStatus());
    }

    public function testValidViaFactoryCommonPayer(): void
    {
        $value = Money::fromString('20.00');
        $transfer = Transfer::createForCommonPayer($value, 10, UserType::COMMON, 20);
        self::assertSame(10, $transfer->getPayerId());
        self::assertSame(20, $transfer->getPayeeId());
    }

    public function testRejectSelfTransferPayerEqualsPayee(): void
    {
        $this->expectException(SelfTransferException::class);
        $value = Money::fromString('10.00');
        new Transfer($value, 5, 5);
    }

    public function testRejectSelfTransferViaFactory(): void
    {
        $this->expectException(SelfTransferException::class);
        $value = Money::fromString('10.00');
        Transfer::createForCommonPayer($value, 7, UserType::COMMON, 7);
    }

    public function testSelfTransferHttpStatus422(): void
    {
        try {
            new Transfer(Money::fromString('10.00'), 1, 1);
            self::fail('Expected SelfTransferException');
        } catch (SelfTransferException $e) {
            self::assertSame(422, $e->getHttpStatus());
            self::assertSame('self_transfer', $e->getBusinessCode());
        }
    }

    public function testRejectMerchantPayer(): void
    {
        $this->expectException(MerchantPayerNotAllowedException::class);
        $value = Money::fromString('10.00');
        new Transfer($value, 1, 2, TransferStatus::PENDING, null, null, null, null, null, null, UserType::MERCHANT);
    }

    public function testRejectMerchantPayerViaFactory(): void
    {
        $this->expectException(MerchantPayerNotAllowedException::class);
        $value = Money::fromString('10.00');
        Transfer::createForCommonPayer($value, 1, UserType::MERCHANT, 2);
    }

    public function testMerchantPayerHttpStatus403(): void
    {
        try {
            Transfer::createForCommonPayer(Money::fromString('10.00'), 1, UserType::MERCHANT, 2);
            self::fail('Expected MerchantPayerNotAllowedException');
        } catch (MerchantPayerNotAllowedException $e) {
            self::assertSame(403, $e->getHttpStatus());
            self::assertSame('merchant_payer_blocked', $e->getBusinessCode());
        }
    }

    public function testRejectZeroValue(): void
    {
        $this->expectException(TransferValidationException::class);
        $value = Money::zero();
        new Transfer($value, 1, 2);
    }

    public function testRejectInvalidPayerIdZero(): void
    {
        $this->expectException(TransferValidationException::class);
        new Transfer(Money::fromString('10.00'), 0, 2);
    }

    public function testRejectInvalidPayeeIdZero(): void
    {
        $this->expectException(TransferValidationException::class);
        new Transfer(Money::fromString('10.00'), 1, 0);
    }

    public function testTransferStatusTransitions(): void
    {
        $t = new Transfer(Money::fromString('10.00'), 1, 2, TransferStatus::PENDING);
        self::assertSame(TransferStatus::PENDING, $t->getStatus());
        $t2 = $t->withStatus(TransferStatus::COMPLETED);
        self::assertSame(TransferStatus::COMPLETED, $t2->getStatus());
        self::assertTrue(TransferStatus::COMPLETED->isTerminal());
        self::assertFalse(TransferStatus::PENDING->isTerminal());
    }
}
