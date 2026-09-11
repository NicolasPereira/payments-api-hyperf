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

final class Wallet
{
    public function __construct(
        public readonly ?int $id,
        public readonly int $userId,
        private Money $balance,
    ) {
    }

    public static function empty(int $userId): self
    {
        return new self(null, $userId, Money::zero());
    }

    public function balance(): Money
    {
        return $this->balance;
    }

    public function canAfford(Money $value): bool
    {
        return $this->balance->isGreaterThanOrEqual($value);
    }

    public function debit(Money $value): void
    {
        $this->balance = $this->balance->subtract($value);
    }

    public function credit(Money $value): void
    {
        $this->balance = $this->balance->add($value);
    }
}
