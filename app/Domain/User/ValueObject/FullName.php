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

namespace App\Domain\User\ValueObject;

use App\Domain\User\Exception\InvalidFullNameException;

final class FullName
{
    private function __construct(private readonly string $value)
    {
    }

    public function __toString(): string
    {
        return $this->value;
    }

    public static function fromString(string $raw): self
    {
        $value = trim($raw);
        if ($value === '') {
            throw new InvalidFullNameException('full_name is required.');
        }
        if (mb_strlen($value) > 255) {
            throw new InvalidFullNameException('full_name must not exceed 255 characters.');
        }

        return new self($value);
    }

    public function getValue(): string
    {
        return $this->value;
    }

    public function equals(self $other): bool
    {
        return $other->value === $this->value;
    }
}
