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

namespace App\Domain\Transfer\Entity;

use App\Domain\Shared\ValueObject\Money;
use App\Domain\Transfer\Exception\MerchantPayerNotAllowedException;
use App\Domain\Transfer\Exception\SelfTransferException;
use App\Domain\Transfer\Exception\TransferValidationException;
use App\Domain\User\Entity\UserType;

/**
 * Transfer — FR-006 / FR-007 per spec.md (T047)
 * - FR-006: common→common e common→merchant permitidos; payee pode ser common OU merchant (sem restrição).
 * - FR-007: merchant payer bloqueado → payerType === MERCHANT lança MerchantPayerNotAllowedException 403 merchant_payer_blocked.
 * Payee type é intencionalmente NÃO validado para permitir lojista como recebedor (US2).
 */
final class Transfer
{
    public function __construct(
        private readonly Money $value,
        private readonly int $payerId,
        private readonly int $payeeId,
        private readonly TransferStatus $status = TransferStatus::COMPLETED,
        private readonly ?int $id = null,
        private readonly ?string $idempotencyKey = null,
        private readonly ?string $correlationId = null,
        private readonly ?string $authorizationResult = null,
        private readonly ?string $createdAt = null,
        private readonly ?string $updatedAt = null,
        private readonly ?UserType $payerType = null,
    ) {
        if ($payerId <= 0 || $payeeId <= 0) {
            throw new TransferValidationException('payer e payee devem ser ids positivos');
        }

        if ($payerId === $payeeId) {
            throw new SelfTransferException();
        }

        if (! $this->value->isPositive()) {
            throw new TransferValidationException('value deve ser positivo > 0.00');
        }

        // FR-007: payer merchant bloqueado (403 merchant_payer_blocked)
        // FR-006: payee merchant explicitamente permitido — nenhuma checagem de payee type
        if ($this->payerType !== null && $this->payerType === UserType::MERCHANT) {
            throw new MerchantPayerNotAllowedException();
        }
    }

    /**
     * Factory that validates payer type for common→common / common→merchant rules.
     * - FR-006: payee merchant permitido (não valida payee type)
     * - FR-007: payer merchant bloqueado via constructor guard
     * Used by tests T033 and UseCase.
     */
    public static function createForCommonPayer(Money $value, int $payerId, UserType $payerType, int $payeeId, TransferStatus $status = TransferStatus::COMPLETED): self
    {
        return new self($value, $payerId, $payeeId, $status, null, null, null, null, null, null, $payerType);
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getValue(): Money
    {
        return $this->value;
    }

    public function getPayerId(): int
    {
        return $this->payerId;
    }

    public function getPayeeId(): int
    {
        return $this->payeeId;
    }

    public function getStatus(): TransferStatus
    {
        return $this->status;
    }

    public function getIdempotencyKey(): ?string
    {
        return $this->idempotencyKey;
    }

    public function getCorrelationId(): ?string
    {
        return $this->correlationId;
    }

    public function getAuthorizationResult(): ?string
    {
        return $this->authorizationResult;
    }

    public function getCreatedAt(): ?string
    {
        return $this->createdAt;
    }

    public function getUpdatedAt(): ?string
    {
        return $this->updatedAt;
    }

    public function withId(int $id): self
    {
        return new self(
            $this->value,
            $this->payerId,
            $this->payeeId,
            $this->status,
            $id,
            $this->idempotencyKey,
            $this->correlationId,
            $this->authorizationResult,
            $this->createdAt,
            $this->updatedAt,
            $this->payerType,
        );
    }

    public function withStatus(TransferStatus $status): self
    {
        return new self(
            $this->value,
            $this->payerId,
            $this->payeeId,
            $status,
            $this->id,
            $this->idempotencyKey,
            $this->correlationId,
            $this->authorizationResult,
            $this->createdAt,
            $this->updatedAt,
            $this->payerType,
        );
    }

    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'value' => $this->value->getAmount(),
            'payer' => $this->payerId,
            'payee' => $this->payeeId,
            'status' => $this->status->value,
            'idempotency_key' => $this->idempotencyKey,
            'correlation_id' => $this->correlationId,
        ];
    }
}
