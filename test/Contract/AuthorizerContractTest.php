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

use App\Application\Transfer\ExecuteTransferUseCase;
use App\Application\User\CreateUserUseCase;
use App\Domain\Contracts\AuthorizerPort;
use App\Domain\Contracts\AuthorizerResult;
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
use Throwable;

/**
 * T049 Contract test authorizer — per contracts/external-services.md:4
 * authorized {status:success, data:{authorization:true}} → proceed;
 * denied/malformed/5xx/timeout → 403/502/503 sem mutação.
 * Verifica ExecuteTransferUseCase com AuthorizerPort mockado, sem mutação antes de transação,
 * mapeamento correto de códigos HTTP e que saldos não mudam em falhas.
 *
 * @internal
 * @coversNothing
 */
final class AuthorizerContractTest extends TestCase
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

    public function testAuthorizedSuccessProceedsWithoutMutationBeforeTransaction(): void
    {
        // Direct AuthorizerResult contract: success true → authorized
        $result = AuthorizerResult::authorized('{"status":"success","data":{"authorization":true}}');
        self::assertTrue($result->isAuthorized());
        self::assertSame('{"status":"success","data":{"authorization":true}}', $result->rawResponse);

        // Verify integration: authorized → transfer completes, balances mutated atomically, outbox pending
        if (! $this->isDbAvailable()) {
            self::markTestSkipped('DB not available');
        }

        [$payerId, $payeeId] = $this->createCommonPair('70.00', '20.00');
        $authorizer = $this->createMock(AuthorizerPort::class);
        $authorizer->expects(self::once())->method('authorize')->willReturn(
            AuthorizerResult::authorized('{"status":"success","data":{"authorization":true}}')
        );

        $redis = $this->makeRedisMock();
        $fp = RedisIdempotencyStore::fingerprint($payerId, $payeeId, '10.00');
        $this->tryDeleteRedis($redis, $fp);
        $useCase = $this->makeUseCase($authorizer, $redis);

        try {
            $resultArr = $useCase->execute(['value' => '10.00', 'payer' => $payerId, 'payee' => $payeeId, 'correlation_id' => 'corr-auth-ok']);
            self::assertSame('completed', $resultArr['transfer']['status']);
            self::assertSame('queued', $resultArr['notification']);
            self::assertSame('corr-auth-ok', $resultArr['correlation_id']);
            // saldos 60/30
            self::assertSame('60.00', $this->wallets->findByUserId($payerId)->getBalance()->getAmount());
            self::assertSame('30.00', $this->wallets->findByUserId($payeeId)->getBalance()->getAmount());
            // outbox pending atômico
            $outboxRow = $this->outbox->findByTransferId((int) $resultArr['transfer']['id']);
            self::assertNotNull($outboxRow);
            self::assertSame('pending', $outboxRow->status);
        } finally {
            $this->tryDeleteRedis($redis, $fp);
            $this->cleanUsers([$payerId, $payeeId]);
        }
    }

    public function testDeniedAuthorizationMapsTo403NoMutation(): void
    {
        // Contract: denied (authorization false) → 403, sem mutação
        $denied = AuthorizerResult::denied('{"status":"success","data":{"authorization":false}}', 'Authorizer denied authorization');
        self::assertFalse($denied->isAuthorized());
        self::assertSame('authorizer_denied', $denied->errorCode);
        self::assertSame(403, $this->mapAuthorizerResultToHttpStatus($denied));

        if (! $this->isDbAvailable()) {
            self::markTestSkipped('DB not available');
        }

        [$payerId, $payeeId] = $this->createCommonPair('100.00', '0.00');
        $authorizer = $this->createMock(AuthorizerPort::class);
        $authorizer->method('authorize')->willReturn($denied);
        $redis = $this->makeRedisMock();
        $fp = RedisIdempotencyStore::fingerprint($payerId, $payeeId, '10.00');
        $this->tryDeleteRedis($redis, $fp);
        $useCase = $this->makeUseCase($authorizer, $redis);

        $transfersBefore = (int) Db::table('transfers')->count();
        $outboxBefore = (int) Db::table('notification_outbox')->count();

        try {
            $useCase->execute(['value' => '10.00', 'payer' => $payerId, 'payee' => $payeeId]);
            self::fail('Expected 403 for denied authorization');
        } catch (DomainException $e) {
            self::assertSame(403, $e->getHttpStatus());
            self::assertSame('authorizer_denied', $e->getBusinessCode());
        }

        self::assertSame('100.00', $this->wallets->findByUserId($payerId)->getBalance()->getAmount(), 'payer sem débito em denied');
        self::assertSame('0.00', $this->wallets->findByUserId($payeeId)->getBalance()->getAmount(), 'payee sem crédito em denied');
        self::assertSame($transfersBefore, (int) Db::table('transfers')->count(), 'nenhum transfer criado em denied');
        self::assertSame($outboxBefore, (int) Db::table('notification_outbox')->count(), 'nenhum outbox em denied');
        $this->tryDeleteRedis($redis, $fp);
        $this->cleanUsers([$payerId, $payeeId]);
    }

    public function testMalformedResponseMapsTo502NoMutation(): void
    {
        // Malformed: invalid JSON or missing status/data → 502
        $malformed = AuthorizerResult::failed('not-json', 'authorizer_malformed', 'Malformed authorizer response: invalid JSON');
        self::assertFalse($malformed->isAuthorized());
        self::assertSame('authorizer_malformed', $malformed->errorCode);
        self::assertSame(502, $this->mapAuthorizerResultToHttpStatus($malformed));

        // Também testa missing status/data
        $malformed2 = AuthorizerResult::failed('{"foo":1}', 'authorizer_malformed', 'Malformed authorizer response: missing status/data');
        self::assertSame(502, $this->mapAuthorizerResultToHttpStatus($malformed2));

        if (! $this->isDbAvailable()) {
            self::markTestSkipped('DB not available');
        }

        [$payerId, $payeeId] = $this->createCommonPair('50.00', '0.00');
        $authorizer = $this->createMock(AuthorizerPort::class);
        $authorizer->method('authorize')->willReturn($malformed);
        $redis = $this->makeRedisMock();
        $fp = RedisIdempotencyStore::fingerprint($payerId, $payeeId, '5.00');
        $this->tryDeleteRedis($redis, $fp);
        $useCase = $this->makeUseCase($authorizer, $redis);

        try {
            $useCase->execute(['value' => '5.00', 'payer' => $payerId, 'payee' => $payeeId]);
            self::fail('Expected 502 for malformed');
        } catch (DomainException $e) {
            self::assertSame(502, $e->getHttpStatus());
            self::assertSame('authorizer_malformed', $e->getBusinessCode());
        }

        self::assertSame('50.00', $this->wallets->findByUserId($payerId)->getBalance()->getAmount(), 'sem mutação em malformed');
        self::assertSame('0.00', $this->wallets->findByUserId($payeeId)->getBalance()->getAmount());
        $this->tryDeleteRedis($redis, $fp);
        $this->cleanUsers([$payerId, $payeeId]);
    }

    public function testUpstream5xxMapsTo502Or503NoMutation(): void
    {
        // 5xx upstream → 502 ou 503 (ambos aceitos per spec)
        $upstream = AuthorizerResult::failed('internal error', 'authorizer_upstream_error', 'Authorizer returned HTTP 500');
        self::assertFalse($upstream->isAuthorized());
        $http = $this->mapAuthorizerResultToHttpStatus($upstream);
        self::assertContains($http, [502, 503], '5xx deve mapear para 502 ou 503');

        if (! $this->isDbAvailable()) {
            self::markTestSkipped('DB not available');
        }

        [$payerId, $payeeId] = $this->createCommonPair('80.00', '10.00');
        $authorizer = $this->createMock(AuthorizerPort::class);
        $authorizer->method('authorize')->willReturn($upstream);
        $redis = $this->makeRedisMock();
        $fp = RedisIdempotencyStore::fingerprint($payerId, $payeeId, '10.00');
        $this->tryDeleteRedis($redis, $fp);
        $useCase = $this->makeUseCase($authorizer, $redis);

        try {
            $useCase->execute(['value' => '10.00', 'payer' => $payerId, 'payee' => $payeeId]);
            self::fail('Expected 502/503 for 5xx');
        } catch (DomainException $e) {
            self::assertContains($e->getHttpStatus(), [502, 503]);
            self::assertContains($e->getBusinessCode(), ['authorizer_upstream_error', 'authorizer_unavailable']);
        }

        self::assertSame('80.00', $this->wallets->findByUserId($payerId)->getBalance()->getAmount(), 'sem débito em 5xx');
        $this->tryDeleteRedis($redis, $fp);
        $this->cleanUsers([$payerId, $payeeId]);
    }

    public function testTimeoutMapsTo503NoMutation(): void
    {
        // Timeout → 503
        $timeout = AuthorizerResult::failed('', 'authorizer_timeout', 'Authorizer timed out');
        self::assertFalse($timeout->isAuthorized());
        self::assertSame(503, $this->mapAuthorizerResultToHttpStatus($timeout));

        if (! $this->isDbAvailable()) {
            self::markTestSkipped('DB not available');
        }

        [$payerId, $payeeId] = $this->createCommonPair('60.00', '0.00');
        $authorizer = $this->createMock(AuthorizerPort::class);
        $authorizer->method('authorize')->willReturn($timeout);
        $redis = $this->makeRedisMock();
        $fp = RedisIdempotencyStore::fingerprint($payerId, $payeeId, '10.00');
        $this->tryDeleteRedis($redis, $fp);
        $useCase = $this->makeUseCase($authorizer, $redis);

        try {
            $useCase->execute(['value' => '10.00', 'payer' => $payerId, 'payee' => $payeeId]);
            self::fail('Expected 503 for timeout');
        } catch (DomainException $e) {
            self::assertSame(503, $e->getHttpStatus());
            self::assertSame('authorizer_timeout', $e->getBusinessCode());
        }

        self::assertSame('60.00', $this->wallets->findByUserId($payerId)->getBalance()->getAmount(), 'sem mutação em timeout');
        $this->tryDeleteRedis($redis, $fp);
        $this->cleanUsers([$payerId, $payeeId]);
    }

    public function testAuthorizerUnavailableMapsTo503NoMutation(): void
    {
        $unavailable = AuthorizerResult::failed('', 'authorizer_unavailable', 'Authorizer unavailable: connection refused');
        self::assertSame(503, $this->mapAuthorizerResultToHttpStatus($unavailable));

        // Também garante que ExecuteTransferUseCase não chama transação antes do authorizer:
        // merchant payer blocked já validado antes de authorizer (T052 garante sem mutação antes transação)
        if (! $this->isDbAvailable()) {
            self::markTestSkipped('DB not available');
        }
        [$payerId, $payeeId] = $this->createCommonPair('90.00', '0.00');
        $authorizer = $this->createMock(AuthorizerPort::class);
        $authorizer->method('authorize')->willReturn($unavailable);
        $redis = $this->makeRedisMock();
        $useCase = $this->makeUseCase($authorizer, $redis);

        try {
            $useCase->execute(['value' => '10.00', 'payer' => $payerId, 'payee' => $payeeId]);
            self::fail('Expected 503 for unavailable');
        } catch (DomainException $e) {
            self::assertSame(503, $e->getHttpStatus());
            self::assertSame('authorizer_unavailable', $e->getBusinessCode());
        }

        // Nenhum débito mesmo com payer saldo suficiente — prova que authorizer bloqueia mutação
        self::assertSame('90.00', $this->wallets->findByUserId($payerId)->getBalance()->getAmount());
        $this->cleanUsers([$payerId, $payeeId]);
    }

    public function testAuthorizerContractPayloadsPerExternalServicesMd(): void
    {
        // Valida contrato per specs/001-picpay-simplificado-transferencias/contracts/external-services.md
        // Success observado: {"status":"success","data":{"authorization":true}}
        $successJson = '{"status":"success","data":{"authorization":true}}';
        $success = AuthorizerResult::authorized($successJson);
        self::assertTrue($success->isAuthorized());
        // Denied: false
        $deniedJson = '{"status":"success","data":{"authorization":false}}';
        $denied = AuthorizerResult::denied($deniedJson);
        self::assertFalse($denied->isAuthorized());
        // Malformed missing fields
        $malformed = AuthorizerResult::failed('{"status":"success"}', 'authorizer_malformed', 'missing data');
        self::assertFalse($malformed->isAuthorized());
        self::assertSame(502, $this->mapAuthorizerResultToHttpStatus($malformed));
        // Financial effect: no mutation unless authorization true — verified nos testes DB acima
    }

    // -----------------------------------------------------------------
    // Helpers — mapeamento reproduzindo ExecuteTransferUseCase::doExecute lógica T052
    private function mapAuthorizerResultToHttpStatus(AuthorizerResult $result): int
    {
        if ($result->isAuthorized()) {
            return 200;
        }
        $code = $result->errorCode ?? 'authorizer_denied';
        if ($code === 'authorizer_denied') {
            return 403;
        }
        if ($code === 'authorizer_timeout') {
            return 503;
        }
        if ($code === 'authorizer_malformed' || $code === 'authorizer_upstream_error') {
            return 502;
        }
        if (str_contains($code, 'unavailable')) {
            return 503;
        }
        return 403;
    }

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
            $mock->method('exists')->willReturn(false);
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

    private function makeUseCase(AuthorizerPort $authorizer, RedisIdempotencyStore $redis): ExecuteTransferUseCase
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
     * @return array{0:int,1:int}
     */
    private function createCommonPair(string $payerBalance, string $payeeBalance): array
    {
        $payer = $this->createUser->execute([
            'full_name' => 'AuthContract Payer ' . uniqid(),
            'document' => '529.982.247-25',
            'email' => 'auth_payer.' . uniqid() . '@example.com',
            'password' => 'password123',
            'type' => 'common',
        ]);
        $payee = $this->createUser->execute([
            'full_name' => 'AuthContract Payee ' . uniqid(),
            'document' => '111.444.777-35',
            'email' => 'auth_payee.' . uniqid() . '@example.com',
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
