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

use App\Application\Transfer\ExecuteTransferUseCase;
use App\Application\User\CreateUserUseCase;
use App\Domain\Contracts\AuthorizerPort;
use App\Domain\Contracts\AuthorizerResult;
use App\Domain\Transfer\Exception\MerchantPayerNotAllowedException;
use App\Domain\User\Entity\User;
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
 * T048 Integration test tipo lojista
 * - merchant payee credita + notifica (common 50 → merchant 10, value 20 → 30/30, outbox pending)
 * - merchant payer bloqueado sem mutação (403 merchant_payer_blocked, authorizer não chamado, balances intactos, sem transfer/outbox)
 * FR-006 / FR-007 per spec.md.
 * @internal
 * @coversNothing
 */
final class MerchantTransferTest extends TestCase
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

    public function testMerchantPayeeCreditedAndNotified(): void
    {
        if (! $this->isDbAvailable()) {
            self::markTestSkipped('DB not available');
        }

        // FR-006: common→merchant credita e notifica — spec independent test: common 50 + merchant 10 → 30/30 com value 20
        $common = $this->createCommonUser('529.982.247-25', 'merchant_payee_common.' . uniqid() . '@example.com');
        $merchant = $this->createMerchantUser('11.222.333/0001-81', 'merchant_payee_merchant.' . uniqid() . '@example.com');
        $commonId = $common->getId();
        $merchantId = $merchant->getId();

        $this->setWalletBalance($commonId, '50.00');
        $this->setWalletBalance($merchantId, '10.00');

        $authorizer = $this->createMock(AuthorizerPort::class);
        $authorizer->method('authorize')->willReturn(AuthorizerResult::authorized('{"status":"success","data":{"authorization":true}}'));

        $redis = $this->makeRedisMock();
        $fp = RedisIdempotencyStore::fingerprint($commonId, $merchantId, '20.00');
        try {
            $redis->delete($fp);
        } catch (Throwable) {
        }

        $useCase = $this->makeUseCase($authorizer, $redis);

        try {
            $result = $useCase->execute(['value' => '20.00', 'payer' => $commonId, 'payee' => $merchantId]);

            self::assertSame('completed', $result['transfer']['status']);
            self::assertSame('queued', $result['notification']);
            self::assertSame('20.00', $result['transfer']['value']);
            self::assertSame($commonId, $result['transfer']['payer']);
            self::assertSame($merchantId, $result['transfer']['payee']);

            // Saldos 30/30
            $commonWallet = $this->wallets->findByUserId($commonId);
            $merchantWallet = $this->wallets->findByUserId($merchantId);
            self::assertSame('30.00', $commonWallet->getBalance()->getAmount(), 'common 50-20=30');
            self::assertSame('30.00', $merchantWallet->getBalance()->getAmount(), 'merchant 10+20=30');

            // Outbox pending com payee merchant
            $outboxRow = $this->outbox->findByTransferId((int) $result['transfer']['id']);
            self::assertNotNull($outboxRow, 'Outbox must exist for merchant payee');
            self::assertSame('pending', $outboxRow->status);
            self::assertSame((string) $merchantId, (string) $outboxRow->payee_id);
            self::assertSame(0, (int) $outboxRow->attempts);

            // Transfer row payee é merchant
            $transferRow = Db::table('transfers')->where('id', $result['transfer']['id'])->first();
            self::assertNotNull($transferRow);
            self::assertSame((string) $merchantId, (string) $transferRow->payee_id);
        } finally {
            try {
                $redis->delete($fp);
            } catch (Throwable) {
            }
            $this->cleanUser($commonId);
            $this->cleanUser($merchantId);
        }
    }

    public function testMerchantPayerBlockedNoMutation(): void
    {
        if (! $this->isDbAvailable()) {
            self::markTestSkipped('DB not available');
        }

        // FR-007: merchant como payer → 403 merchant_payer_blocked sem mutação
        $merchant = $this->createMerchantUser('12.ABC.345/01DE-35', 'merchant_payer_merchant.' . uniqid() . '@example.com');
        $common = $this->createCommonUser('111.444.777-35', 'merchant_payer_common.' . uniqid() . '@example.com');
        $merchantId = $merchant->getId();
        $commonId = $common->getId();

        $this->setWalletBalance($merchantId, '100.00');
        $this->setWalletBalance($commonId, '10.00');

        $authorizer = $this->createMock(AuthorizerPort::class);
        $authorizer->expects(self::never())->method('authorize');

        $redis = $this->makeRedisMock();
        $fp = RedisIdempotencyStore::fingerprint($merchantId, $commonId, '10.00');
        try {
            $redis->delete($fp);
        } catch (Throwable) {
        }

        $useCase = $this->makeUseCase($authorizer, $redis);

        $transfersBefore = (int) Db::table('transfers')->where('payer_id', $merchantId)->count();
        $outboxBefore = (int) Db::table('notification_outbox')->where('payee_id', $commonId)->count();

        try {
            $useCase->execute(['value' => '10.00', 'payer' => $merchantId, 'payee' => $commonId]);
            self::fail('Expected MerchantPayerNotAllowedException');
        } catch (MerchantPayerNotAllowedException $e) {
            self::assertSame(403, $e->getHttpStatus());
            self::assertSame('merchant_payer_blocked', $e->getBusinessCode());
            self::assertStringContainsString('Lojista', $e->getMessage());
        }

        // Sem mutação: balances intactos
        self::assertSame('100.00', $this->wallets->findByUserId($merchantId)->getBalance()->getAmount(), 'merchant balance unchanged');
        self::assertSame('10.00', $this->wallets->findByUserId($commonId)->getBalance()->getAmount(), 'common balance unchanged');

        // Sem transfer/outbox criados
        self::assertSame($transfersBefore, (int) Db::table('transfers')->where('payer_id', $merchantId)->count(), 'no new transfer for blocked merchant payer');
        self::assertSame($outboxBefore, (int) Db::table('notification_outbox')->where('payee_id', $commonId)->count(), 'no outbox for blocked');

        // Idempotency não deve ter armazenado resultado para bloqueio (opcional), mas balances permanecem
        // Verifica que authorizer não foi chamado (expects never acima)

        try {
            $redis->delete($fp);
        } catch (Throwable) {
        }
        $this->cleanUser($merchantId);
        $this->cleanUser($commonId);
    }

    public function testMerchantPayerBlockedEvenWithMerchantPayee(): void
    {
        if (! $this->isDbAvailable()) {
            self::markTestSkipped('DB not available');
        }

        // merchant→merchant também bloqueado (payer merchant sempre bloqueado)
        $merchant1 = $this->createMerchantUser('11.222.333/0001-81', 'm2m_merchant1.' . uniqid() . '@example.com');
        $merchant2 = $this->createMerchantUser('12.ABC.345/01DE-35', 'm2m_merchant2.' . uniqid() . '@example.com');
        $m1Id = $merchant1->getId();
        $m2Id = $merchant2->getId();

        $this->setWalletBalance($m1Id, '100.00');
        $this->setWalletBalance($m2Id, '10.00');

        $authorizer = $this->createMock(AuthorizerPort::class);
        $authorizer->expects(self::never())->method('authorize');

        $redis = $this->makeRedisMock();
        $useCase = $this->makeUseCase($authorizer, $redis);

        try {
            $useCase->execute(['value' => '10.00', 'payer' => $m1Id, 'payee' => $m2Id]);
            self::fail('Expected MerchantPayerNotAllowedException for merchant→merchant');
        } catch (MerchantPayerNotAllowedException $e) {
            self::assertSame('merchant_payer_blocked', $e->getBusinessCode());
            self::assertSame(403, $e->getHttpStatus());
        } finally {
            self::assertSame('100.00', $this->wallets->findByUserId($m1Id)->getBalance()->getAmount());
            self::assertSame('10.00', $this->wallets->findByUserId($m2Id)->getBalance()->getAmount());
            $this->cleanUser($m1Id);
            $this->cleanUser($m2Id);
        }
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

    private function createCommonUser(string $document, string $email): User
    {
        // cleanup duplicate document/email leftover per T062 quality gate
        try {
            if (isset($document)) {
                $norm = strtoupper(preg_replace('/[^A-Za-z0-9]/', '', $document));
                Db::statement('SET FOREIGN_KEY_CHECKS=0');
                Db::table('wallets')->whereIn('user_id', Db::table('users')->where('document', $norm)->pluck('id')->toArray())->delete();
                Db::table('users')->where('document', $norm)->delete();
                Db::statement('SET FOREIGN_KEY_CHECKS=1');
            } if (isset($email)) {
                Db::table('users')->where('email', $email)->delete();
            }
        } catch (Throwable) {
            try {
                Db::statement('SET FOREIGN_KEY_CHECKS=1');
            } catch (Throwable) {
            }
        }
        // cleanup merchant duplicate
        try {
            if (isset($document)) {
                $norm = strtoupper(preg_replace('/[.\-\/ ]+/', '', $document));
                Db::table('users')->where('document', $norm)->delete();
            } if (isset($email)) {
                Db::table('users')->where('email', $email)->delete();
            }
        } catch (Throwable) {
        }
        $result = $this->createUser->execute([
            'full_name' => 'MerchantTest Common ' . uniqid(),
            'document' => $document,
            'email' => $email,
            'password' => 'password123',
            'type' => 'common',
        ]);
        return $result['user'];
    }

    private function createMerchantUser(string $document, string $email): User
    {
        // cleanup duplicate document/email leftover per T062 quality gate
        try {
            if (isset($document)) {
                $norm = strtoupper(preg_replace('/[^A-Za-z0-9]/', '', $document));
                Db::statement('SET FOREIGN_KEY_CHECKS=0');
                Db::table('wallets')->whereIn('user_id', Db::table('users')->where('document', $norm)->pluck('id')->toArray())->delete();
                Db::table('users')->where('document', $norm)->delete();
                Db::statement('SET FOREIGN_KEY_CHECKS=1');
            } if (isset($email)) {
                Db::table('users')->where('email', $email)->delete();
            }
        } catch (Throwable) {
            try {
                Db::statement('SET FOREIGN_KEY_CHECKS=1');
            } catch (Throwable) {
            }
        }
        // cleanup merchant duplicate
        try {
            if (isset($document)) {
                $norm = strtoupper(preg_replace('/[.\-\/ ]+/', '', $document));
                Db::table('users')->where('document', $norm)->delete();
            } if (isset($email)) {
                Db::table('users')->where('email', $email)->delete();
            }
        } catch (Throwable) {
        }
        $result = $this->createUser->execute([
            'full_name' => 'MerchantTest Merchant ' . uniqid(),
            'document' => $document,
            'email' => $email,
            'password' => 'password123',
            'type' => 'merchant',
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
}
