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

namespace App\Infrastructure\Http\Security;

/**
 * T062 Input validation boundaries + sanitização para logs (no PII/secrets).
 * Constitution V: Input validated at boundaries, sensitive info not logged, PII minimized.
 */
final class InputSanitizer
{
    /**
     * Sanitiza payload para log: mascara PII/secrets.
     * @param array<string,mixed> $data
     * @return array<string,mixed>
     */
    public static function forLog(array $data): array
    {
        $sanitized = $data;
        foreach (['password', 'password_hash', 'document', 'email', 'authorization', 'token', 'secret'] as $sensitive) {
            if (array_key_exists($sensitive, $sanitized)) {
                $val = $sanitized[$sensitive];
                if (is_string($val) && $val !== '') {
                    $len = strlen($val);
                    $sanitized[$sensitive] = $len <= 4 ? '***' : substr($val, 0, 2) . str_repeat('*', $len - 4) . substr($val, -2);
                } else {
                    $sanitized[$sensitive] = '***';
                }
            }
        }
        return $sanitized;
    }

    /**
     * Valida boundaries de criação de usuário (thin controller layer antes do UseCase).
     * @param array<string,mixed> $data
     * @return array<string,string> erros field => message (vazio se ok)
     */
    public static function validateUserInput(array $data): array
    {
        $errors = [];

        $fullName = $data['full_name'] ?? null;
        if (! is_string($fullName) || trim($fullName) === '') {
            $errors['full_name'] = 'full_name é obrigatório';
        } elseif (mb_strlen(trim($fullName)) < 3) {
            $errors['full_name'] = 'full_name deve ter ao menos 3 caracteres';
        } elseif (mb_strlen($fullName) > 255) {
            $errors['full_name'] = 'full_name deve ter no máximo 255 caracteres';
        }

        $document = $data['document'] ?? null;
        if (! is_string($document) || $document === '') {
            $errors['document'] = 'document é obrigatório';
        } elseif (mb_strlen($document) > 30) {
            $errors['document'] = 'document excede tamanho máximo (30 com formatação)';
        } elseif (mb_strlen(trim($document)) < 11) {
            $errors['document'] = 'document muito curto';
        }

        $email = $data['email'] ?? null;
        if (! is_string($email) || $email === '') {
            $errors['email'] = 'email é obrigatório';
        } elseif (mb_strlen($email) > 255) {
            $errors['email'] = 'email deve ter no máximo 255 caracteres';
        } elseif (filter_var(trim($email), FILTER_VALIDATE_EMAIL) === false) {
            $errors['email'] = 'email formato inválido';
        }

        $password = $data['password'] ?? null;
        if (! is_string($password) || $password === '') {
            $errors['password'] = 'password é obrigatório';
        } elseif (mb_strlen($password) < 8) {
            $errors['password'] = 'password deve ter no mínimo 8 caracteres';
        } elseif (mb_strlen($password) > 128) {
            $errors['password'] = 'password deve ter no máximo 128 caracteres';
        }

        $type = $data['type'] ?? null;
        if (! is_string($type) || ! in_array($type, ['common', 'merchant'], true)) {
            $errors['type'] = 'type deve ser common ou merchant';
        }

        return $errors;
    }

    /**
     * Valida boundaries de transferência.
     * @param array<string,mixed> $data
     * @return array<string,string>
     */
    public static function validateTransferInput(array $data): array
    {
        $errors = [];

        $value = $data['value'] ?? null;
        if (! is_string($value)) {
            $errors['value'] = 'value deve ser string decimal exata ex: "10.00"';
        } elseif (preg_match('/^(?!0+\.00$)[0-9]+\.[0-9]{2}$/', $value) !== 1) {
            $errors['value'] = 'value deve ser string decimal positiva com 2 casas ex: "10.00"';
        } elseif (strlen($value) > 20) {
            $errors['value'] = 'value excede tamanho máximo';
        }

        $payer = $data['payer'] ?? null;
        if ($payer === null) {
            $errors['payer'] = 'payer é obrigatório';
        } elseif (! self::isPositiveInt($payer)) {
            $errors['payer'] = 'payer deve ser integer positivo';
        } elseif ((int) $payer > 2147483647) {
            $errors['payer'] = 'payer fora do intervalo permitido';
        }

        $payee = $data['payee'] ?? null;
        if ($payee === null) {
            $errors['payee'] = 'payee é obrigatório';
        } elseif (! self::isPositiveInt($payee)) {
            $errors['payee'] = 'payee deve ser integer positivo';
        } elseif ((int) $payee > 2147483647) {
            $errors['payee'] = 'payee fora do intervalo permitido';
        }

        if (! isset($errors['payer']) && ! isset($errors['payee']) && (int) $payer === (int) $payee) {
            $errors['payer'] = 'payer e payee não podem ser iguais';
        }

        return $errors;
    }

    private static function isPositiveInt(mixed $v): bool
    {
        if (is_int($v) && $v > 0) {
            return true;
        }
        if (is_string($v) && ctype_digit($v) && (int) $v > 0) {
            return true;
        }
        return false;
    }
}
