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

use App\Domain\User\Entity\UserType;
use App\Domain\User\Exception\InvalidUserTypeException;

final class DocumentFactory
{
    public static function for(UserType $type, string $raw): Document
    {
        return match ($type) {
            UserType::COMMON => self::asCommon($raw),
            UserType::MERCHANT => self::asMerchant($raw),
        };
    }

    private static function asCommon(string $raw): Document
    {
        $probe = strtoupper((string) preg_replace('/[\.\-\/\s]/', '', $raw));
        if (preg_match('/^[A-Z0-9]{12}[0-9]{2}$/', $probe) === 1 && preg_match('/^[0-9]{11}$/', $probe) !== 1) {
            throw new InvalidUserTypeException('Common user requires CPF.');
        }
        if (preg_match('/[A-Z]/', $probe) === 1) {
            throw new InvalidUserTypeException('Common user requires CPF.');
        }
        if (strlen($probe) === 14 && ctype_digit($probe)) {
            throw new InvalidUserTypeException('Common user requires CPF.');
        }

        return new DocumentConsumer($raw);
    }

    private static function asMerchant(string $raw): Document
    {
        $probe = strtoupper((string) preg_replace('/[\.\-\/\s]/', '', $raw));
        if (preg_match('/^[0-9]{11}$/', $probe) === 1) {
            throw new InvalidUserTypeException('Merchant requires CNPJ.');
        }

        return new DocumentMerchant($raw);
    }
}
