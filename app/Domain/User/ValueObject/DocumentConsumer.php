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

use App\Domain\User\Exception\InvalidDocumentException;

final class DocumentConsumer implements Document
{
    private readonly string $value;

    public function __construct(string $raw)
    {
        $normalized = self::normalize($raw);
        if (preg_match('/^[0-9]{11}$/', $normalized) !== 1) {
            throw new InvalidDocumentException('Invalid CPF format.');
        }
        if (preg_match('/^(.)\1{10}$/', $normalized) === 1) {
            throw new InvalidDocumentException('Invalid CPF: repeated digits.');
        }
        if (! self::checkDigitsValid($normalized)) {
            throw new InvalidDocumentException('Invalid CPF check digits.');
        }
        $this->value = $normalized;
    }

    public function __toString(): string
    {
        return $this->value;
    }

    public static function normalize(string $raw): string
    {
        return (string) preg_replace('/[\.\-\/\s]/', '', $raw);
    }

    public function getValue(): string
    {
        return $this->value;
    }

    public function getType(): DocumentType
    {
        return DocumentType::CPF;
    }

    public function equals(Document $other): bool
    {
        return $other instanceof self && $other->value === $this->value;
    }

    private static function checkDigitsValid(string $cpf): bool
    {
        $d1 = self::digit(substr($cpf, 0, 9), 10);
        $d2 = self::digit(substr($cpf, 0, 9) . (string) $d1, 11);

        return $cpf[9] === (string) $d1 && $cpf[10] === (string) $d2;
    }

    private static function digit(string $digits, int $start): int
    {
        $sum = 0;
        foreach (str_split($digits) as $i => $ch) {
            $sum += ((int) $ch) * ($start - $i);
        }
        $r = $sum % 11;

        return $r < 2 ? 0 : 11 - $r;
    }
}
