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

use App\Domain\User\Exception\InvalidPasswordException;

final class PlainPassword
{
    private function __construct(private readonly string $value)
    {
    }

    public static function fromString(string $raw): self
    {
        if (mb_strlen($raw) < 8) {
            throw new InvalidPasswordException('Password must be at least 8 characters.');
        }

        return new self($raw);
    }

    public function getValue(): string
    {
        return $this->value;
    }

    public function erase(): void
    {
        // Best-effort: caller should unset; kept explicit for intent.
    }
}
