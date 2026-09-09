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
use RuntimeException;
use ValueError;

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
     *
     * @throws DomainException
     */
    public function execute(array $input): array
    {
        // 1. Pure domain validation before any I/O (FR-024)
        $fullName = trim((string) ($input['full_name'] ?? ''));
        $rawDocument = (string) ($input['document'] ?? '');
        $rawEmail = (string) ($input['email'] ?? '');
        $plainPassword = (string) ($input['password'] ?? '');
        $rawType = (string) ($input['type'] ?? '');

        if ($fullName === '') {
            throw new class('full_name é obrigatório', 'invalid_full_name', 422) extends DomainException {
            };
        }

        if ($plainPassword === '' || mb_strlen($plainPassword) < 8) {
            throw new class('Senha deve ter no mínimo 8 caracteres', 'invalid_password', 422) extends DomainException {
            };
        }

        try {
            $userType = UserType::from($rawType);
        } catch (ValueError $e) {
            throw new InvalidUserTypeException('Tipo de usuário inválido — deve ser common ou merchant');
        }

        // Document validation via VO (normalization + DV). Must happen before I/O.
        try {
            $document = DocumentFactory::for($userType, $rawDocument);
        } catch (InvalidDocumentException $e) {
            throw $e;
        } catch (InvalidUserTypeException $e) {
            throw $e;
        }

        // Email validation pure
        $email = new Email($rawEmail);

        // Password hash with ARGON2ID fallback BCRYPT (research Decision 10)
        $passwordHash = $this->hashPassword($plainPassword);

        // Domain User aggregate (also validates document-type compatibility redundantly)
        $user = new User($fullName, $document, $email, $passwordHash, $userType);

        // 2. Uniqueness checks (I/O) — after domain validation, before persistence
        $normalizedDoc = $document->getValue();
        $normalizedEmail = $email->getValue();

        if ($this->users->existsByDocument($normalizedDoc)) {
            throw new DuplicateDocumentException('Documento já cadastrado');
        }

        if ($this->users->existsByEmail($normalizedEmail)) {
            // Reuse 409 business code; spec expects 409 for duplicate email as well
            throw new DuplicateDocumentException('E-mail já cadastrado');
        }

        // 3. Atomic User + Wallet persistence (plan.md:136, data-model.md:113)
        return Database::transaction(function () use ($user) {
            $persistedUser = $this->users->create($user);
            $wallet = $this->wallets->createForUser($persistedUser->getId() ?? 0, Money::zero());

            return ['user' => $persistedUser, 'wallet' => $wallet];
        });
    }

    private function hashPassword(string $plain): string
    {
        // Primary: ARGON2ID with memory 64MiB (65536 KiB), time 4, threads 1
        if (defined('PASSWORD_ARGON2ID')) {
            $options = [
                'memory_cost' => 1 << 16, // 65536 KiB = 64 MiB
                'time_cost' => 4,
                'threads' => 1,
            ];
            $hash = password_hash($plain, PASSWORD_ARGON2ID, $options);
            if (is_string($hash) && $hash !== '') {
                return $hash;
            }
        }

        // Fallback: BCRYPT cost 12
        $hash = password_hash($plain, PASSWORD_BCRYPT, ['cost' => 12]);
        if (! is_string($hash) || $hash === '') {
            throw new RuntimeException('Falha ao gerar hash de senha');
        }

        return $hash;
    }
}
