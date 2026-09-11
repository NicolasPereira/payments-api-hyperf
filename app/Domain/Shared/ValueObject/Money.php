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

namespace App\Domain\Shared\ValueObject;

use App\Domain\Transfer\Exception\TransferValidationException;
use JsonSerializable;
use Stringable;

final class Money implements JsonSerializable, Stringable
{
    private const PATTERN = '/^[0-9]+\.[0-9]{2}$/';

    private const MAX_CENTS = 999999999999999;

    private function __construct(
        private readonly string $amount,
        private readonly int $cents,
    ) {
    }

    public function __toString(): string
    {
        return $this->amount;
    }

    public static function fromString(string $amount): self
    {
        if (preg_match(self::PATTERN, $amount) !== 1) {
            throw new TransferValidationException('Value must match ^[0-9]+\.[0-9]{2}$.');
        }
        [$int, $dec] = explode('.', $amount);
        $normInt = ltrim($int, '0');
        if ($normInt === '') {
            $normInt = '0';
        }
        $normalized = $normInt . '.' . $dec;
        $cents = (int) ($normInt . $dec);
        if ($cents <= 0) {
            throw new TransferValidationException('Value must be greater than 0.00.');
        }
        if ($cents > self::MAX_CENTS) {
            throw new TransferValidationException('Value exceeds DECIMAL(15,2).');
        }

        return new self($normalized, $cents);
    }

    public static function fromCents(int $cents): self
    {
        if ($cents < 0) {
            throw new TransferValidationException('Money cannot be negative.');
        }
        $int = intdiv($cents, 100);
        $dec = str_pad((string) ($cents % 100), 2, '0', STR_PAD_LEFT);

        return new self($int . '.' . $dec, $cents);
    }

    public static function zero(): self
    {
        return new self('0.00', 0);
    }

    public function amount(): string
    {
        return $this->amount;
    }

    public function cents(): int
    {
        return $this->cents;
    }

    public function add(self $other): self
    {
        return self::fromCents($this->cents + $other->cents);
    }

    public function subtract(self $other): self
    {
        $result = $this->cents - $other->cents;
        if ($result < 0) {
            throw new TransferValidationException('Resulting balance cannot be negative.');
        }

        return self::fromCents($result);
    }

    public function isGreaterThan(self $other): bool
    {
        return $this->cents > $other->cents;
    }

    public function isGreaterThanOrEqual(self $other): bool
    {
        return $this->cents >= $other->cents;
    }

    public function jsonSerialize(): string
    {
        return $this->amount;
    }
}
