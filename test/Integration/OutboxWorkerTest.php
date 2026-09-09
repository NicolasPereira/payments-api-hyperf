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

namespace HyperfTest\Integration;

use App\Application\Notification\ProcessNotificationOutboxUseCase;
use App\Application\Transfer\ExecuteTransferUseCase;
use App\Application\User\CreateUserUseCase;
use App\Domain\Contracts\AuthorizerPort;
use App\Domain\Contracts\AuthorizerResult;
use App\Domain\Contracts\NotifierPort;
use App\Domain\Contracts\NotifyResult;
use App\Infrastructure\Cache\RedisIdempotencyStore;
use App\Infrastructure\Notification\OutboxWorker;
use App\Infrastructure\Persistence\NotificationOutboxRepository;
use App\Infrastructure\Persistence\TransferRepository;
use App\Infrastructure\Persistence\UserRepository;
use App\Infrastructure\Persistence\WalletRepository;
use Hyperf\DbConnection\Db;
use Hyperf\Redis\RedisFactory;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Throwable;

/**
 * T051 Integration test Outbox worker — commit Transfer+Outbox atômico,
 * worker claim pending→processing→sent, lease expiry processing→pending,
 * failed após 3.
 *
 * @internal
 * @coversNothing
 */
final class OutboxWorkerTest extends TestCase
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

    public function testTransferAndOutboxAtomicCommit(): void
    {
        if (! $this->isDbAvailable()) {
            self::markTestSkipped('DB not available');
        }

        [$payerId, $payeeId] = $this->createCommonPair('100.00', '0.00');
        $authorizer = $this->createMock(AuthorizerPort::class);
        $authorizer->method('authorize')->willReturn(AuthorizerResult::authorized('{"status":"success","data":{"authorization":true}}'));
        $redis = $this->makeRedisMock();
        $fp = RedisIdempotencyStore::fingerprint($payerId, $payeeId, '10.00');
        $this->tryDeleteRedis($redis, $fp);

        $useCase = $this->makeTransferUseCase($authorizer, $redis);

        try {
            $beforeTransfers = (int) Db::table('transfers')->count();
            $beforeOutbox = (int) Db::table('notification_outbox')->count();

            $result = $useCase->execute(['value' => '10.00', 'payer' => $payerId, 'payee' => $payeeId, 'correlation_id' => 'corr-atomic']);

            // Ambos committed atomically na mesma transação
            self::assertSame($beforeTransfers + 1, (int) Db::table('transfers')->count(), 'transfer committed atomically');
            self::assertSame($beforeOutbox + 1, (int) Db::table('notification_outbox')->count(), 'outbox committed atomically');

            $transferRow = Db::table('transfers')->where('id', $result['transfer']['id'])->first();
            self::assertNotNull($transferRow);
            self::assertSame('completed', $transferRow->status);
            self::assertSame('corr-atomic', $transferRow->correlation_id);

            $outboxRow = Db::table('notification_outbox')->where('transfer_id', $transferRow->id)->first();
            self::assertNotNull($outboxRow, 'outbox must exist in same transaction');
            self::assertSame('pending', $outboxRow->status);
            self::assertSame((string) $payeeId, (string) $outboxRow->payee_id);
            self::assertSame(0, (int) $outboxRow->attempts);
            self::assertNull($outboxRow->lease_until);
            self::assertNotNull($outboxRow->available_at);

            // Saldo correto: payer 90, payee 10
            self::assertSame('90.00', $this->wallets->findByUserId($payerId)->getBalance()->getAmount());
            self::assertSame('10.00', $this->wallets->findByUserId($payeeId)->getBalance()->getAmount());
        } finally {
            $this->tryDeleteRedis($redis, $fp);
            $this->cleanUsers([$payerId, $payeeId]);
        }
    }

    public function testWorkerClaimPendingToProcessingToSent(): void
    {
        if (! $this->isDbAvailable()) {
            self::markTestSkipped('DB not available');
        }

        [$payerId, $payeeId] = $this->createCommonPair('70.00', '10.00');
        $transferId = $this->createTransferInline($payerId, $payeeId, '15.00');
        $outboxRow = $this->outbox->findByTransferId($transferId);
        self::assertNotNull($outboxRow);
        self::assertSame('pending', $outboxRow->status);

        // Worker com notifier 204 → pending→processing→sent
        $notifier = $this->createMock(NotifierPort::class);
        $notifier->method('notify')->willReturn(NotifyResult::sent(204, ''));
        $processor = new ProcessNotificationOutboxUseCase($this->outbox, $notifier, new NullLogger());
        $worker = new OutboxWorker($processor, new NullLogger());

        // Verify initial pending
        self::assertSame('pending', $outboxRow->status);
        self::assertNull($outboxRow->lease_until);

        // runOnce deve claim e sent
        $processed = $worker->runOnce('corr-worker-sent');
        self::assertSame(1, $processed, 'worker should process 1 pending→sent');

        $after = $this->outbox->findByTransferId($transferId);
        self::assertSame('sent', $after->status, 'pending→processing→sent');
        self::assertNotNull($after->sent_at);
        self::assertNull($after->lease_until);
        self::assertStringContainsString('204', (string) $after->last_response ?: '204');

        // Segunda chamada não deve reprocessar sent
        $processed2 = $worker->runOnce('corr-worker-sent2');
        self::assertSame(0, $processed2, 'sent não deve ser reprocessado');

        // Saldo intacto
        self::assertSame('55.00', $this->wallets->findByUserId($payerId)->getBalance()->getAmount());
        self::assertSame('25.00', $this->wallets->findByUserId($payeeId)->getBalance()->getAmount());

        $this->cleanUsers([$payerId, $payeeId]);
    }

    public function testLeaseExpiryProcessingToPending(): void
    {
        if (! $this->isDbAvailable()) {
            self::markTestSkipped('DB not available');
        }

        [$payerId, $payeeId] = $this->createCommonPair('60.00', '0.00');
        $transferId = $this->createTransferInline($payerId, $payeeId, '10.00');
        $outbox = $this->outbox->findByTransferId($transferId);
        self::assertNotNull($outbox);

        // Simula worker crash: coloca em processing com lease expirado (no passado)
        $pastLease = date('Y-m-d H:i:s', time() - 60);
        Db::table('notification_outbox')->where('id', $outbox->id)->update([
            'status' => NotificationOutboxRepository::STATUS_PROCESSING,
            'lease_until' => $pastLease,
            'updated_at' => $pastLease,
        ]);

        $stuck = Db::table('notification_outbox')->where('id', $outbox->id)->first();
        self::assertSame('processing', $stuck->status);
        self::assertSame($pastLease, $stuck->lease_until);

        // Processor deve liberar lease expirado → pending
        $notifier = $this->createMock(NotifierPort::class);
        $notifier->method('notify')->willReturn(NotifyResult::sent(204, ''));
        $processor = new ProcessNotificationOutboxUseCase($this->outbox, $notifier, new NullLogger());

        $released = $processor->releaseExpiredLeases();
        self::assertSame(1, $released, 'lease expiry processing→pending');

        $releasedRow = Db::table('notification_outbox')->where('id', $outbox->id)->first();
        self::assertSame('pending', $releasedRow->status, 'stuck processing deve voltar a pending após lease expiry');
        self::assertNull($releasedRow->lease_until);

        // Agora worker consegue processar normalmente → sent
        $worker = new OutboxWorker($processor, new NullLogger());
        $processed = $worker->runOnce('corr-lease-retry');
        self::assertSame(1, $processed);
        $final = $this->outbox->findByTransferId($transferId);
        self::assertSame('sent', $final->status);

        $this->cleanUsers([$payerId, $payeeId]);
    }

    public function testLeaseNotExpiredRemainsProcessing(): void
    {
        if (! $this->isDbAvailable()) {
            self::markTestSkipped('DB not available');
        }

        [$payerId, $payeeId] = $this->createCommonPair('50.00', '5.00');
        $transferId = $this->createTransferInline($payerId, $payeeId, '5.00');
        $outbox = $this->outbox->findByTransferId($transferId);
        $futureLease = date('Y-m-d H:i:s', time() + 30);
        Db::table('notification_outbox')->where('id', $outbox->id)->update([
            'status' => NotificationOutboxRepository::STATUS_PROCESSING,
            'lease_until' => $futureLease,
            'updated_at' => date('Y-m-d H:i:s'),
        ]);

        $notifier = $this->createMock(NotifierPort::class);
        $notifier->expects(self::never())->method('notify');
        $processor = new ProcessNotificationOutboxUseCase($this->outbox, $notifier, new NullLogger());

        // Lease ainda válido → não deve liberar
        $released = $processor->releaseExpiredLeases();
        self::assertSame(0, $released, 'lease futuro não deve expirar');

        $stillProcessing = Db::table('notification_outbox')->where('id', $outbox->id)->first();
        self::assertSame('processing', $stillProcessing->status);
        self::assertSame($futureLease, $stillProcessing->lease_until);

        // Worker runOnce não deve claim processing com lease válido
        $worker = new OutboxWorker($processor, new NullLogger());
        $processed = $worker->runOnce('corr-lease-valid');
        self::assertSame(0, $processed, 'processing com lease válido não deve ser re-claimed');

        // Limpa: força expiry e completa
        Db::table('notification_outbox')->where('id', $outbox->id)->update([
            'lease_until' => date('Y-m-d H:i:s', time() - 1),
        ]);
        $processor->releaseExpiredLeases();
        $notifier2 = $this->createMock(NotifierPort::class);
        $notifier2->method('notify')->willReturn(NotifyResult::sent(204, ''));
        $processor2 = new ProcessNotificationOutboxUseCase($this->outbox, $notifier2, new NullLogger());
        $worker2 = new OutboxWorker($processor2, new NullLogger());
        $worker2->runOnce('corr-cleanup');

        $this->cleanUsers([$payerId, $payeeId]);
    }

    public function testFailedAfterThreeAttempts(): void
    {
        if (! $this->isDbAvailable()) {
            self::markTestSkipped('DB not available');
        }

        [$payerId, $payeeId] = $this->createCommonPair('90.00', '0.00');
        $transferId = $this->createTransferInline($payerId, $payeeId, '10.00');

        $notifierFail = $this->createMock(NotifierPort::class);
        $notifierFail->method('notify')->willReturn(NotifyResult::failed(500, 'err', '5xx'));
        $processor = new ProcessNotificationOutboxUseCase($this->outbox, $notifierFail, new NullLogger());
        $worker = new OutboxWorker($processor, new NullLogger());

        // Attempt 1: pending → pending (retry)
        $worker->runOnce('corr-fail-1');
        $row1 = $this->outbox->findByTransferId($transferId);
        self::assertSame('pending', $row1->status);
        self::assertSame(1, (int) $row1->attempts);
        // Backoff 10s, force available_now
        Db::table('notification_outbox')->where('id', $row1->id)->update(['available_at' => date('Y-m-d H:i:s')]);

        // Attempt 2: pending → pending
        $worker->runOnce('corr-fail-2');
        $row2 = $this->outbox->findByTransferId($transferId);
        self::assertSame('pending', $row2->status);
        self::assertSame(2, (int) $row2->attempts);
        Db::table('notification_outbox')->where('id', $row2->id)->update(['available_at' => date('Y-m-d H:i:s')]);

        // Attempt 3: pending → failed
        $worker->runOnce('corr-fail-3');
        $row3 = $this->outbox->findByTransferId($transferId);
        self::assertSame('failed', $row3->status, 'failed após 3');
        self::assertSame(3, (int) $row3->attempts);

        // Após failed, worker não reprocessa
        $processedAfterFailed = $worker->runOnce('corr-after-failed');
        self::assertSame(0, $processedAfterFailed, 'failed não deve ser retentado');

        // Transfer permanece completed — não revertido
        $transferRow = Db::table('transfers')->where('id', $transferId)->first();
        self::assertSame('completed', $transferRow->status);
        self::assertSame('80.00', $this->wallets->findByUserId($payerId)->getBalance()->getAmount(), 'saldo não revertido após failed 3');

        $this->cleanUsers([$payerId, $payeeId]);
    }

    public function testWorkerProcessesBatchUpTo10(): void
    {
        if (! $this->isDbAvailable()) {
            self::markTestSkipped('DB not available');
        }

        // Cria 3 transfers distintos e verifica worker processa lote
        $notifier = $this->createMock(NotifierPort::class);
        $notifier->method('notify')->willReturn(NotifyResult::sent(204, ''));
        $processor = new ProcessNotificationOutboxUseCase($this->outbox, $notifier, new NullLogger());
        $worker = new OutboxWorker($processor, new NullLogger());

        $ids = [];
        $pairs = [];
        for ($i = 0; $i < 3; ++$i) {
            [$payerId, $payeeId] = $this->createCommonPair('100.00', '0.00');
            $pairs[] = [$payerId, $payeeId];
            $tid = $this->createTransferInline($payerId, $payeeId, sprintf('%d.00', 5 + $i));
            $ids[] = $tid;
        }

        $processed = $worker->runOnce('corr-batch');
        self::assertSame(3, $processed, 'worker batch deve processar todos os pending');

        foreach ($ids as $tid) {
            $row = $this->outbox->findByTransferId($tid);
            self::assertSame('sent', $row->status, sprintf('transfer %d deve estar sent', $tid));
        }

        foreach ($pairs as [$payerId, $payeeId]) {
            $this->cleanUsers([$payerId, $payeeId]);
        }
    }

    // Helpers

    private function isDbAvailable(): bool
    {
        try {
            Db::select('SELECT 1');
            return true;
        } catch (Throwable) {
            return false;
        }
    }

    private function makeRedisMock(): RedisIdempotencyStore
    {
        try {
            $factory = \Hyperf\Support\make(RedisFactory::class);
            $store = new RedisIdempotencyStore($factory, new NullLogger());
            $factory->get('default')->ping();
            return $store;
        } catch (Throwable) {
            $mock = $this->createMock(RedisIdempotencyStore::class);
            $mock->method('get')->willReturn(null);
            $mock->method('tryReserve')->willReturn(true);
            return $mock;
        }
    }

    private function tryDeleteRedis(RedisIdempotencyStore $redis, string $fp): void
    {
        try {
            $redis->delete($fp);
        } catch (Throwable) {
        }
    }

    private function makeTransferUseCase(AuthorizerPort $authorizer, RedisIdempotencyStore $redis): ExecuteTransferUseCase
    {
        return new ExecuteTransferUseCase(
            $this->users,
            $this->wallets,
            $this->transfers,
            $this->outbox,
            $authorizer,
            $redis,
            new NullLogger()
        );
    }

    private function createCommonPair(string $payerBalance, string $payeeBalance): array
    {
        // T062 polish: ensure unique document per run — clean leftover from previous test run (duplicate prevention)
        try {
            Db::statement('SET FOREIGN_KEY_CHECKS=0');
            Db::table('users')->whereIn('document', ['52998224725', '11144477735'])->delete();
            Db::statement('DELETE w FROM wallets w LEFT JOIN users u ON w.user_id = u.id WHERE u.id IS NULL');
            Db::statement('SET FOREIGN_KEY_CHECKS=1');
        } catch (Throwable) {
            try {
                Db::statement('SET FOREIGN_KEY_CHECKS=1');
            } catch (Throwable) {
            }
        }
        $payer = $this->createUser->execute([
            'full_name' => 'OutboxWorker Payer ' . uniqid(),
            'document' => '529.982.247-25',
            'email' => 'ow_payer.' . uniqid() . '@example.com',
            'password' => 'password123',
            'type' => 'common',
        ]);
        $payee = $this->createUser->execute([
            'full_name' => 'OutboxWorker Payee ' . uniqid(),
            'document' => '111.444.777-35',
            'email' => 'ow_payee.' . uniqid() . '@example.com',
            'password' => 'password123',
            'type' => 'common',
        ]);
        $payerId = $payer['user']->getId();
        $payeeId = $payee['user']->getId();
        Db::table('wallets')->where('user_id', $payerId)->update(['balance' => $payerBalance, 'updated_at' => date('Y-m-d H:i:s')]);
        Db::table('wallets')->where('user_id', $payeeId)->update(['balance' => $payeeBalance, 'updated_at' => date('Y-m-d H:i:s')]);
        return [$payerId, $payeeId];
    }

    private function createTransferInline(int $payerId, int $payeeId, string $value): int
    {
        $authorizer = $this->createMock(AuthorizerPort::class);
        $authorizer->method('authorize')->willReturn(AuthorizerResult::authorized('{"status":"success","data":{"authorization":true}}'));
        $redis = $this->makeRedisMock();
        $fp = RedisIdempotencyStore::fingerprint($payerId, $payeeId, $value);
        $this->tryDeleteRedis($redis, $fp);
        $useCase = $this->makeTransferUseCase($authorizer, $redis);
        $res = $useCase->execute(['value' => $value, 'payer' => $payerId, 'payee' => $payeeId]);
        return (int) $res['transfer']['id'];
    }

    /**
     * @param int[] $userIds
     */
    private function cleanUsers(array $userIds): void
    {
        foreach ($userIds as $uid) {
            try {
                Db::table('notification_outbox')->where('payee_id', $uid)->delete();
                Db::table('transfers')->where('payer_id', $uid)->orWhere('payee_id', $uid)->delete();
                Db::table('wallets')->where('user_id', $uid)->delete();
                Db::table('users')->where('id', $uid)->delete();
            } catch (Throwable) {
            }
        }
    }
}
