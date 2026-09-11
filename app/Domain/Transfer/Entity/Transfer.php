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

use App\Domain\Transfer\Exception\MerchantPayerNotAllowedException;
use App\Domain\Transfer\Exception\SelfTransferException;
use App\Domain\Transfer\Exception\TransferValidationException;
use App\Domain\Transfer\ValueObject\TransferValue;
use App\Domain\User\Entity\UserType;

final class Transfer
{
    private function __construct(
        public readonly ?int $id,
        public readonly int $payerId,
        public readonly int $payeeId,
        public readonly TransferValue $value,
        public readonly TransferStatus $status,
        public readonly ?string $idempotencyKey = null,
        public readonly ?string $correlationId = null,
    ) {
    }

    public static function create(
        int $payerId,
        int $payeeId,
        TransferValue $value,
        UserType $payerType,
        ?string $idempotencyKey = null,
        ?string $correlationId = null,
    ): self {
        if ($payerId <= 0 || $payeeId <= 0) {
            throw new TransferValidationException('Payer and payee must be positive ids.');
        }
        if ($payerId === $payeeId) {
            throw new SelfTransferException();
        }
        if ($payerType !== UserType::CONSUMER) {
            throw new MerchantPayerNotAllowedException();
        }

        return new self(null, $payerId, $payeeId, $value, TransferStatus::PENDING, $idempotencyKey, $correlationId);
    }

    public function markAuthorized(): self
    {
        return $this->transitionTo(TransferStatus::AUTHORIZED);
    }

    public function markCompleted(): self
    {
        return $this->transitionTo(TransferStatus::COMPLETED);
    }

    public function markFailed(): self
    {
        $next = $this->status === TransferStatus::PENDING || $this->status === TransferStatus::AUTHORIZED
            ? TransferStatus::FAILED
            : throw new TransferValidationException('Terminal transfer cannot transition.');

        return new self($this->id, $this->payerId, $this->payeeId, $this->value, $next, $this->idempotencyKey, $this->correlationId);
    }

    public function withId(int $id): self
    {
        return new self($id, $this->payerId, $this->payeeId, $this->value, $this->status, $this->idempotencyKey, $this->correlationId);
    }

    private function transitionTo(TransferStatus $next): self
    {
        if (! $this->status->canTransitionTo($next)) {
            throw new TransferValidationException("Cannot transition from {$this->status->value} to {$next->value}.");
        }

        return new self($this->id, $this->payerId, $this->payeeId, $this->value, $next, $this->idempotencyKey, $this->correlationId);
    }
}
