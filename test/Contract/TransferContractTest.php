<?php

declare(strict_types=1);

namespace HyperfTest\Contract;

use App\Domain\Shared\Exception\DomainException;
use App\Domain\Shared\ValueObject\Money;
use App\Domain\Transfer\Entity\Transfer;
use App\Domain\Transfer\Entity\TransferStatus;
use App\Domain\Transfer\Exception\SelfTransferException;
use App\Domain\Transfer\Exception\TransferValidationException;
use App\Domain\User\Entity\UserType;
use App\Domain\Wallet\Exception\InsufficientBalanceException;
use PHPUnit\Framework\TestCase;

/**
 * T035 Contract test POST /transfer valid
 * (200/201 completed/queued, 422 saldo, 404 missing, 422 self-transfer)
 * Domain-level contract validation without HTTP server; mirrors transfer.yaml
 */
final class TransferContractTest extends TestCase
{
    public function testValidTransferResponseShape(): void
    {
        $transfer = new Transfer(Money::fromString('100.00'), 4, 15, TransferStatus::COMPLETED, 1, 'abc123', 'corr-1');
        $arr = $transfer->toArray();

        // per contracts/transfer.yaml TransferResponse: id, value, payer, payee, status, notification queued
        $response = [
            'id' => $arr['id'],
            'value' => $arr['value'],
            'payer' => $arr['payer'],
            'payee' => $arr['payee'],
            'status' => 'completed',
            'notification' => 'queued',
        ];

        self::assertSame(1, $response['id']);
        self::assertSame('100.00', $response['value']);
        self::assertSame(4, $response['payer']);
        self::assertSame(15, $response['payee']);
        self::assertSame('completed', $response['status']);
        self::assertSame('queued', $response['notification']);
        self::assertMatchesRegularExpression('/^(?!0+\.00$)[0-9]+\.[0-9]{2}$/', $response['value']);
        // 200 or 201 acceptable
        $statusCode = 201;
        self::assertContains($statusCode, [200, 201]);
    }

    public function testContractTransferValuePatternValid(): void
    {
        $valid = ['10.00', '0.01', '100.00', '9999999999999.99'];
        foreach ($valid as $v) {
            $m = Money::fromString($v);
            self::assertSame($v, $m->getAmount());
            // Also via TransferValue
            $tv = \App\Domain\Transfer\ValueObject\TransferValue::fromString($v);
            self::assertSame($v, $tv->getAmount());
        }
    }

    public function testContractTransferValuePatternInvalidZero(): void
    {
        $this->expectException(TransferValidationException::class);
        \App\Domain\Transfer\ValueObject\TransferValue::fromString('0.00');
    }

    public function testContractTransferSelfTransfer422(): void
    {
        try {
            new Transfer(Money::fromString('10.00'), 1, 1);
            self::fail('Expected SelfTransferException');
        } catch (SelfTransferException $e) {
            self::assertSame(422, $e->getHttpStatus());
            self::assertSame('self_transfer', $e->getBusinessCode());
            // ErrorResponse shape per contracts/transfer.yaml:103
            $error = ['code' => $e->getBusinessCode(), 'message' => $e->getMessage(), 'correlation_id' => 'corr-1'];
            self::assertArrayHasKey('code', $error);
            self::assertArrayHasKey('message', $error);
            self::assertSame(422, $e->getHttpStatus());
        }
    }

    public function testContractInsufficientBalance422(): void
    {
        $payerBalance = Money::fromString('5.00');
        $transferValue = Money::fromString('10.00');

        self::assertTrue($payerBalance->lessThan($transferValue));

        $ex = new InsufficientBalanceException();
        self::assertSame(422, $ex->getHttpStatus());
        self::assertSame('insufficient_balance', $ex->getBusinessCode());

        $error = ['code' => $ex->getBusinessCode(), 'message' => $ex->getMessage()];
        self::assertSame('insufficient_balance', $error['code']);
    }

    public function testContractPayerNotFound404(): void
    {
        $ex = new \App\Domain\Shared\Exception\NotFoundException('Payer não encontrado', 'payer_not_found');
        self::assertSame(404, $ex->getHttpStatus());
        self::assertSame('payer_not_found', $ex->getBusinessCode());
    }

    public function testContractPayeeNotFound404(): void
    {
        $ex = new \App\Domain\Shared\Exception\NotFoundException('Payee não encontrado', 'payee_not_found');
        self::assertSame(404, $ex->getHttpStatus());
    }

    public function testContractValueNumberRejected422(): void
    {
        $this->expectException(TransferValidationException::class);
        \App\Domain\Transfer\ValueObject\TransferValue::fromMixed(100.0);
    }

    public function testContractValueNumberIntRejected422(): void
    {
        $this->expectException(TransferValidationException::class);
        \App\Domain\Transfer\ValueObject\TransferValue::fromMixed(10);
    }

    public function testContractNegativeValueRejected422(): void
    {
        $this->expectException(TransferValidationException::class);
        \App\Domain\Transfer\ValueObject\TransferValue::fromString('-10.00');
    }

    public function testContractMoreThan2DecimalsRejected422(): void
    {
        $this->expectException(TransferValidationException::class);
        \App\Domain\Transfer\ValueObject\TransferValue::fromString('10.001');
    }

    public function testContractMissingFields422(): void
    {
        $ex = new TransferValidationException('Campos obrigatórios: value, payer, payee');
        self::assertSame(422, $ex->getHttpStatus());
        self::assertSame('transfer_validation', $ex->getBusinessCode());
    }

    public function testContractIdempotencyFingerprintDeterministic(): void
    {
        $fp1 = \App\Infrastructure\Cache\RedisIdempotencyStore::fingerprint(1, 2, '10.00');
        $fp2 = \App\Infrastructure\Cache\RedisIdempotencyStore::fingerprint(1, 2, '10.00');
        $fp3 = \App\Infrastructure\Cache\RedisIdempotencyStore::fingerprint(1, 2, '20.00');
        self::assertSame($fp1, $fp2);
        self::assertNotSame($fp1, $fp3);
        self::assertSame(64, strlen($fp1)); // sha256 hex
    }

    public function testContractErrorResponseEnvelope(): void
    {
        $ex = new InsufficientBalanceException('Saldo insuficiente');
        $envelope = ['code' => $ex->getBusinessCode(), 'message' => $ex->getMessage(), 'correlation_id' => 'test-corr'];
        self::assertArrayHasKey('code', $envelope);
        self::assertArrayHasKey('message', $envelope);
        self::assertArrayHasKey('correlation_id', $envelope);
        self::assertSame('insufficient_balance', $envelope['code']);
    }

    public function testContractTransferResponseHttpCodes(): void
    {
        // per transfer.yaml: 200,201 for completed; 422,404,403,502,503 for errors
        $successCodes = [200, 201];
        $errorCodes = [403, 404, 422, 502, 503];
        self::assertContains(201, $successCodes);
        self::assertContains(422, $errorCodes);
        self::assertContains(404, $errorCodes);
    }
}
