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
use RuntimeException;

final class PasswordHash
{
    private function __construct(private readonly string $value)
    {
    }

    public function __toString(): string
    {
        return $this->value;
    }

    public static function fromPlain(PlainPassword $plain): self
    {
        if (defined('PASSWORD_ARGON2ID')) {
            $hash = password_hash($plain->getValue(), PASSWORD_ARGON2ID, [
                'memory_cost' => 1 << 16,
                'time_cost' => 4,
                'threads' => 1,
            ]);
            if (is_string($hash) && $hash !== '') {
                return new self($hash);
            }
        }
        $hash = password_hash($plain->getValue(), PASSWORD_BCRYPT, ['cost' => 12]);
        if (! is_string($hash) || $hash === '') {
            throw new RuntimeException('Failed to hash password.');
        }

        return new self($hash);
    }

    public static function fromHash(string $hash): self
    {
        if ($hash === '') {
            throw new InvalidPasswordException('Empty password hash.');
        }

        return new self($hash);
    }

    public function getValue(): string
    {
        return $this->value;
    }
}
