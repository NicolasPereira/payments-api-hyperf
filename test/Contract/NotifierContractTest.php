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

namespace HyperfTest\Contract;

use App\Application\Notification\ProcessNotificationOutboxUseCase;
use App\Application\Transfer\ExecuteTransferUseCase;
use App\Application\User\CreateUserUseCase;
use App\Domain\Contracts\AuthorizerPort;
use App\Domain\Contracts\AuthorizerResult;
use App\Domain\Contracts\NotifierPort;
use App\Domain\Contracts\NotifyResult;
use App\Infrastructure\Cache\RedisIdempotencyStore;
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
 * T050 Contract test notifier — per contracts/external-services.md:18
 * 204 → sent; 4xx/5xx/timeout → pending retry, ≤3 attempts, não reverte saldo.
 *
 * Verifica ProcessNotificationOutboxUseCase com NotifierPort mockado:
 * - sent 204 marca sent
 * - 4xx/5xx/timeout marca pending com backoff 10s/60s e failed após 3
 * - saldo completed permanece (não reverte)
 *
 * @internal
 * @coversNothing
 */
final class NotifierContractTest extends TestCase
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

    public function testNotifyResultContract204Sent(): void
    {
        $sent = NotifyResult::sent(204, '');
        self::assertTrue($sent->isSent());
        self::assertSame(204, $sent->httpStatus);

        $sent200 = NotifyResult::sent(200, '{"ok":true}');
        self::assertTrue($sent200->isSent());

        $failed = NotifyResult::failed(500, '', 'Notifier returned HTTP 500');
        self::assertFalse($failed->isSent());
        self::assertSame(500, $failed->httpStatus);

        $failed0 = NotifyResult::failed(0, '', 'Notifier exception: timeout');
        self::assertFalse($failed0->isSent());
        self::assertSame(0, $failed0->httpStatus);
    }

    public function testOutbox204SentDoesNotRevertBalance(): void
    {
        if (! $this->isDbAvailable()) {
            self::markTestSkipped('DB not available');
        }

        [$payerId, $payeeId] = $this->createCommonPair('100.00', '10.00');
        // Create transfer directly via UseCase with authorized mock
        $transferResult = $this->executeTransfer($payerId, $payeeId, '10.00');
        $transferId = (int) $transferResult['transfer']['id'];

        // Balances after transfer: 90 / 20, completed não reverte mesmo se notifier falhar depois
        self::assertSame('90.00', $this->wallets->findByUserId($payerId)->getBalance()->getAmount());
        self::assertSame('20.00', $this->wallets->findByUserId($payeeId)->getBalance()->getAmount());

        $outboxRow = $this->outbox->findByTransferId($transferId);
        self::assertNotNull($outboxRow);
        self::assertSame('pending', $outboxRow->status);
        self::assertSame(0, (int) $outboxRow->attempts);

        // Mock notifier 204 → sent
        $notifier = $this->createMock(NotifierPort::class);
        $notifier->expects(self::once())->method('notify')->willReturn(NotifyResult::sent(204, ''));
        $processor = new ProcessNotificationOutboxUseCase($this->outbox, $notifier, new NullLogger());

        $result = $processor->processNext('corr-notify-204');
        self::assertNotNull($result);
        self::assertSame('sent', $result['status']);
        self::assertSame(204, $result['http_status']);

        // Outbox agora sent
        $after = $this->outbox->findByTransferId($transferId);
        self::assertSame('sent', $after->status);
        self::assertNotNull($after->sent_at);
        self::assertNull($after->lease_until);

        // Saldo permanece completed, não revertido
        self::assertSame('90.00', $this->wallets->findByUserId($payerId)->getBalance()->getAmount(), 'notifier 204 não reverte saldo');
        self::assertSame('20.00', $this->wallets->findByUserId($payeeId)->getBalance()->getAmount());

        $this->cleanUsers([$payerId, $payeeId]);
    }

    public function testOutbox4xxPendingRetryBackoff10s(): void
    {
        if (! $this->isDbAvailable()) {
            self::markTestSkipped('DB not available');
        }

        [$payerId, $payeeId] = $this->createCommonPair('70.00', '0.00');
        $transferResult = $this->executeTransfer($payerId, $payeeId, '10.00');
        $transferId = (int) $transferResult['transfer']['id'];

        $notifier = $this->createMock(NotifierPort::class);
        $notifier->method('notify')->willReturn(NotifyResult::failed(400, '{"error":"bad request"}', 'Notifier returned HTTP 400'));
        $processor = new ProcessNotificationOutboxUseCase($this->outbox, $notifier, new NullLogger());

        $result = $processor->processNext('corr-4xx');
        self::assertNotNull($result);
        self::assertSame('pending', $result['status'], '4xx deve ficar pending para retry');
        self::assertSame(1, $result['attempts']);
        self::assertSame(400, $result['http_status']);

        $row = $this->outbox->findByTransferId($transferId);
        self::assertSame('pending', $row->status);
        self::assertSame(1, (int) $row->attempts);
        self::assertNotNull($row->last_response);
        self::assertNull($row->lease_until);
        // available_at deve ser agora +10s
        $availableAt = strtotime((string) $row->available_at);
        $diff = $availableAt - time();
        self::assertGreaterThanOrEqual(8, $diff, 'backoff 1 deve ser ~10s');
        self::assertLessThanOrEqual(12, $diff);

        // Saldo não revertido
        self::assertSame('60.00', $this->wallets->findByUserId($payerId)->getBalance()->getAmount());
        self::assertSame('10.00', $this->wallets->findByUserId($payeeId)->getBalance()->getAmount());

        $this->cleanUsers([$payerId, $payeeId]);
    }

    public function testOutbox5xxPendingRetryBackoff60sOnSecondAttempt(): void
    {
        if (! $this->isDbAvailable()) {
            self::markTestSkipped('DB not available');
        }

        [$payerId, $payeeId] = $this->createCommonPair('80.00', '5.00');
        $transferResult = $this->executeTransfer($payerId, $payeeId, '20.00');
        $transferId = (int) $transferResult['transfer']['id'];

        // First attempt fails 5xx
        $notifierFail = $this->createMock(NotifierPort::class);
        $notifierFail->method('notify')->willReturn(NotifyResult::failed(500, '', 'Notifier returned HTTP 500'));
        $processorFail = new ProcessNotificationOutboxUseCase($this->outbox, $notifierFail, new NullLogger());
        $r1 = $processorFail->processNext('corr-5xx-1');
        self::assertSame('pending', $r1['status']);
        self::assertSame(1, $r1['attempts']);

        // Force available_at to now to allow second attempt immediately (simulate backoff elapsed)
        Db::table('notification_outbox')->where('transfer_id', $transferId)->update(['available_at' => date('Y-m-d H:i:s')]);

        // Second attempt also 5xx → pending with 60s backoff, attempts 2
        $r2 = $processorFail->processNext('corr-5xx-2');
        self::assertSame('pending', $r2['status']);
        self::assertSame(2, $r2['attempts']);

        $row = $this->outbox->findByTransferId($transferId);
        self::assertSame(2, (int) $row->attempts);
        $availableAt = strtotime((string) $row->available_at);
        $diff = $availableAt - time();
        self::assertGreaterThanOrEqual(58, $diff, 'backoff 2 deve ser ~60s');
        self::assertLessThanOrEqual(62, $diff);

        // Saldo não revertido após 2 falhas
        self::assertSame('60.00', $this->wallets->findByUserId($payerId)->getBalance()->getAmount());
        self::assertSame('25.00', $this->wallets->findByUserId($payeeId)->getBalance()->getAmount());

        $this->cleanUsers([$payerId, $payeeId]);
    }

    public function testOutboxTimeoutFailedAfterThreeAttempts(): void
    {
        if (! $this->isDbAvailable()) {
            self::markTestSkipped('DB not available');
        }

        [$payerId, $payeeId] = $this->createCommonPair('100.00', '0.00');
        $transferResult = $this->executeTransfer($payerId, $payeeId, '15.00');
        $transferId = (int) $transferResult['transfer']['id'];

        // Simulate timeout failures 3 times
        $notifierTimeout = $this->createMock(NotifierPort::class);
        $notifierTimeout->method('notify')->willReturn(NotifyResult::failed(0, '', 'Notifier exception: timeout'));

        $processor = new ProcessNotificationOutboxUseCase($this->outbox, $notifierTimeout, new NullLogger());

        // Attempt 1 → pending
        $r1 = $processor->processNext('corr-timeout-1');
        self::assertSame('pending', $r1['status']);
        self::assertSame(1, $r1['attempts']);
        Db::table('notification_outbox')->where('transfer_id', $transferId)->update(['available_at' => date('Y-m-d H:i:s')]);

        // Attempt 2 → pending
        $r2 = $processor->processNext('corr-timeout-2');
        self::assertSame('pending', $r2['status']);
        self::assertSame(2, $r2['attempts']);
        Db::table('notification_outbox')->where('transfer_id', $transferId)->update(['available_at' => date('Y-m-d H:i:s')]);

        // Attempt 3 → failed (max retries)
        $r3 = $processor->processNext('corr-timeout-3');
        self::assertSame('failed', $r3['status']);
        self::assertSame(3, $r3['attempts']);
        self::assertSame(0, $r3['http_status']); // timeout http 0

        $row = $this->outbox->findByTransferId($transferId);
        self::assertSame('failed', $row->status);
        self::assertSame(3, (int) $row->attempts);
        self::assertStringContainsString('timeout', strtolower((string) $row->last_response));

        // Saldo NÃO revertido mesmo após failed (fire-and-forget)
        self::assertSame('85.00', $this->wallets->findByUserId($payerId)->getBalance()->getAmount(), 'notifier failed após 3 não reverte saldo');
        self::assertSame('15.00', $this->wallets->findByUserId($payeeId)->getBalance()->getAmount());

        // Transfer permanece completed
        $transferRow = Db::table('transfers')->where('id', $transferId)->first();
        self::assertSame('completed', $transferRow->status);

        $this->cleanUsers([$payerId, $payeeId]);
    }

    public function testOutboxNeverMoreThan3Attempts(): void
    {
        if (! $this->isDbAvailable()) {
            self::markTestSkipped('DB not available');
        }

        [$payerId, $payeeId] = $this->createCommonPair('50.00', '0.00');
        $transferResult = $this->executeTransfer($payerId, $payeeId, '10.00');
        $transferId = (int) $transferResult['transfer']['id'];

        $notifier = $this->createMock(NotifierPort::class);
        $notifier->method('notify')->willReturn(NotifyResult::failed(500, 'err', '5xx'));

        $processor = new ProcessNotificationOutboxUseCase($this->outbox, $notifier, new NullLogger());

        for ($i = 1; $i <= 3; ++$i) {
            $res = $processor->processNext('corr-max-' . $i);
            if ($i < 3) {
                self::assertSame('pending', $res['status']);
                Db::table('notification_outbox')->where('transfer_id', $transferId)->update(['available_at' => date('Y-m-d H:i:s')]);
            } else {
                self::assertSame('failed', $res['status']);
            }
        }

        // Após failed, próximo processNext não deve reprocessar (attempts <3 filter)
        $next = $processor->processNext('corr-after-failed');
        self::assertNull($next, 'failed não deve ser reprocessado');

        $row = $this->outbox->findByTransferId($transferId);
        self::assertSame(3, (int) $row->attempts);
        self::assertSame('failed', $row->status);

        $this->cleanUsers([$payerId, $payeeId]);
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

    /**
     * @return array{transfer:array, notification:string, value:string, idempotency_key:string, correlation_id:string}
     */
    private function executeTransfer(int $payerId, int $payeeId, string $value): array
    {
        $authorizer = $this->createMock(AuthorizerPort::class);
        $authorizer->method('authorize')->willReturn(AuthorizerResult::authorized('{"status":"success","data":{"authorization":true}}'));
        $redis = $this->makeRedisMock();
        $fp = RedisIdempotencyStore::fingerprint($payerId, $payeeId, $value);
        try {
            $redis->delete($fp);
        } catch (Throwable) {
        }
        $useCase = new ExecuteTransferUseCase(
            $this->users,
            $this->wallets,
            $this->transfers,
            $this->outbox,
            $authorizer,
            $redis,
            new NullLogger()
        );
        return $useCase->execute(['value' => $value, 'payer' => $payerId, 'payee' => $payeeId]);
        // Store fingerprint for cleanup? Caller cleans wallets/users, idempotency key auto expires.
    }

    /**
     * @return array{0:int,1:int}
     */
    private function createCommonPair(string $payerBalance, string $payeeBalance): array
    {
        $payer = $this->createUser->execute([
            'full_name' => 'NotifyContract Payer ' . uniqid(),
            'document' => '529.982.247-25',
            'email' => 'notify_payer.' . uniqid() . '@example.com',
            'password' => 'password123',
            'type' => 'common',
        ]);
        $payee = $this->createUser->execute([
            'full_name' => 'NotifyContract Payee ' . uniqid(),
            'document' => '111.444.777-35',
            'email' => 'notify_payee.' . uniqid() . '@example.com',
            'password' => 'password123',
            'type' => 'common',
        ]);
        $payerId = $payer['user']->getId();
        $payeeId = $payee['user']->getId();
        Db::table('wallets')->where('user_id', $payerId)->update(['balance' => $payerBalance, 'updated_at' => date('Y-m-d H:i:s')]);
        Db::table('wallets')->where('user_id', $payeeId)->update(['balance' => $payeeBalance, 'updated_at' => date('Y-m-d H:i:s')]);
        return [$payerId, $payeeId];
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
