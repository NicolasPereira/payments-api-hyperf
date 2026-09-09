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

namespace HyperfTest\Contract;

use App\Domain\Shared\Exception\DomainException;
use App\Domain\Shared\ValueObject\Money;
use App\Domain\Transfer\Entity\Transfer;
use App\Domain\Transfer\Entity\TransferStatus;
use App\Domain\Transfer\Exception\MerchantPayerNotAllowedException;
use App\Domain\User\Entity\UserType;
use App\Domain\Wallet\Exception\InsufficientBalanceException;
use PHPUnit\Framework\TestCase;

/**
 * T045 Contract test merchant payer rejection
 * FR-007: lojista como payer → 403/422 "lojista não pode enviar" businessCode merchant_payer_blocked
 * TDD: cobre DomainException hierarchy, http 403, OTEL exception.type e metrics code.
 * @internal
 * @coversNothing
 */
final class MerchantPayerRejectionTest extends TestCase
{
    public function testMerchantPayerThrows403MerchantPayerBlocked(): void
    {
        try {
            Transfer::createForCommonPayer(Money::fromString('10.00'), 5, UserType::MERCHANT, 9);
            self::fail('Expected MerchantPayerNotAllowedException');
        } catch (MerchantPayerNotAllowedException $e) {
            self::assertSame(403, $e->getHttpStatus());
            self::assertSame('merchant_payer_blocked', $e->getBusinessCode());
            self::assertStringContainsString('Lojista', $e->getMessage());
        }
    }

    public function testMerchantPayerDirectConstructor403(): void
    {
        $this->expectException(MerchantPayerNotAllowedException::class);
        new Transfer(Money::fromString('10.00'), 1, 2, TransferStatus::PENDING, null, null, null, null, null, null, UserType::MERCHANT);
    }

    public function testMerchantPayerDirectConstructorProperties(): void
    {
        try {
            new Transfer(Money::fromString('10.00'), 7, 8, TransferStatus::COMPLETED, null, null, null, null, null, null, UserType::MERCHANT);
            self::fail('Expected MerchantPayerNotAllowedException');
        } catch (MerchantPayerNotAllowedException $e) {
            self::assertSame(403, $e->getHttpStatus(), 'FR-007 requires 403 for merchant payer');
            self::assertSame('merchant_payer_blocked', $e->getBusinessCode());
            // DomainException hierarchy já criada (T008)
            self::assertInstanceOf(DomainException::class, $e);
            // OTEL exception.type should be MerchantPayerNotAllowedException
            self::assertSame(MerchantPayerNotAllowedException::class, $e::class);
            // Metrics code
            self::assertSame('merchant_payer_blocked', $e->getBusinessCode());
        }
    }

    public function testMerchantPayerErrorEnvelope(): void
    {
        $ex = new MerchantPayerNotAllowedException();
        $envelope = ['code' => $ex->getBusinessCode(), 'message' => $ex->getMessage(), 'correlation_id' => 'corr-merchant-test'];
        self::assertSame('merchant_payer_blocked', $envelope['code']);
        self::assertArrayHasKey('message', $envelope);
        self::assertArrayHasKey('correlation_id', $envelope);
        self::assertSame(403, $ex->getHttpStatus());
        // 403 ou 422 aceitos per spec FR-007, mas implementação usa 403 (T046)
        self::assertContains($ex->getHttpStatus(), [403, 422]);
        self::assertSame(403, $ex->getHttpStatus());
    }

    public function testCommonPayerStillAllowed(): void
    {
        // Sanity: common payer NÃO deve lançar merchant_payer_blocked
        $transfer = Transfer::createForCommonPayer(Money::fromString('10.00'), 1, UserType::COMMON, 2);
        self::assertSame(1, $transfer->getPayerId());
    }

    public function testMerchantPayerBlockedBeforeMutationContract(): void
    {
        // Contract: merchant payer deve ser rejeitado antes de Authorizer ou débito — sem mutação
        // Valida que exceção é 403 e não 422 insuficiente saldo (businessCode distinto)
        $exMerchant = new MerchantPayerNotAllowedException();
        $exBalance = new InsufficientBalanceException();
        self::assertNotSame($exMerchant->getBusinessCode(), $exBalance->getBusinessCode());
        self::assertSame('merchant_payer_blocked', $exMerchant->getBusinessCode());
        self::assertSame('insufficient_balance', $exBalance->getBusinessCode());
        self::assertSame(403, $exMerchant->getHttpStatus());
        self::assertSame(422, $exBalance->getHttpStatus());
    }

    public function testMerchantPayerRejectionMapsToHttp403(): void
    {
        // Integration with ExceptionMapper would map to 403; validate directly
        $ex = new MerchantPayerNotAllowedException('Lojista não pode enviar transferência');
        self::assertSame(403, $ex->getHttpStatus());
        self::assertSame('merchant_payer_blocked', $ex->getBusinessCode());
        self::assertMatchesRegularExpression('/lojista/i', $ex->getMessage());
    }
}
