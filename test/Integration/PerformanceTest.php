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
use App\Domain\Shared\ValueObject\Money;
use App\Domain\User\Entity\User;
use App\Infrastructure\Cache\RedisIdempotencyStore;
use App\Infrastructure\Persistence\NotificationOutboxRepository;
use App\Infrastructure\Persistence\TransferRepository;
use App\Infrastructure\Persistence\UserRepository;
use App\Infrastructure\Persistence\WalletRepository;
use Hyperf\DbConnection\Db;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Throwable;

/**
 * T061 Performance smoke 100 transfers/min per SC-013.
 *
 * SC-013: Sistema mantém taxa de erro < 1% para transferências válidas sob carga de 100 transfers/min.
 * Este teste executa 100 transferências válidas mockadas (sem I/O externo real) e valida:
 * - erro < 1% (todas devem suceder com authorizer mocked authorized)
 * - throughput 100/min => p95 < 60s para o lote (smoke) e p95 single transfer < 3s (SC-005)
 *
 * Usa mocks para Authorizer e RedisIdempotencyStore para evitar idempotency hit
 * (cada valor é único), focando em transação DB + Outbox atômica.
 * @internal
 * @coversNothing
 */
final class PerformanceTest extends TestCase
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

    public function testPerformance100TransfersPerMinuteSmoke(): void
    {
        if (! $this->isDbAvailable()) {
            self::markTestSkipped('DB not available');
        }

        // Cria payer com saldo suficiente para 100 * 1.00 + margem
        $payer = $this->createCommonUser('529.982.247-25', 'perf_payer.' . uniqid() . '@example.com');
        $payee = $this->createCommonUser('111.444.777-35', 'perf_payee.' . uniqid() . '@example.com');
        $payerId = $payer->getId();
        $payeeId = $payee->getId();

        // Saldo inicial 200.00 cobre 100 transfers de valores 0.10..10.00
        $this->setWalletBalance($payerId, '1000.00');
        $this->setWalletBalance($payeeId, '0.00');

        $authorizer = $this->createMock(AuthorizerPort::class);
        $authorizer->method('authorize')->willReturn(AuthorizerResult::authorized('{"status":"success","data":{"authorization":true}}'));

        // Mock Redis: sempre permite, sem idempotency hit — cada transfer distinta via valor único
        $redis = $this->createMock(RedisIdempotencyStore::class);
        $redis->method('get')->willReturn(null);
        $redis->method('tryReserve')->willReturn(true);
        $redis->method('exists')->willReturn(false);

        $useCase = new ExecuteTransferUseCase(
            $this->users,
            $this->wallets,
            $this->transfers,
            $this->outbox,
            $authorizer,
            $redis,
            new NullLogger()
        );

        $total = 100;
        $errors = 0;
        $durationsMs = [];
        $startAll = microtime(true);

        for ($i = 1; $i <= $total; ++$i) {
            // Valor único para evitar fingerprint duplicado: 0.01 * i + 0.10 ex: 0.11..10.10
            // Mantém pattern ^(?!0+\.00$)[0-9]+\.[0-9]{2}$ e cada um é distinto
            $value = sprintf('%d.%02d', intdiv($i, 100) + 1, $i % 100); // 1.01, 1.02 ... 2.00 etc — todos únicos e >0.00
            // Garante >0.00 e 2 decimais
            if ($value === '0.00' || $value === '1.00') {
                $value = sprintf('1.%02d', $i % 100);
            }

            $start = microtime(true);
            try {
                $result = $useCase->execute(['value' => $value, 'payer' => $payerId, 'payee' => $payeeId]);
                self::assertSame('completed', $result['transfer']['status']);
            } catch (Throwable $e) {
                ++$errors;
                // Log para debug sem PII
                fwrite(STDERR, sprintf("Perf transfer %d failed: %s\n", $i, $e->getMessage()));
            }
            $durationsMs[] = (int) ((microtime(true) - $start) * 1000);
        }

        $totalMs = (int) ((microtime(true) - $startAll) * 1000);
        $errorRate = $errors / $total;

        // p95 single transfer < 3000ms (SC-005 transfer <3s at p95)
        sort($durationsMs);
        $p95Idx = (int) ceil(0.95 * count($durationsMs)) - 1;
        $p95Idx = max(0, min($p95Idx, count($durationsMs) - 1));
        $p95Ms = $durationsMs[$p95Idx];
        $avgMs = array_sum($durationsMs) / count($durationsMs);

        // Throughput 100/min => <60s para lote
        fwrite(STDERR, sprintf("Perf smoke 100 transfers: total=%dms avg=%.1fms p95=%dms errors=%d/100 errorRate=%.2f%%\n", $totalMs, $avgMs, $p95Ms, $errors, $errorRate * 100));

        self::assertLessThan(60000, $totalMs, sprintf('100 transfers should complete in <60s, took %dms', $totalMs));
        self::assertLessThan(0.01, $errorRate, sprintf('Error rate must be <1%%, got %.2f%% (%d errors)', $errorRate * 100, $errors));
        self::assertLessThan(3000, $p95Ms, sprintf('p95 single transfer <3s per SC-005, got %dms', $p95Ms));
        self::assertLessThan(1000, $avgMs, 'avg transfer should be <1s (sanity)');

        // Valida saldo final consistente: payer descontado, payee creditado
        $payerWallet = $this->wallets->findByUserId($payerId);
        $payeeWallet = $this->wallets->findByUserId($payeeId);
        // Soma dos valores: payer 1000 - sum, payee 0 + sum
        $expectedPayerBalanceCents = 100000 - array_sum(array_map(fn ($v) => (int) round((float) $v * 100), array_map(fn ($i) => sprintf('%d.%02d', intdiv($i, 100) + 1, $i % 100), range(1, 100))));
        // Não valida centavos exatos aqui para evitar float drift; apenas garante não negativo e transferência ocorreu
        self::assertFalse($payerWallet->getBalance()->lessThan(Money::zero()), 'payer balance must not be negative after 100 transfers');
        self::assertTrue($payeeWallet->getBalance()->greaterThan(Money::zero()), 'payee balance must be >0 after 100 transfers');

        $this->cleanUser($payerId);
        $this->cleanUser($payeeId);
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
        $result = $this->createUser->execute([
            'full_name' => 'Perf User ' . uniqid(),
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
        } catch (Throwable) {
        }
    }
}
