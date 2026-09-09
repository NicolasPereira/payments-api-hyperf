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

namespace App\Domain\Wallet\Entity;

use App\Domain\Shared\ValueObject\Money;
use InvalidArgumentException;

final class Wallet
{
    public function __construct(
        private readonly int $userId,
        private readonly Money $balance,
        private readonly ?int $id = null,
        private readonly ?string $createdAt = null,
        private readonly ?string $updatedAt = null,
    ) {
        if ($this->userId <= 0) {
            throw new InvalidArgumentException('user_id deve ser positivo');
        }
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getUserId(): int
    {
        return $this->userId;
    }

    public function getBalance(): Money
    {
        return $this->balance;
    }

    public function withBalance(Money $newBalance): self
    {
        return new self($this->userId, $newBalance, $this->id, $this->createdAt, $this->updatedAt);
    }

    public function withId(int $id): self
    {
        return new self($this->userId, $this->balance, $id, $this->createdAt, $this->updatedAt);
    }
}
