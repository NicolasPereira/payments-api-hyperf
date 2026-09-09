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

namespace Database\Seeders;

use App\Application\User\CreateUserUseCase;
use App\Infrastructure\Persistence\UserRepository;
use App\Infrastructure\Persistence\WalletRepository;
use Hyperf\DbConnection\Db;
use InvalidArgumentException;
use RuntimeException;

/**
 * T059 Seed/migration de saldos para testes — sem endpoint /deposit per spec Clarification 2026-08-30.
 *
 * Carteiras iniciam com 0.00; este seeder provê saldos para cenários de transferência
 * via UPDATE direto em wallets. Uso: `TestBalanceSeeder::seed(userId, "100.00")`.
 *
 * Não expõe endpoint HTTP; apenas helper para tests/scripts/quickstart validation.
 */
final class TestBalanceSeeder
{
    /**
     * Define saldo exato para um usuário (DECIMAL string "100.00").
     *
     * @throws InvalidArgumentException se valor não for string decimal exata com 2 casas
     */
    public static function seed(int $userId, string $balance): void
    {
        if (preg_match('/^\d+\.\d{2}$/', $balance) !== 1) {
            throw new InvalidArgumentException('Balance must be string decimal with 2 digits, e.g. "100.00"');
        }

        if ($userId <= 0) {
            throw new InvalidArgumentException('userId must be positive int');
        }

        $exists = Db::table('wallets')->where('user_id', $userId)->exists();
        if (! $exists) {
            throw new RuntimeException(sprintf('Wallet not found for user_id %d', $userId));
        }

        Db::table('wallets')->where('user_id', $userId)->update([
            'balance' => $balance,
            'updated_at' => date('Y-m-d H:i:s'),
        ]);
    }

    /**
     * Define saldos para múltiplos usuários.
     *
     * @param array<int, string> $balances map userId => balance "100.00"
     */
    public static function seedMany(array $balances): void
    {
        foreach ($balances as $userId => $balance) {
            self::seed((int) $userId, (string) $balance);
        }
    }

    /**
     * Zera saldos de todos os wallets (útil para reset entre testes).
     */
    public static function resetAll(): void
    {
        Db::table('wallets')->update([
            'balance' => '0.00',
            'updated_at' => date('Y-m-d H:i:s'),
        ]);
    }

    /**
     * Cria usuário + wallet via UseCase e já define saldo — helper para quickstart/tests.
     *
     * @return array{userId:int, balance:string}
     */
    public static function createUserWithBalance(
        string $fullName,
        string $document,
        string $email,
        string $password,
        string $type,
        string $balance = '0.00',
    ): array {
        $repoUser = new UserRepository();
        $repoWallet = new WalletRepository();
        $useCase = new CreateUserUseCase($repoUser, $repoWallet);

        $result = $useCase->execute([
            'full_name' => $fullName,
            'document' => $document,
            'email' => $email,
            'password' => $password,
            'type' => $type,
        ]);

        $userId = $result['user']->getId();
        if ($userId === null) {
            throw new RuntimeException('Failed to create user');
        }

        if ($balance !== '0.00') {
            self::seed($userId, $balance);
        }

        return ['userId' => $userId, 'balance' => $balance];
    }
}
