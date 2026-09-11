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

namespace HyperfTest\Unit\Application\Transfer;

use App\Application\Transfer\ExecuteTransferUseCase;
use App\Domain\Contracts\AuthorizerPort;
use App\Domain\Contracts\AuthorizerResult;
use App\Domain\Contracts\IdempotencyStore;
use App\Domain\Contracts\TransactionManager;
use App\Domain\Shared\ValueObject\Money;
use App\Domain\Transfer\Exception\AuthorizerDeniedException;
use App\Domain\User\Entity\User;
use App\Domain\User\Entity\UserType;
use App\Domain\User\ValueObject\DocumentConsumer;
use App\Domain\User\ValueObject\Email;
use App\Domain\User\ValueObject\FullName;
use App\Domain\User\ValueObject\PasswordHash;
use App\Domain\User\ValueObject\PlainPassword;
use App\Domain\Wallet\Entity\Wallet;
use App\Domain\Wallet\Exception\InsufficientBalanceException;
use App\Infrastructure\Persistence\NotificationOutboxRepository;
use App\Infrastructure\Persistence\TransferRepository;
use App\Infrastructure\Persistence\UserRepository;
use App\Infrastructure\Persistence\WalletRepository;
use PHPUnit\Framework\TestCase;

/**
 * T034 — ExecuteTransferUseCase with mocks.
 * @internal
 * @coversNothing
 */
final class ExecuteTransferTest extends TestCase
{
    public function testInsufficientBalanceThrowsWithoutMutation(): void
    {
        $users = $this->createMock(UserRepository::class);
        $users->method('findById')->willReturnCallback(
            fn (int $id) => $this->user($id, UserType::CONSUMER)
        );
        $wallets = $this->createMock(WalletRepository::class);
        $wallets->method('findByUserId')->willReturnCallback(
            fn (int $uid) => $uid === 1 ? new Wallet(null, 1, Money::fromString('5.00')) : Wallet::empty($uid)
        );
        $authorizer = $this->createMock(AuthorizerPort::class);
        $authorizer->expects($this->never())->method('authorize');
        $tx = $this->createMock(TransactionManager::class);
        $tx->expects($this->never())->method('transaction');
        $transfers = $this->createMock(TransferRepository::class);
        $transfers->expects($this->never())->method('create');

        $useCase = new ExecuteTransferUseCase(
            $users,
            $wallets,
            $transfers,
            $this->createMock(NotificationOutboxRepository::class),
            $this->idempotencyOwned(),
            $authorizer,
            $tx,
        );

        $this->expectException(InsufficientBalanceException::class);
        $useCase->execute(['value' => '10.00', 'payer' => 1, 'payee' => 2]);
    }

    public function testAuthorizedTransferCompletes(): void
    {
        $users = $this->createMock(UserRepository::class);
        $users->method('findById')->willReturnCallback(
            fn (int $id) => $this->user($id, UserType::CONSUMER)
        );
        $wallets = $this->createMock(WalletRepository::class);
        $wallets->method('findByUserId')->willReturnCallback(
            fn (int $uid) => $uid === 1 ? new Wallet(null, 1, Money::fromString('100.00')) : Wallet::empty($uid)
        );
        $wallets->method('lockForUpdate')->willReturnCallback(
            fn (array $ids) => [
                1 => new Wallet(null, 1, Money::fromString('100.00')),
                2 => Wallet::empty(2),
            ]
        );
        $transfers = $this->createMock(TransferRepository::class);
        $transfers->method('create')->willReturnCallback(
            fn ($t) => $t->withId(99)
        );
        $authorizer = $this->createMock(AuthorizerPort::class);
        $authorizer->method('authorize')->willReturn(AuthorizerResult::authorized());
        $tx = $this->createMock(TransactionManager::class);
        $tx->method('transaction')->willReturnCallback(fn (callable $work) => $work());

        $useCase = new ExecuteTransferUseCase(
            $users,
            $wallets,
            $transfers,
            $this->createMock(NotificationOutboxRepository::class),
            $this->idempotencyOwned(),
            $authorizer,
            $tx,
        );

        $result = $useCase->execute(['value' => '10.00', 'payer' => 1, 'payee' => 2]);
        $this->assertFalse($result['replay']);
        $this->assertSame('queued', $result['notification']);
        $this->assertSame(99, $result['transfer']->id);
    }

    public function testAuthorizerDeniedThrowsWithoutTransaction(): void
    {
        $users = $this->createMock(UserRepository::class);
        $users->method('findById')->willReturnCallback(
            fn (int $id) => $this->user($id, UserType::CONSUMER)
        );
        $wallets = $this->createMock(WalletRepository::class);
        $wallets->method('findByUserId')->willReturnCallback(
            fn (int $uid) => $uid === 1 ? new Wallet(null, 1, Money::fromString('100.00')) : Wallet::empty($uid)
        );
        $authorizer = $this->createMock(AuthorizerPort::class);
        $authorizer->method('authorize')->willReturn(AuthorizerResult::denied());
        $tx = $this->createMock(TransactionManager::class);
        $tx->expects($this->never())->method('transaction');

        $useCase = new ExecuteTransferUseCase(
            $users,
            $wallets,
            $this->createMock(TransferRepository::class),
            $this->createMock(NotificationOutboxRepository::class),
            $this->idempotencyOwned(),
            $authorizer,
            $tx,
        );

        $this->expectException(AuthorizerDeniedException::class);
        $useCase->execute(['value' => '10.00', 'payer' => 1, 'payee' => 2]);
    }

    private function user(int $id, UserType $type): User
    {
        return User::create(
            FullName::fromString('User ' . $id),
            new DocumentConsumer('529.982.247-25'),
            Email::fromString("user{$id}@example.com"),
            PasswordHash::fromPlain(PlainPassword::fromString('password123')),
            $type,
        )->withId($id);
    }

    private function idempotencyOwned(): IdempotencyStore
    {
        $store = $this->createMock(IdempotencyStore::class);
        $store->method('fingerprint')->willReturn('fp-test');
        $store->method('reserve')->willReturn(true);

        return $store;
    }
}
