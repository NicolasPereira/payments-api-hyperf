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

use App\Domain\User\ValueObject\Document;
use App\Domain\User\ValueObject\Email;

final class User
{
    public function __construct(
        public readonly ?int $id,
        public readonly string $fullName,
        public readonly Document $document,
        public readonly Email $email,
        public readonly string $passwordHash,
        public readonly UserType $type,
    ) {
    }

    public function isMerchant(): bool
    {
        return $this->type === UserType::MERCHANT;
    }

    public function isCommon(): bool
    {
        return $this->type === UserType::COMMON;
    }
}
