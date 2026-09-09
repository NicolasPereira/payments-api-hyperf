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

use App\Domain\Shared\Exception\DomainException;

final class Email
{
    private readonly string $value;

    public function __construct(string $raw)
    {
        $normalized = self::normalize($raw);

        if ($normalized === '' || filter_var($normalized, FILTER_VALIDATE_EMAIL) === false) {
            throw new class('E-mail inválido', 'invalid_email', 422) extends DomainException {
            };
        }

        // Additional basic length check per RFC, but filter already covers
        if (strlen($normalized) > 255) {
            throw new class('E-mail inválido — tamanho excede 255', 'invalid_email', 422) extends DomainException {
            };
        }

        $this->value = $normalized;
    }

    public function __toString(): string
    {
        return $this->value;
    }

    public static function normalize(string $raw): string
    {
        return strtolower(trim($raw));
    }

    public function getValue(): string
    {
        return $this->value;
    }

    public function equals(self $other): bool
    {
        return $this->value === $other->value;
    }
}
