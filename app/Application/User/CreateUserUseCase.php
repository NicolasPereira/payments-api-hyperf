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

use App\Domain\Contracts\TransactionManager;
use App\Domain\Shared\ValueObject\Money;
use App\Domain\User\Entity\User;
use App\Domain\User\Entity\UserType;
use App\Domain\User\Exception\DuplicateDocumentException;
use App\Domain\User\ValueObject\DocumentFactory;
use App\Domain\User\ValueObject\Email;
use App\Domain\User\ValueObject\FullName;
use App\Domain\User\ValueObject\PasswordHash;
use App\Domain\User\ValueObject\PlainPassword;
use App\Domain\Wallet\Entity\Wallet;
use App\Infrastructure\Persistence\UserRepository;
use App\Infrastructure\Persistence\WalletRepository;

final class CreateUserUseCase
{
    public function __construct(
        private readonly UserRepository $users,
        private readonly WalletRepository $wallets,
        private readonly TransactionManager $transactionManager,
    ) {
    }

    /**
     * @param array{full_name:string, document:string, email:string, password:string, type:string} $input
     * @return array{user:User, wallet:Wallet}
     */
    public function execute(array $input): array
    {
        $userType = UserType::fromString((string) ($input['type'] ?? ''));
        $fullName = FullName::fromString((string) ($input['full_name'] ?? ''));
        $document = DocumentFactory::for($userType, (string) ($input['document'] ?? ''));
        $email = Email::fromString((string) ($input['email'] ?? ''));
        $passwordHash = PasswordHash::fromPlain(
            PlainPassword::fromString((string) ($input['password'] ?? ''))
        );

        $user = User::create($fullName, $document, $email, $passwordHash, $userType);

        if ($this->users->existsByDocument($document->getValue())) {
            throw new DuplicateDocumentException('Document already registered.');
        }
        if ($this->users->existsByEmail($email->getValue())) {
            throw new DuplicateDocumentException('Email already registered.');
        }

        return $this->transactionManager->transaction(function () use ($user) {
            $persisted = $this->users->create($user);
            $wallet = $this->wallets->createForUser((int) $persisted->id, Money::zero());

            return ['user' => $persisted, 'wallet' => $wallet];
        });
    }
}
