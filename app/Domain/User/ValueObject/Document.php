<?php

declare(strict_types=1);

namespace App\Domain\User\ValueObject;

interface Document
{
    public function getValue(): string;

    public function getType(): DocumentType;

    public function equals(Document $other): bool;

    public function __toString(): string;
}
