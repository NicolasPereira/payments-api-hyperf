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
use App\Domain\Shared\Exception\DomainException;
use App\Infrastructure\Cache\RedisIdempotencyStore;
use App\Infrastructure\Persistence\NotificationOutboxRepository;
use App\Infrastructure\Persistence\TransferRepository;
use App\Infrastructure\Persistence\UserRepository;
use App\Infrastructure\Persistence\WalletRepository;
use Hyperf\DbConnection\Db;
use Hyperf\Redis\RedisFactory;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use RuntimeException;
use Throwable;

/**
 * T057 Integration test resiliência completa —
 * authorizer indisponível 503 sem débito,
 * notifier timeout mantém completed,
 * idempotency Redis loss mantém audit MySQL.
 *
 * @internal
 * @coversNothing
 */
final class ExternalResilienceTest extends TestCase
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

    public function testAuthorizer503NoDebit(): void
    {
        if (! $this->isDbAvailable()) {
            self::markTestSkipped('DB not available');
        }

        // Authorizer indisponível (503) → sem débito, sem transfer/outbox, saldo intacto
        [$payerId, $payeeId] = $this->createCommonPair('100.00', '20.00');
        $authorizer503 = $this->createMock(AuthorizerPort::class);
        $authorizer503->method('authorize')->willReturn(
            AuthorizerResult::failed('', 'authorizer_unavailable', 'Authorizer unavailable: 503')
        );
        $redis = $this->makeRedisMock();
        $fp = RedisIdempotencyStore::fingerprint($payerId, $payeeId, '30.00');
        $this->tryDeleteRedis($redis, $fp);

        $useCase503 = $this->makeTransferUseCase($authorizer503, $redis);

        $transfersBefore = (int) Db::table('transfers')->count();
        $outboxBefore = (int) Db::table('notification_outbox')->count();

        try {
            $useCase503->execute(['value' => '30.00', 'payer' => $payerId, 'payee' => $payeeId, 'correlation_id' => 'corr-resilience-503']);
            self::fail('Expected 503 DomainException for authorizer unavailable');
        } catch (DomainException $e) {
            self::assertSame(503, $e->getHttpStatus(), 'authorizer 503 deve mapear para 503');
            self::assertSame('authorizer_unavailable', $e->getBusinessCode());
        }

        // Sem débito: prova que authorizer bloqueia mutação antes da transação (T052)
        self::assertSame('100.00', $this->wallets->findByUserId($payerId)->getBalance()->getAmount(), '503 sem débito');
        self::assertSame('20.00', $this->wallets->findByUserId($payeeId)->getBalance()->getAmount(), '503 sem crédito');
        self::assertSame($transfersBefore, (int) Db::table('transfers')->count(), 'nenhum transfer em 503');
        self::assertSame($outboxBefore, (int) Db::table('notification_outbox')->count(), 'nenhum outbox em 503');

        // Também testa 5xx genérico sem débito
        $authorizer500 = $this->createMock(AuthorizerPort::class);
        $authorizer500->method('authorize')->willReturn(
            AuthorizerResult::failed('{"error":500}', 'authorizer_upstream_error', 'Authorizer returned HTTP 500')
        );
        $useCase500 = $this->makeTransferUseCase($authorizer500, $redis);
        try {
            $useCase500->execute(['value' => '10.00', 'payer' => $payerId, 'payee' => $payeeId]);
            self::fail('Expected 502 for upstream 5xx');
        } catch (DomainException $e) {
            self::assertContains($e->getHttpStatus(), [502, 503]);
        }
        self::assertSame('100.00', $this->wallets->findByUserId($payerId)->getBalance()->getAmount(), '5xx sem mutação');

        $this->tryDeleteRedis($redis, $fp);
        $this->cleanUsers([$payerId, $payeeId]);
    }

    public function testNotifierTimeoutKeepsCompleted(): void
    {
        if (! $this->isDbAvailable()) {
            self::markTestSkipped('DB not available');
        }

        // Transfer completa com sucesso; notifier timeout → mantém completed, outbox pending retry, não reverte saldo
        [$payerId, $payeeId] = $this->createCommonPair('200.00', '30.00');
        $transferResult = $this->executeTransferSuccess($payerId, $payeeId, '25.00', 'corr-timeout-keep');
        $transferId = (int) $transferResult['transfer']['id'];

        // Verificações pós-transfer
        self::assertSame('completed', Db::table('transfers')->where('id', $transferId)->first()->status);
        self::assertSame('175.00', $this->wallets->findByUserId($payerId)->getBalance()->getAmount(), 'débito aplicado antes do notify');
        self::assertSame('55.00', $this->wallets->findByUserId($payeeId)->getBalance()->getAmount());

        $outboxBefore = $this->outbox->findByTransferId($transferId);
        self::assertSame('pending', $outboxBefore->status);

        // Notifier timeout → pending retry, não reverte
        $notifierTimeout = $this->createMock(NotifierPort::class);
        $notifierTimeout->method('notify')->willReturn(NotifyResult::failed(0, '', 'Notifier exception: timeout'));

        $processor = new ProcessNotificationOutboxUseCase($this->outbox, $notifierTimeout, new NullLogger());
        $result = $processor->processNext('corr-notify-timeout');

        self::assertNotNull($result);
        self::assertSame('pending', $result['status'], 'timeout deve ficar pending para retry');
        self::assertSame(1, $result['attempts']);
        self::assertSame(0, $result['http_status']);

        // Transfer permanece completed mesmo com notifier falho
        $transferAfter = Db::table('transfers')->where('id', $transferId)->first();
        self::assertSame('completed', $transferAfter->status, 'notifier timeout mantém completed');

        // Saldo permanece debitado/creditado
        self::assertSame('175.00', $this->wallets->findByUserId($payerId)->getBalance()->getAmount(), 'timeout não reverte débito');
        self::assertSame('55.00', $this->wallets->findByUserId($payeeId)->getBalance()->getAmount());

        // Outbox pending com backoff 10s
        $outboxAfter = $this->outbox->findByTransferId($transferId);
        self::assertSame('pending', $outboxAfter->status);
        self::assertSame(1, (int) $outboxAfter->attempts);
        self::assertStringContainsString('timeout', strtolower((string) $outboxAfter->last_response));
        $availableAt = strtotime((string) $outboxAfter->available_at);
        $diff = $availableAt - time();
        self::assertGreaterThanOrEqual(8, $diff);
        self::assertLessThanOrEqual(12, $diff);

        // Segunda tentativa também timeout → backoff 60s
        Db::table('notification_outbox')->where('id', $outboxAfter->id)->update(['available_at' => date('Y-m-d H:i:s')]);
        $r2 = $processor->processNext('corr-notify-timeout2');
        self::assertSame('pending', $r2['status']);
        self::assertSame(2, $r2['attempts']);
        self::assertSame('completed', Db::table('transfers')->where('id', $transferId)->first()->status, 'ainda completed após 2 timeouts');

        $this->cleanUsers([$payerId, $payeeId]);
    }

    public function testRedisLossKeepsAuditMysql(): void
    {
        if (! $this->isDbAvailable()) {
            self::markTestSkipped('DB not available');
        }

        // Cenário: Redis indisponível (get/tryReserve/storeResult lançam) → transferência ainda completa com audit MySQL
        [$payerId, $payeeId] = $this->createCommonPair('150.00', '10.00');

        // Idempotency store que falha (simula Redis loss)
        $failingRedis = $this->createMock(RedisIdempotencyStore::class);
        $failingRedis->method('get')->willThrowException(new RuntimeException('Redis connection lost'));
        $failingRedis->method('tryReserve')->willThrowException(new RuntimeException('Redis connection lost'));
        $failingRedis->method('storeResult')->willThrowException(new RuntimeException('Redis connection lost'));
        $failingRedis->method('exists')->willThrowException(new RuntimeException('Redis connection lost'));
        $failingRedis->method('delete')->willThrowException(new RuntimeException('Redis connection lost'));

        $authorizer = $this->createMock(AuthorizerPort::class);
        $authorizer->method('authorize')->willReturn(AuthorizerResult::authorized('{"status":"success","data":{"authorization":true}}'));

        $useCase = $this->makeTransferUseCase($authorizer, $failingRedis);

        $transfersBefore = (int) Db::table('transfers')->count();

        // Deve completar mesmo com Redis down — graceful degradation per ExecuteTransferUseCase (log warning, proceed)
        $result = $useCase->execute(['value' => '20.00', 'payer' => $payerId, 'payee' => $payeeId, 'correlation_id' => 'corr-redis-loss']);

        self::assertSame('completed', $result['transfer']['status']);
        self::assertSame('20.00', $result['transfer']['value']);
        self::assertSame('corr-redis-loss', $result['correlation_id']);
        self::assertSame($transfersBefore + 1, (int) Db::table('transfers')->count(), 'audit MySQL persiste mesmo com Redis loss');

        // Verifica transferência auditada no MySQL com idempotency_key ainda gravado (mesmo sem Redis)
        $transferRow = Db::table('transfers')->where('id', $result['transfer']['id'])->first();
        self::assertNotNull($transferRow);
        self::assertSame('completed', $transferRow->status);
        self::assertNotNull($transferRow->idempotency_key, 'idempotency_key persistido no MySQL mesmo com Redis loss');
        self::assertSame(64, strlen((string) $transferRow->idempotency_key), 'sha256 hex 64');
        self::assertSame('corr-redis-loss', $transferRow->correlation_id);

        // Outbox também committed atomically
        $outboxRow = Db::table('notification_outbox')->where('transfer_id', $transferRow->id)->first();
        self::assertNotNull($outboxRow, 'outbox commit mesmo com Redis loss');
        self::assertSame('pending', $outboxRow->status);

        // Saldo correto: payer 130, payee 30
        self::assertSame('130.00', $this->wallets->findByUserId($payerId)->getBalance()->getAmount());
        self::assertSame('30.00', $this->wallets->findByUserId($payeeId)->getBalance()->getAmount());

        // Prova que idempotency não é crítica para audit: mesmo com Redis loss, historico fica no MySQL
        // E que segunda tentativa sem Redis também não causa duplo débito por falta de cache? Na perda total, duplicata só seria evitada via DB unique? Mas audit permanece.
        // Verifica que transfer audit pode ser reconstruída via MySQL
        $auditTransfers = Db::table('transfers')->where('payer_id', $payerId)->where('payee_id', $payeeId)->where('value', '20.00')->get();
        self::assertCount(1, $auditTransfers, 'apenas um audit MySQL para valor mesmo com Redis loss');

        $this->cleanUsers([$payerId, $payeeId]);
    }

    public function testResilienceEndToEndAuthorizerOkNotifierEventuallySent(): void
    {
        if (! $this->isDbAvailable()) {
            self::markTestSkipped('DB not available');
        }

        // Fluxo completo resiliente: authorizer ok + notifier falha primeira vez mas sent segunda vez (retry)
        [$payerId, $payeeId] = $this->createCommonPair('100.00', '0.00');
        $transferResult = $this->executeTransferSuccess($payerId, $payeeId, '40.00', 'corr-e2e');
        $transferId = (int) $transferResult['transfer']['id'];

        // First notifier attempt timeout → pending
        $notifierFail = $this->createMock(NotifierPort::class);
        $notifierFail->method('notify')->willReturn(NotifyResult::failed(0, '', 'timeout'));
        $processorFail = new ProcessNotificationOutboxUseCase($this->outbox, $notifierFail, new NullLogger());
        $r1 = $processorFail->processNext('corr-e2e-1');
        self::assertSame('pending', $r1['status']);
        self::assertSame(1, $r1['attempts']);

        // Saldo ainda completed
        self::assertSame('60.00', $this->wallets->findByUserId($payerId)->getBalance()->getAmount());
        self::assertSame('40.00', $this->wallets->findByUserId($payeeId)->getBalance()->getAmount());
        self::assertSame('completed', Db::table('transfers')->where('id', $transferId)->first()->status);

        // Force available now + second attempt sent
        $outbox = $this->outbox->findByTransferId($transferId);
        Db::table('notification_outbox')->where('id', $outbox->id)->update(['available_at' => date('Y-m-d H:i:s')]);

        $notifierSent = $this->createMock(NotifierPort::class);
        $notifierSent->method('notify')->willReturn(NotifyResult::sent(204, ''));
        $processorSent = new ProcessNotificationOutboxUseCase($this->outbox, $notifierSent, new NullLogger());
        $r2 = $processorSent->processNext('corr-e2e-2');
        self::assertSame('sent', $r2['status']);

        // Final audit: transfer completed, outbox sent, saldo intacto
        self::assertSame('completed', Db::table('transfers')->where('id', $transferId)->first()->status);
        self::assertSame('sent', $this->outbox->findByTransferId($transferId)->status);
        self::assertSame('60.00', $this->wallets->findByUserId($payerId)->getBalance()->getAmount());
        self::assertSame('40.00', $this->wallets->findByUserId($payeeId)->getBalance()->getAmount());

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

    /**
     * @return array{transfer:array, notification:string, value:string, idempotency_key:string, correlation_id:string}
     */
    private function executeTransferSuccess(int $payerId, int $payeeId, string $value, string $correlationId = ''): array
    {
        $authorizer = $this->createMock(AuthorizerPort::class);
        $authorizer->method('authorize')->willReturn(AuthorizerResult::authorized('{"status":"success","data":{"authorization":true}}'));
        $redis = $this->makeRedisMock();
        $fp = RedisIdempotencyStore::fingerprint($payerId, $payeeId, $value);
        $this->tryDeleteRedis($redis, $fp);
        $useCase = $this->makeTransferUseCase($authorizer, $redis);
        $input = ['value' => $value, 'payer' => $payerId, 'payee' => $payeeId];
        if ($correlationId !== '') {
            $input['correlation_id'] = $correlationId;
        }
        return $useCase->execute($input);
    }

    /**
     * @return array{0:int,1:int}
     */
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
            'full_name' => 'Resilience Payer ' . uniqid(),
            'document' => '529.982.247-25',
            'email' => 'res_payer.' . uniqid() . '@example.com',
            'password' => 'password123',
            'type' => 'common',
        ]);
        $payee = $this->createUser->execute([
            'full_name' => 'Resilience Payee ' . uniqid(),
            'document' => '111.444.777-35',
            'email' => 'res_payee.' . uniqid() . '@example.com',
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
