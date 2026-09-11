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

namespace App\Infrastructure\Persistence;

use App\Domain\User\Entity\User;
use App\Domain\User\Entity\UserType;
use App\Domain\User\ValueObject\DocumentFactory;
use App\Domain\User\ValueObject\Email;
use App\Domain\User\ValueObject\FullName;
use App\Domain\User\ValueObject\PasswordHash;
use Hyperf\DbConnection\Db;

class UserRepository
{
    public function findById(int $id): ?User
    {
        $row = Db::table('users')->where('id', $id)->first();
        if ($row === null) {
            return null;
        }

        return $this->hydrate($row);
    }

    public function existsByDocument(string $document): bool
    {
        return Db::table('users')->where('document', $document)->exists();
    }

    public function existsByEmail(string $email): bool
    {
        return Db::table('users')->where('email', $email)->exists();
    }

    public function create(User $user): User
    {
        $id = (int) Db::table('users')->insertGetId([
            'full_name' => $user->fullName->getValue(),
            'document_type' => $user->type->getDocumentType()->value,
            'document' => $user->document->getValue(),
            'email' => $user->email->getValue(),
            'password_hash' => $user->passwordHash->getValue(),
            'type' => $user->type->value,
            'created_at' => date('Y-m-d H:i:s'),
            'updated_at' => date('Y-m-d H:i:s'),
        ]);

        return $user->withId($id);
    }

    private function hydrate(array|object $row): User
    {
        $r = is_array($row) ? (object) $row : $row;
        $type = UserType::fromString((string) $r->type);

        return new User(
            isset($r->id) ? (int) $r->id : null,
            FullName::fromString((string) $r->full_name),
            DocumentFactory::for($type, (string) $r->document),
            Email::fromString((string) $r->email),
            PasswordHash::fromHash((string) $r->password_hash),
            $type,
        );
    }
}
