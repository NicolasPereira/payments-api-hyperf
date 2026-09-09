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

use App\Domain\Shared\Exception\DomainException;
use App\Domain\Shared\ValueObject\Money;
use App\Domain\Transfer\Exception\TransferValidationException;

/**
 * T037 TransferValue — parses string "100.00" → cents/DECIMAL with strict pattern.
 * Pattern: ^(?!0+\.00$)[0-9]+\.[0-9]{2}$ per contracts/transfer.yaml:75
 * Rejects number type, zero, negative, >2 decimals.
 */
final class TransferValue
{
    private const PATTERN = '/^(?!0+\.00$)[0-9]+\.[0-9]{2}$/';

    private readonly string $amount;

    private readonly int $cents;

    private readonly Money $money;

    private function __construct(string $amount, int $cents, Money $money)
    {
        $this->amount = $amount;
        $this->cents = $cents;
        $this->money = $money;
    }

    public function __toString(): string
    {
        return $this->amount;
    }

    /**
     * Strictly from string — validates pattern and positive.
     *
     * @throws TransferValidationException
     */
    public static function fromString(string $amount): self
    {
        if (preg_match(self::PATTERN, $amount) !== 1) {
            throw new TransferValidationException(
                'Value must be string decimal with exactly 2 fractional digits and > 0.00, e.g. "10.00"'
            );
        }

        // Use Money for cents conversion without float; Money allows 0.00 but TransferValue forbids it via pattern above.
        try {
            $money = Money::fromString($amount);
        } catch (DomainException $e) {
            throw new TransferValidationException($e->getMessage(), $e);
        }

        if ($money->isZero()) {
            throw new TransferValidationException('Value must be > 0.00');
        }

        return new self($money->getAmount(), $money->getCents(), $money);
    }

    /**
     * Accepts mixed value to enforce string type (rejects number 10.0, int, etc.).
     *
     * @throws TransferValidationException
     */
    public static function fromMixed(mixed $value): self
    {
        if (! is_string($value)) {
            throw new TransferValidationException(
                sprintf('Value must be string decimal ex: "10.00", got %s', get_debug_type($value))
            );
        }

        return self::fromString($value);
    }

    public static function fromMoney(Money $money): self
    {
        if (! $money->isPositive()) {
            throw new TransferValidationException('Value must be positive');
        }

        return new self($money->getAmount(), $money->getCents(), $money);
    }

    public function getAmount(): string
    {
        return $this->amount;
    }

    public function getCents(): int
    {
        return $this->cents;
    }

    public function getMoney(): Money
    {
        return $this->money;
    }

    public function toMoney(): Money
    {
        return $this->money;
    }

    public function equals(self $other): bool
    {
        return $this->cents === $other->cents;
    }
}
