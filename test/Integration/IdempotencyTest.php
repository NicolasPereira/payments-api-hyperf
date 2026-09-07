<?php

declare(strict_types=1);

namespace HyperfTest\Integration;

use App\Application\Transfer\ExecuteTransferUseCase;
use App\Application\User\CreateUserUseCase;
use App\Domain\Contracts\AuthorizerPort;
use App\Domain\Contracts\AuthorizerResult;
use App\Infrastructure\Cache\RedisIdempotencyStore;
use App\Infrastructure\Persistence\NotificationOutboxRepository;
use App\Infrastructure\Persistence\TransferRepository;
use App\Infrastructure\Persistence\UserRepository;
use App\Infrastructure\Persistence\WalletRepository;
use Hyperf\DbConnection\Db;
use Hyperf\Redis\RedisFactory;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * T043 Integration test idempotency Redis
 * (2× mesmo payer+payee+value em 3min → mesmo resultado sem duplo débito, TTL 180s)
 */
final class IdempotencyTest extends TestCase
{
    private UserRepository $users;
    private WalletRepository $wallets;
    private TransferRepository $transfers;
    private NotificationOutboxRepository $outbox;
    private CreateUserUseCase $createUser;

    protected function setUp(): void
    {
        parent::setUp();
        $this->users = new UserRepository();
        $this->wallets = new WalletRepository();
        $this->transfers = new TransferRepository();
        $this->outbox = new NotificationOutboxRepository();
        $this->createUser = new CreateUserUseCase($this->users, $this->wallets);
    }

    private function isDbAvailable(): bool
    {
        try {
            Db::select('SELECT 1');
            return true;
        } catch (\Throwable) {
            return false;
        }
    }

    private function isRedisAvailable(): ?RedisIdempotencyStore
    {
        try {
            $factory = \Hyperf\Support\make(RedisFactory::class);
            $store = new RedisIdempotencyStore($factory, new NullLogger());
            $factory->get('default')->ping();
            return $store;
        } catch (\Throwable) {
            return null;
        }
    }

    private function createCommonUser(string $document, string $email): \App\Domain\User\Entity\User
    {
        $result = $this->createUser->execute([
            'full_name' => 'Idemp User ' . uniqid(),
            'document' => $document,
            'email' => $email,
            'password' => 'password123',
            'type' => 'common',
        ]);

        return $result['user'];
    }

    private function setWalletBalance(int $userId, string $amount): void
    {
        Db::table('wallets')->where('user_id', $userId)->update(['balance' => $amount, 'updated_at' => date('Y-m-d H:i:s')]);
    }

    private function cleanUser(int $userId): void
    {
        try {
            Db::table('notification_outbox')->where('payee_id', $userId)->delete();
            Db::table('transfers')->where('payer_id', $userId)->orWhere('payee_id', $userId)->delete();
            Db::table('wallets')->where('user_id', $userId)->delete();
            Db::table('users')->where('id', $userId)->delete();
        } catch (\Throwable) {
        }
    }

    public function testIdempotentSamePayerPayeeValueReturnsSameResultNoDoubleDebit(): void
    {
        if (!$this->isDbAvailable()) {
            self::markTestSkipped('DB not available');
        }

        $redis = $this->isRedisAvailable();
        if ($redis === null) {
            // Fallback to mock behavior test without real Redis: verify fingerprint deterministic and UseCase handles mock storeResult
            self::markTestSkipped('Redis not available — idempotency requires Redis; mock fallback test skipped');
        }

        $payer = $this->createCommonUser('529.982.247-25', 'idemp_payer.' . uniqid() . '@example.com');
        $payee = $this->createCommonUser('111.444.777-35', 'idemp_payee.' . uniqid() . '@example.com');
        $payerId = $payer->getId();
        $payeeId = $payee->getId();

        $this->setWalletBalance($payerId, '100.00');
        $this->setWalletBalance($payeeId, '50.00');

        $authorizer = $this->createMock(AuthorizerPort::class);
        $authorizer->method('authorize')->willReturn(AuthorizerResult::authorized('{"status":"success","data":{"authorization":true}}'));

        $useCase = new ExecuteTransferUseCase(
            $this->users,
            $this->wallets,
            $this->transfers,
            $this->outbox,
            $authorizer,
            $redis,
            new NullLogger()
        );

        $value = '10.00';
        $fingerprint = RedisIdempotencyStore::fingerprint($payerId, $payeeId, $value);
        // Ensure clean
        $redis->delete($fingerprint);

        $first = $useCase->execute(['value' => $value, 'payer' => $payerId, 'payee' => $payeeId]);
        $second = $useCase->execute(['value' => $value, 'payer' => $payerId, 'payee' => $payeeId]);

        // Same result (same transfer id, same value)
        self::assertSame($first['transfer']['id'], $second['transfer']['id'], 'Idempotent second call must return same transfer id');
        self::assertSame($first['transfer']['value'], $second['transfer']['value']);
        self::assertSame($first['notification'], $second['notification']);
        self::assertSame($first['idempotency_key'], $second['idempotency_key']);
        self::assertSame($fingerprint, $first['idempotency_key']);

        // No double debit: balances debited only once
        $payerWallet = $this->wallets->findByUserId($payerId);
        $payeeWallet = $this->wallets->findByUserId($payeeId);
        self::assertSame('90.00', $payerWallet->getBalance()->getAmount(), 'Payer should be 90.00 (100-10 once)');
        self::assertSame('60.00', $payeeWallet->getBalance()->getAmount(), 'Payee should be 60.00 (50+10 once)');

        // Only one transfer row for this fingerprint
        $count = (int) Db::table('transfers')->where('idempotency_key', $fingerprint)->count();
        self::assertSame(1, $count, 'Only one transfer should exist for fingerprint');

        // TTL 180s — check Redis TTL
        $factory = \Hyperf\Support\make(RedisFactory::class);
        $redisRaw = $factory->get('default');
        $ttl = $redisRaw->ttl(RedisIdempotencyStore::key($fingerprint));
        self::assertGreaterThan(0, $ttl, 'TTL should be >0');
        self::assertLessThanOrEqual(180, $ttl, 'TTL should be <=180');
        self::assertGreaterThanOrEqual(170, $ttl, 'TTL should be near 180 after immediate second call');

        // Cleanup
        $redis->delete($fingerprint);
        $this->cleanUser($payerId);
        $this->cleanUser($payeeId);
    }

