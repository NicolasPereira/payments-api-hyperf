<?php

declare(strict_types=1);

namespace App\Domain\User\ValueObject;

use App\Domain\User\Entity\UserType;
use App\Domain\User\Exception\InvalidDocumentException;
use App\Domain\User\Exception\InvalidUserTypeException;

final class DocumentFactory
{
    /**
     * Polymorphic factory per data-model.md:13 — hides CPF/CNPJ behind Document.
     *
     * @throws InvalidDocumentException
     * @throws InvalidUserTypeException
     */
    public static function for(UserType $type, string $raw): Document
    {
        return match ($type) {
            UserType::COMMON => new DocumentConsumer($raw),
            UserType::MERCHANT => new DocumentMerchant($raw),
        };
    }

    /**
     * Alias for `for` to allow `create` naming in some contexts.
     */
    public static function create(string $raw, UserType $type): Document
    {
        return self::for($type, $raw);
    }
}
