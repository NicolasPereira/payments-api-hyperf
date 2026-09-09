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
 * CPF value object — identity for `common` users.
 * Normalization: strip .-/ and spaces; pattern ^[0-9]{11}$;
 * Rejects all-equal sequences; validates DV modulo 11.
 * Pure domain, no I/O.
 */
final class DocumentConsumer implements Document
{
    private readonly string $value;

    public function __construct(string $raw)
    {
        $normalized = self::normalize($raw);

        if (! preg_match('/^[0-9]{11}$/', $normalized)) {
            throw new InvalidDocumentException('Documento inválido — formato CPF deve conter 11 dígitos');
        }

        if (self::isAllEqual($normalized)) {
            throw new InvalidDocumentException('Documento inválido — CPF com todos dígitos iguais');
        }

        if (! self::hasValidCheckDigits($normalized)) {
            throw new InvalidDocumentException('Documento inválido — dígitos verificadores CPF inválidos');
        }

        $this->value = $normalized;
    }

    public function __toString(): string
    {
        return $this->value;
    }

    public static function normalize(string $raw): string
    {
        // strip . - / and spaces (including "\t", "\n"), keep only digits then validate
        $stripped = preg_replace('/[\.\-\/\s]/', '', $raw);
        return $stripped ?? '';
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
        return $other instanceof self && $this->value === $other->getValue();
    }

    private static function isAllEqual(string $value): bool
    {
        return preg_match('/^(.)\1{10}$/', $value) === 1;
    }

    private static function hasValidCheckDigits(string $cpf): bool
    {
        // CPF has 11 digits: first 9 base + 2 DVs
        $base9 = substr($cpf, 0, 9);
        $dv1 = self::calcCpfDigit($base9, 10);
        $dv2 = self::calcCpfDigit($base9 . (string) $dv1, 11);

        return $cpf[9] === (string) $dv1 && $cpf[10] === (string) $dv2;
    }

    private static function calcCpfDigit(string $digits, int $weightStart): int
    {
        $sum = 0;
        $len = strlen($digits);
        for ($i = 0; $i < $len; ++$i) {
            $sum += ((int) $digits[$i]) * ($weightStart - $i);
        }
        $rest = $sum % 11;

        return $rest < 2 ? 0 : 11 - $rest;
    }
}
