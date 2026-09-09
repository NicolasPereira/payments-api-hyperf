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

namespace App\Infrastructure\Persistence;

use App\Domain\Shared\ValueObject\Money;
use App\Domain\Wallet\Entity\Wallet;
use Hyperf\DbConnection\Db;

class WalletRepository
{
    public function findByUserId(int $userId): ?Wallet
    {
        $row = Db::table('wallets')->where('user_id', $userId)->first();
        if ($row === null) {
            return null;
        }

        return $this->hydrate($row);
    }

    public function create(Wallet $wallet): Wallet
    {
        $id = (int) Db::table('wallets')->insertGetId([
            'user_id' => $wallet->getUserId(),
            'balance' => $wallet->getBalance()->getAmount(),
            'created_at' => date('Y-m-d H:i:s'),
            'updated_at' => date('Y-m-d H:i:s'),
        ]);

        return $wallet->withId($id);
    }

    /**
     * Create wallet with 0.00 for a new user (atomic caller uses transaction).
     */
    public function createForUser(int $userId, ?Money $initial = null): Wallet
    {
        $balance = $initial ?? Money::zero();
        $wallet = new Wallet($userId, $balance);

        return $this->create($wallet);
    }

    public function updateBalance(int $userId, Money $newBalance): void
    {
        Db::table('wallets')
            ->where('user_id', $userId)
            ->update([
                'balance' => $newBalance->getAmount(),
                'updated_at' => date('Y-m-d H:i:s'),
            ]);
    }

    private function hydrate(array|object $row): Wallet
    {
        $r = is_array($row) ? (object) $row : $row;

        return new Wallet(
            (int) $r->user_id,
            Money::fromString((string) $r->balance),
            isset($r->id) ? (int) $r->id : null,
            isset($r->created_at) ? (string) $r->created_at : null,
            isset($r->updated_at) ? (string) $r->updated_at : null,
        );
    }
}
