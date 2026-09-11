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

use App\Domain\User\Exception\InvalidUserTypeException;
use App\Domain\User\ValueObject\Document;
use App\Domain\User\ValueObject\Email;
use App\Domain\User\ValueObject\FullName;
use App\Domain\User\ValueObject\PasswordHash;

final class User
{
    public function __construct(
        public readonly ?int $id,
        public readonly FullName $fullName,
        public readonly Document $document,
        public readonly Email $email,
        public readonly PasswordHash $passwordHash,
        public readonly UserType $type,
    ) {
        if ($this->document->getType() !== $this->type->getDocumentType()) {
            throw new InvalidUserTypeException('Document type mismatch for user type.');
        }
    }

    public static function create(
        FullName $fullName,
        Document $document,
        Email $email,
        PasswordHash $passwordHash,
        UserType $type,
    ): self {
        return new self(null, $fullName, $document, $email, $passwordHash, $type);
    }

    public function isMerchant(): bool
    {
        return $this->type === UserType::MERCHANT;
    }

    public function isConsumer(): bool
    {
        return $this->type === UserType::CONSUMER;
    }

    public function withId(int $id): self
    {
        return new self($id, $this->fullName, $this->document, $this->email, $this->passwordHash, $this->type);
    }
}
