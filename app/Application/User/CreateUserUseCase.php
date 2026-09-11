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

namespace App\Application\User;

use App\Domain\Shared\Exception\DomainException;
use App\Domain\Shared\ValueObject\Money;
use App\Domain\User\Entity\User;
use App\Domain\User\Entity\UserType;
use App\Domain\User\Exception\DuplicateDocumentException;
use App\Domain\User\Exception\InvalidDocumentException;
use App\Domain\User\Exception\InvalidUserTypeException;
use App\Domain\User\ValueObject\DocumentFactory;
use App\Domain\User\ValueObject\Email;
use App\Domain\Wallet\Entity\Wallet;
use App\Infrastructure\Persistence\Database;
use App\Infrastructure\Persistence\UserRepository;
use App\Infrastructure\Persistence\WalletRepository;
use InvalidArgumentException;
use RuntimeException;

final class CreateUserUseCase
{
    public function __construct(
        private readonly UserRepository $users,
        private readonly WalletRepository $wallets,
    ) {
    }

    /**
     * @param array{full_name:string, document:string, email:string, password:string, type:string} $input
     * @return array{user:User, wallet:Wallet}
     */
    public function execute(array $input): array
    {
        $fullName = trim((string) ($input['full_name'] ?? ''));
        $rawDocument = (string) ($input['document'] ?? '');
        $rawEmail = (string) ($input['email'] ?? '');
        $plainPassword = (string) ($input['password'] ?? '');
        $rawType = (string) ($input['type'] ?? '');

        if ($fullName === '') {
            throw new class('full_name is required.', 'invalid_full_name', 422) extends DomainException {
            };
        }
        if (mb_strlen($plainPassword) < 8) {
            throw new class('Password must be at least 8 characters.', 'invalid_password', 422) extends DomainException {
            };
        }

        try {
            $userType = UserType::fromString($rawType);
        } catch (InvalidArgumentException $e) {
            throw new InvalidUserTypeException('Invalid user type.');
        }

        try {
            $document = DocumentFactory::for($userType, $rawDocument);
        } catch (InvalidDocumentException|InvalidUserTypeException $e) {
            throw $e;
        }

        $email = new Email($rawEmail);
        $passwordHash = $this->hashPassword($plainPassword);

        $user = new User(null, $fullName, $document, $email, $passwordHash, $userType);

        if ($this->users->existsByDocument($document->getValue())) {
            throw new DuplicateDocumentException('Document already registered.');
        }
        if ($this->users->existsByEmail($email->getValue())) {
            throw new DuplicateDocumentException('Email already registered.');
        }

        return Database::transaction(function () use ($user) {
            $persisted = $this->users->create($user);
            $wallet = $this->wallets->createForUser((int) $persisted->id, Money::zero());

            return ['user' => $persisted, 'wallet' => $wallet];
        });
    }

    private function hashPassword(string $plain): string
    {
        if (defined('PASSWORD_ARGON2ID')) {
            $hash = password_hash($plain, PASSWORD_ARGON2ID, [
                'memory_cost' => 1 << 16,
                'time_cost' => 4,
                'threads' => 1,
            ]);
            if (is_string($hash) && $hash !== '') {
                return $hash;
            }
        }
        $hash = password_hash($plain, PASSWORD_BCRYPT, ['cost' => 12]);
        if (! is_string($hash) || $hash === '') {
            throw new RuntimeException('Failed to hash password.');
        }

        return $hash;
    }
}
