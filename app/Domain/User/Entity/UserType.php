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

namespace App\Domain\User\Entity;

use App\Domain\User\ValueObject\DocumentType;
use InvalidArgumentException;

enum UserType: string
{
    case COMMON = 'common';
    case MERCHANT = 'merchant';

    public function getDocumentType(): DocumentType
    {
        return match ($this) {
            self::COMMON => DocumentType::CPF,
            self::MERCHANT => DocumentType::CNPJ,
        };
    }

    public static function fromString(string $raw): self
    {
        return match (strtolower(trim($raw))) {
            'common' => self::COMMON,
            'merchant' => self::MERCHANT,
            default => throw new InvalidArgumentException('Invalid user type.'),
        };
    }
}