    public function testDifferentValueCreatesDifferentFingerprint(): void
    {
        $fp1 = RedisIdempotencyStore::fingerprint(1, 2, '10.00');
        $fp2 = RedisIdempotencyStore::fingerprint(1, 2, '20.00');
        self::assertNotSame($fp1, $fp2);
    }

    public function testIdempotencyFingerprintHashDeterministic(): void
    {
        $fp1 = RedisIdempotencyStore::fingerprint(5, 9, '100.00');
        $fp2 = RedisIdempotencyStore::fingerprint(5, 9, '100.00');
        self::assertSame($fp1, $fp2);
        self::assertSame(hash('sha256', '5:9:100.00'), $fp1);
    }

    public function testIdempotencyMockWithoutRedisStillPreventsDoubleDebit(): void
    {
        // Unit-style fallback: with mocked Redis that returns cached result, UseCase should not double debit
        if (!$this->isDbAvailable()) {
            self::markTestSkipped('DB not available');
        }

        $payer = $this->createCommonUser('529.982.247-25', 'idemp_mock_payer.' . uniqid() . '@example.com');
        $payee = $this->createCommonUser('111.444.777-35', 'idemp_mock_payee.' . uniqid() . '@example.com');
        $payerId = $payer->getId();
        $payeeId = $payee->getId();

        $this->setWalletBalance($payerId, '100.00');
        $this->setWalletBalance($payeeId, '0.00');

        $authorizer = $this->createMock(AuthorizerPort::class);
        $authorizer->method('authorize')->willReturn(AuthorizerResult::authorized('ok'));

        // Create a mock Redis that simulates hit on second call
        $redis = $this->createMock(RedisIdempotencyStore::class);
        $fingerprint = RedisIdempotencyStore::fingerprint($payerId, $payeeId, '10.00');
        $cachedResult = json_encode([
            'transfer' => ['id' => 999, 'value' => '10.00', 'payer' => $payerId, 'payee' => $payeeId, 'status' => 'completed'],
            'notification' => 'queued',
            'value' => '10.00',
            'idempotency_key' => $fingerprint,
            'correlation_id' => 'test',
        ]);
        // First call: get returns null, tryReserve true → proceeds to transaction
        // Second call: get returns cached → returns without transaction
        // For this test we simulate second call only: we inject redis that returns cached and verify UseCase returns cached without hitting authorizer

        $redis->method('get')->willReturn($cachedResult);
        $redis->method('tryReserve')->willReturn(false);
        $redis->expects(self::never())->method('storeResult');

        $authorizerHit = $this->createMock(AuthorizerPort::class);
        $authorizerHit->expects(self::never())->method('authorize');

        $useCaseHit = new ExecuteTransferUseCase(
            $this->users,
            $this->wallets,
            $this->transfers,
            $this->outbox,
            $authorizerHit,
            $redis,
            new NullLogger()
        );

        $result = $useCaseHit->execute(['value' => '10.00', 'payer' => $payerId, 'payee' => $payeeId]);
        self::assertSame(999, $result['transfer']['id'], 'Should return cached idempotent result without calling authorizer');

        // Balances must not have been debited (still 100/0)
        self::assertSame('100.00', $this->wallets->findByUserId($payerId)->getBalance()->getAmount());
        self::assertSame('0.00', $this->wallets->findByUserId($payeeId)->getBalance()->getAmount());

        $this->cleanUser($payerId);
        $this->cleanUser($payeeId);
    }
}
