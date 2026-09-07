<?php

declare(strict_types=1);

namespace App\Domain\User\Entity;

enum UserType: string
{
    case COMMON = 'common';
    case MERCHANT = 'merchant';

    public function getDocumentType(): \App\Domain\User\ValueObject\DocumentType
    {
        return match ($this) {
            self::COMMON => \App\Domain\User\ValueObject\DocumentType::CPF,
            self::MERCHANT => \App\Domain\User\ValueObject\DocumentType::CNPJ,
        };
    }
}
