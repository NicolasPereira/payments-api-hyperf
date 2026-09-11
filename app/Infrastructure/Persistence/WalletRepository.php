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

    public function createForUser(int $userId, ?Money $initial = null): Wallet
    {
        $balance = $initial ?? Money::zero();
        $id = (int) Db::table('wallets')->insertGetId([
            'user_id' => $userId,
            'balance' => $balance->amount(),
            'created_at' => date('Y-m-d H:i:s'),
            'updated_at' => date('Y-m-d H:i:s'),
        ]);

        return new Wallet($id, $userId, $balance);
    }

    public function updateBalance(int $userId, Money $balance): void
    {
        Db::table('wallets')->where('user_id', $userId)->update([
            'balance' => $balance->amount(),
            'updated_at' => date('Y-m-d H:i:s'),
        ]);
    }

    private function hydrate(array|object $row): Wallet
    {
        $r = is_array($row) ? (object) $row : $row;

        return new Wallet(
            isset($r->id) ? (int) $r->id : null,
            (int) $r->user_id,
            Money::fromString((string) $r->balance),
        );
    }
}
