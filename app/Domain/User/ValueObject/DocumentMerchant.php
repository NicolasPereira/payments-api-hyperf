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

/**
 * CNPJ value object — identity for `merchant` users.
 * Supports legacy numeric ^[0-9]{14}$ (subset) and alphanumeric IN 2.229/2024 ^[A-Z0-9]{12}[0-9]{2}$.
 * Normalization: strip .-/ and spaces + uppercase.
 * DV: ASCII-48 (0-9=0-9, A=17..Z=42), weights 2-9 repeating right-to-left, modulo 11.
 * Rejects all-equal sequences extended for alfa: AAAA..., 111..., etc.
 */
final class DocumentMerchant implements Document
{
    private readonly string $value;

    public function __construct(string $raw)
    {
        $normalized = self::normalize($raw);

        if (! preg_match('/^[A-Z0-9]{12}[0-9]{2}$/', $normalized)) {
            throw new InvalidDocumentException('Documento inválido — formato CNPJ deve conter 14 posições ^[A-Z0-9]{12}[0-9]{2}$');
        }

        if (self::isAllEqual($normalized)) {
            throw new InvalidDocumentException('Documento inválido — CNPJ com sequência repetida');
        }

        // Also reject first 12 chars all equal (e.g., AAAAAAAAAAAA00 case from spec edge)
        $base12 = substr($normalized, 0, 12);
        if (preg_match('/^(.)\1{11}$/', $base12) === 1) {
            throw new InvalidDocumentException('Documento inválido — CNPJ com sequência repetida');
        }

        if (! self::hasValidCheckDigits($normalized)) {
            throw new InvalidDocumentException('Documento inválido — dígitos verificadores CNPJ inválidos');
        }

        $this->value = $normalized;
    }

    public function __toString(): string
    {
        return $this->value;
    }

    public static function normalize(string $raw): string
    {
        $stripped = preg_replace('/[\.\-\/\s]/', '', $raw);
        return strtoupper($stripped ?? '');
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
        return $other instanceof self && $this->value === $other->getValue();
    }

    private static function isAllEqual(string $value): bool
    {
        return preg_match('/^(.)\1{13}$/', $value) === 1;
    }

    private static function hasValidCheckDigits(string $cnpj): bool
    {
        $base12 = substr($cnpj, 0, 12);
        $expectedDv = self::calcDv($base12);
        $actualDv = substr($cnpj, 12, 2);

        return $expectedDv === $actualDv;
    }

    /**
     * Calculate the two DVs for a 12-char base using ASCII-48 + weights 2-9.
     */
    private static function calcDv(string $base12): string
    {
        $d1 = self::calcSingleDigit($base12);
        $d2 = self::calcSingleDigit($base12 . (string) $d1);

        return (string) $d1 . (string) $d2;
    }

    private static function calcSingleDigit(string $chars): int
    {
        $sum = 0;
        $weight = 2;
        // iterate from rightmost to leftmost
        for ($i = strlen($chars) - 1; $i >= 0; --$i) {
            $c = $chars[$i];
            $val = ord($c) - 48; // ASCII-48: '0'=0 .. '9'=9, 'A'=17 .. 'Z'=42
            $sum += $val * $weight;
            ++$weight;
            if ($weight > 9) {
                $weight = 2;
            }
        }
        $rest = $sum % 11;

        return $rest < 2 ? 0 : 11 - $rest;
    }
}
