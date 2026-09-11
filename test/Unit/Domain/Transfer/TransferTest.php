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

use App\Domain\Transfer\Entity\Transfer;
use App\Domain\Transfer\Entity\TransferStatus;
use App\Domain\Transfer\Exception\MerchantPayerNotAllowedException;
use App\Domain\Transfer\Exception\SelfTransferException;
use App\Domain\Transfer\Exception\TransferValidationException;
use App\Domain\Transfer\ValueObject\TransferValue;
use App\Domain\User\Entity\UserType;
use PHPUnit\Framework\TestCase;

/**
 * T033 — Transfer invariants.
 * @internal
 * @coversNothing
 */
final class TransferTest extends TestCase
{
    public function testCreatesPendingTransfer(): void
    {
        $t = Transfer::create(1, 2, TransferValue::fromString('10.00'), UserType::CONSUMER);
        $this->assertSame(TransferStatus::PENDING, $t->status);
    }

    public function testRejectsSelfTransfer(): void
    {
        $this->expectException(SelfTransferException::class);
        Transfer::create(1, 1, TransferValue::fromString('10.00'), UserType::CONSUMER);
    }

    public function testRejectsMerchantPayer(): void
    {
        $this->expectException(MerchantPayerNotAllowedException::class);
        Transfer::create(1, 2, TransferValue::fromString('10.00'), UserType::MERCHANT);
    }

    public function testAllowsMerchantPayee(): void
    {
        $t = Transfer::create(1, 2, TransferValue::fromString('10.00'), UserType::CONSUMER);
        $this->assertSame(2, $t->payeeId);
    }

    public function testTransitionPendingAuthorizedCompleted(): void
    {
        $t = Transfer::create(1, 2, TransferValue::fromString('10.00'), UserType::CONSUMER);
        $t = $t->markAuthorized();
        $this->assertSame(TransferStatus::AUTHORIZED, $t->status);
        $t = $t->markCompleted();
        $this->assertSame(TransferStatus::COMPLETED, $t->status);
    }

    public function testRejectsInvalidTransition(): void
    {
        $this->expectException(TransferValidationException::class);
        Transfer::create(1, 2, TransferValue::fromString('10.00'), UserType::CONSUMER)->markCompleted();
    }
}
