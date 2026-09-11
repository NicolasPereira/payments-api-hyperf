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

namespace App\Domain\Transfer\ValueObject;

use App\Domain\Shared\ValueObject\Money;
use App\Domain\Transfer\Exception\TransferValidationException;

final class TransferValue
{
    private function __construct(private readonly Money $money)
    {
    }

    public static function fromString(string $raw): self
    {
        if (preg_match('/^(?!0+\.00$)[0-9]+\.[0-9]{2}$/', $raw) !== 1) {
            throw new TransferValidationException('Value must match ^(?!0+\.00$)[0-9]+\.[0-9]{2}$.');
        }

        return new self(Money::fromString($raw));
    }

    public function money(): Money
    {
        return $this->money;
    }

    public function amount(): string
    {
        return $this->money->amount();
    }

    public function equals(self $other): bool
    {
        return $other->money->cents() === $this->money->cents();
    }
}
