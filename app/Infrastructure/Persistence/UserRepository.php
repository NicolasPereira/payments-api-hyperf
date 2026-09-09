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

    public function findByDocument(string $normalizedDocument): ?User
    {
        $row = Db::table('users')->where('document', $normalizedDocument)->first();
        if ($row === null) {
            return null;
        }

        return $this->hydrate($row);
    }

    public function findByEmail(string $normalizedEmail): ?User
    {
        $row = Db::table('users')->where('email', $normalizedEmail)->first();
        if ($row === null) {
            return null;
        }

        return $this->hydrate($row);
    }

    public function existsByDocument(string $normalizedDocument): bool
    {
        return Db::table('users')->where('document', $normalizedDocument)->exists();
    }

    public function existsByEmail(string $normalizedEmail): bool
    {
        return Db::table('users')->where('email', $normalizedEmail)->exists();
    }

    /**
     * Persist user and return with id. Caller must handle transaction for User+Wallet atomicity.
     */
    public function create(User $user): User
    {
        $id = (int) Db::table('users')->insertGetId([
            'full_name' => $user->getFullName(),
            'document_type' => $user->getDocumentType()->value,
            'document' => $user->getDocument()->getValue(),
            'email' => $user->getEmail()->getValue(),
            'password_hash' => $user->getPasswordHash(),
            'type' => $user->getType()->value,
            'created_at' => date('Y-m-d H:i:s'),
            'updated_at' => date('Y-m-d H:i:s'),
        ]);

        return $user->withId($id);
    }

    /**
     * Hydrate DB row into domain User.
     */
    private function hydrate(array|object $row): User
    {
        $r = is_array($row) ? (object) $row : $row;

        $type = UserType::from($r->type);
        $document = DocumentFactory::for($type, (string) $r->document);
        $email = new Email((string) $r->email);

        return new User(
            (string) $r->full_name,
            $document,
            $email,
            (string) $r->password_hash,
            $type,
            (int) $r->id,
            isset($r->created_at) ? (string) $r->created_at : null,
            isset($r->updated_at) ? (string) $r->updated_at : null,
        );
    }
}
