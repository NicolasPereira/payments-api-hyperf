<?php

declare(strict_types=1);

namespace App\Domain\Shared\ValueObject;

use App\Domain\Shared\Exception\DomainException;
use JsonSerializable;
use Stringable;

/**
 * Exact monetary value with 2 decimal places, stored without float.
 * Persistence as DECIMAL(15,2), domain as cents integer + string.
 */
final class Money implements JsonSerializable, Stringable
{
    private const PATTERN = '/^\d+\.\d{2}$/';

    private readonly string $amount;

    private readonly int $cents;

    private function __construct(string $amount, int $cents)
    {
        $this->amount = $amount;
        $this->cents = $cents;
    }

    /**
     * Create from exact decimal string like "10.00".
     *
     * @throws DomainException when format invalid
     */
    public static function fromString(string $amount): self
    {
        if ($amount === '' || preg_match(self::PATTERN, $amount) !== 1) {
            throw new class('Value must be string decimal with exactly 2 fractional digits, e.g. "10.00"', 'money_invalid_format', 422) extends DomainException {
            };
        }

        // Disallow values like "00.00"? Allow but ensure non-negative.
        // Use cents conversion without float.
        $parts = explode('.', $amount);
        $intPart = ltrim($parts[0], '0');
        if ($intPart === '') {
            $intPart = '0';
        }
        $centsStr = $intPart . $parts[1];

        // Guard overflow: DECIMAL(15,2) => max 9999999999999.99 => cents 999999999999999 < PHP_INT_MAX
        if (strlen($centsStr) > 15) {
            // cents string longer than 15 digits would exceed DECIMAL(15,2) when combined
            // Actually max cents 15 digits (999999999999999) fits, 16 would overflow
            if (strlen($centsStr) > 15 || (strlen($centsStr) === 15 && strcmp($centsStr, '999999999999999') > 0)) {
                throw new class('Value exceeds DECIMAL(15,2) maximum', 'money_overflow', 422) extends DomainException {
                };
            }
        }

        // Manual cents parse without int overflow risk for normal range
        $cents = (int) $centsStr;

        // Rebuild canonical amount to ensure no leading zeros distortion? Keep original but normalized?
        // Normalize amount: remove leading zeros from int part except keep one zero.
        $normalized = $intPart . '.' . $parts[1];

        return new self($normalized, $cents);
    }

    public static function fromCents(int $cents): self
    {
        if ($cents < 0) {
            throw new class('Money cents cannot be negative', 'money_negative', 422) extends DomainException {
            };
        }

        if ($cents > 999999999999999) {
            throw new class('Value exceeds DECIMAL(15,2) maximum', 'money_overflow', 422) extends DomainException {
            };
        }

        $intPart = intdiv($cents, 100);
        $fracPart = $cents % 100;

        $amount = sprintf('%d.%02d', $intPart, $fracPart);

        return new self($amount, $cents);
    }

    public static function zero(): self
    {
        return new self('0.00', 0);
    }

    public function getAmount(): string
    {
        return $this->amount;
    }

    public function getCents(): int
    {
        return $this->cents;
    }

    public function isZero(): bool
    {
        return $this->cents === 0;
    }

    public function isPositive(): bool
    {
        return $this->cents > 0;
    }

    public function equals(self $other): bool
    {
        return $this->cents === $other->cents;
    }

    public function greaterThan(self $other): bool
    {
        return $this->cents > $other->cents;
    }

    public function greaterThanOrEqual(self $other): bool
    {
        return $this->cents >= $other->cents;
    }

    public function lessThan(self $other): bool
    {
        return $this->cents < $other->cents;
    }

    public function add(self $other): self
    {
        $sum = $this->cents + $other->cents;
        if ($sum > 999999999999999) {
            throw new class('Money addition overflow DECIMAL(15,2)', 'money_overflow', 422) extends DomainException {
            };
        }

        return self::fromCents($sum);
    }

    public function subtract(self $other): self
    {
        $diff = $this->cents - $other->cents;
        if ($diff < 0) {
            throw new class('Money subtraction would be negative', 'money_negative', 422) extends DomainException {
            };
        }

        return self::fromCents($diff);
    }

    public function jsonSerialize(): string
    {
        return $this->amount;
    }

    public function __toString(): string
    {
        return $this->amount;
    }
}
