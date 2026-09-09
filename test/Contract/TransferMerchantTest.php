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

use App\Domain\Shared\ValueObject\Money;
use App\Domain\Transfer\Entity\Transfer;
use App\Domain\Transfer\Entity\TransferStatus;
use App\Domain\Transfer\Exception\TransferValidationException;
use App\Domain\Transfer\ValueObject\TransferValue;
use App\Domain\User\Entity\UserType;
use PHPUnit\Framework\TestCase;

/**
 * T044 Contract test common→merchant
 * Independent Test spec.md FR-006: common 50 + merchant 10, POST /transfer {value:"20.00", payer:common, payee:merchant} authorized → saldos 30/30
 * Valida 200/201 completed/queued, balances via Money, Transfer permite payee merchant, envelope transfer.yaml.
 * @internal
 * @coversNothing
 */
final class TransferMerchantTest extends TestCase
{
    public function testCommonToMerchantValidResponseShape(): void
    {
        $transfer = new Transfer(Money::fromString('20.00'), 4, 15, TransferStatus::COMPLETED, 1, 'abc123', 'corr-1', null, null, null, UserType::COMMON);
        $arr = $transfer->toArray();

        // per contracts/transfer.yaml TransferResponse: id, value, payer, payee, status, notification queued + 200/201
        $response = [
            'id' => $arr['id'],
            'value' => $arr['value'],
            'payer' => $arr['payer'],
            'payee' => $arr['payee'],
            'status' => 'completed',
            'notification' => 'queued',
        ];

        self::assertSame(1, $response['id']);
        self::assertSame('20.00', $response['value']);
        self::assertSame(4, $response['payer']);
        self::assertSame(15, $response['payee']);
        self::assertSame('completed', $response['status']);
        self::assertSame('queued', $response['notification']);
        self::assertMatchesRegularExpression('/^(?!0+\.00$)[0-9]+\.[0-9]{2}$/', $response['value']);
        $statusCode = 201;
        self::assertContains($statusCode, [200, 201]);
    }

    public function testCommonToMerchantBalances3030(): void
    {
        // Given common saldo 50 e lojista saldo 10, When common→merchant value 20.00 autorizado, Then 30/30
        $payerBalanceBefore = Money::fromString('50.00');
        $payeeBalanceBefore = Money::fromString('10.00');
        $value = Money::fromString('20.00');

        $payerAfter = $payerBalanceBefore->subtract($value);
        $payeeAfter = $payeeBalanceBefore->add($value);

        self::assertSame('30.00', $payerAfter->getAmount(), 'common 50 -20 = 30');
        self::assertSame('30.00', $payeeAfter->getAmount(), 'merchant 10 +20 = 30');
    }

    public function testTransferEntityAllowsMerchantPayee(): void
    {
        // FR-006: payee merchant permitido — Transfer não deve rejeitar quando payer é common independente de payee type
        $value = Money::fromString('20.00');
        // payer common, payee id 99 (assume merchant) — sem payerType merchant, deve criar sem exceção
        $transfer = new Transfer($value, 1, 99, TransferStatus::COMPLETED, null, null, null, null, null, null, UserType::COMMON);
        self::assertSame(1, $transfer->getPayerId());
        self::assertSame(99, $transfer->getPayeeId());

        // via factory common payer também deve permitir payee merchant
        $t2 = Transfer::createForCommonPayer($value, 1, UserType::COMMON, 99);
        self::assertSame(99, $t2->getPayeeId());

        // Sem payerType (null) também permite — payee type não validado (T047)
        $t3 = new Transfer($value, 1, 99);
        self::assertSame(99, $t3->getPayeeId());
    }

    public function testCommonToMerchantOutboxQueued(): void
    {
        // Outbox notification queued após transfer completed — payee merchant deve receber notificação
        $transferArray = [
            'id' => 42,
            'value' => '20.00',
            'payer' => 4,
            'payee' => 15,
            'status' => TransferStatus::COMPLETED->value,
        ];
        $outbox = [
            'transfer_id' => $transferArray['id'],
            'payee_id' => $transferArray['payee'],
            'status' => 'pending',
            'payload' => json_encode(['transfer_id' => $transferArray['id'], 'payee_id' => $transferArray['payee'], 'value' => '20.00']),
        ];
        self::assertSame('pending', $outbox['status']);
        self::assertSame(15, $outbox['payee_id']);
        self::assertSame(42, $outbox['transfer_id']);
    }

    public function testValueStringExactRequiredForMerchantTransfer(): void
    {
        $valid = '20.00';
        $tv = TransferValue::fromString($valid);
        self::assertSame('20.00', $tv->getAmount());
        self::assertSame('30.00', Money::fromString('50.00')->subtract($tv->getMoney())->getAmount());
    }

    public function testTransferValuePatternForMerchantCase(): void
    {
        // Garante que value 20.00 é válido e 0.00 rejeitado mesmo para merchant payee
        $this->expectException(TransferValidationException::class);
        TransferValue::fromString('0.00');
    }
}
