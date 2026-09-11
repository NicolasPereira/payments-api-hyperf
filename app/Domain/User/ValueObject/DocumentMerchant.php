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

final class DocumentMerchant implements Document
{
    private readonly string $value;

    public function __construct(string $raw)
    {
        $normalized = self::normalize($raw);
        if (preg_match('/^[A-Z0-9]{12}[0-9]{2}$/', $normalized) !== 1) {
            throw new InvalidDocumentException('Invalid CNPJ format.');
        }
        if (preg_match('/^(.)\1{13}$/', $normalized) === 1) {
            throw new InvalidDocumentException('Invalid CNPJ: repeated sequence.');
        }
        if (preg_match('/^(.)\1{11}$/', substr($normalized, 0, 12)) === 1) {
            throw new InvalidDocumentException('Invalid CNPJ: repeated base.');
        }
        if (! self::checkDigitsValid($normalized)) {
            throw new InvalidDocumentException('Invalid CNPJ check digits.');
        }
        $this->value = $normalized;
    }

    public function __toString(): string
    {
        return $this->value;
    }

    public static function normalize(string $raw): string
    {
        $stripped = (string) preg_replace('/[\.\-\/\s]/', '', $raw);

        return strtoupper($stripped);
    }

    public function getValue(): string
    {
        return $this->value;
    }

    public function getType(): DocumentType
    {
        return DocumentType::CNPJ;
    }

    public function equals(Document $other): bool
    {
        return $other instanceof self && $other->value === $this->value;
    }

    private static function checkDigitsValid(string $cnpj): bool
    {
        $base = substr($cnpj, 0, 12);
        $d1 = self::single($base);
        $d2 = self::single($base . (string) $d1);

        return substr($cnpj, 12, 2) === ((string) $d1 . (string) $d2);
    }

    private static function single(string $chars): int
    {
        $sum = 0;
        $weight = 2;
        for ($i = strlen($chars) - 1; $i >= 0; --$i) {
            $sum += (ord($chars[$i]) - 48) * $weight;
            $weight = $weight === 9 ? 2 : $weight + 1;
        }
        $r = $sum % 11;

        return $r < 2 ? 0 : 11 - $r;
    }
}
