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
use App\Domain\User\ValueObject\DocumentType;
use App\Domain\User\ValueObject\Email;
use InvalidArgumentException;

final class User
{
    public function __construct(
        private readonly string $fullName,
        private readonly Document $document,
        private readonly Email $email,
        private readonly string $passwordHash,
        private readonly UserType $type,
        private readonly ?int $id = null,
        private readonly ?string $createdAt = null,
        private readonly ?string $updatedAt = null,
    ) {
        if (trim($this->fullName) === '') {
            throw new InvalidArgumentException('full_name não pode ser vazio');
        }

        if ($this->passwordHash === '') {
            throw new InvalidArgumentException('passwordHash não pode ser vazio');
        }

        // Ensure document type matches user type invariant (common→cpf, merchant→cnpj)
        $expected = $this->type->getDocumentType();
        if ($this->document->getType() !== $expected) {
            throw new InvalidUserTypeException(
                'Tipo de documento incompatível com tipo de usuário'
            );
        }
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getFullName(): string
    {
        return $this->fullName;
    }

    public function getDocument(): Document
    {
        return $this->document;
    }

    public function getEmail(): Email
    {
        return $this->email;
    }

    public function getPasswordHash(): string
    {
        return $this->passwordHash;
    }

    public function getType(): UserType
    {
        return $this->type;
    }

    public function getDocumentType(): DocumentType
    {
        return $this->document->getType();
    }

    public function withId(int $id): self
    {
        return new self(
            $this->fullName,
            $this->document,
            $this->email,
            $this->passwordHash,
            $this->type,
            $id,
            $this->createdAt,
            $this->updatedAt,
        );
    }
}
