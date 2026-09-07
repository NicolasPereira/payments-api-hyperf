<?php

declare(strict_types=1);

namespace App\Infrastructure\Persistence;

use Hyperf\Collection\Collection;
use Hyperf\DbConnection\Db;
use Throwable;

use function Hyperf\Collection\collect;

/**
 * Transaction helpers + SELECT FOR UPDATE ordered by user_id per plan.md:145.
 * Deterministic wallet locks in ascending user_id order to prevent deadlocks.
 */
final class Database
{
    /**
     * Execute callback inside a DB transaction.
     *
     * @template T
     * @param callable():T $callback
     * @return T
     * @throws Throwable
     */
    public static function transaction(callable $callback, int $attempts = 1): mixed
    {
        return Db::transaction($callback, $attempts);
    }

    /**
     * Lock wallets for update, deterministically ordered by user_id ascending.
     *
     * @param int[] $userIds
     * @return Collection<int, object> rows with at least id, user_id, balance
     */
    public static function lockWalletsForUpdate(array $userIds): Collection
    {
        if ($userIds === []) {
            return collect([]);
        }

        $ordered = array_values(array_unique($userIds));
        sort($ordered, SORT_NUMERIC);

        return Db::table('wallets')
            ->whereIn('user_id', $ordered)
            ->orderBy('user_id', 'asc')
            ->lockForUpdate()
            ->get();
    }

    /**
     * Lock a single wallet for update.
     *
     * @return object|null row
     */
    public static function lockWalletForUpdate(int $userId): ?object
    {
        $result = Db::table('wallets')
            ->where('user_id', $userId)
            ->lockForUpdate()
            ->first();

        return $result !== null ? (object) $result : null;
    }

    /**
     * Generic SELECT FOR UPDATE ordered helper.
     *
     * @param int[] $ids
     * @return Collection<int, object>
     */
    public static function selectForUpdateOrdered(string $table, string $column, array $ids, string $orderBy = 'user_id'): Collection
    {
        if ($ids === []) {
            return collect([]);
        }

        $ordered = array_values(array_unique($ids));
        sort($ordered, SORT_NUMERIC);

        return Db::table($table)
            ->whereIn($column, $ordered)
            ->orderBy($orderBy, 'asc')
            ->lockForUpdate()
            ->get();
    }

    /**
     * Begin transaction manually (for coroutine contexts where closure style is not desired).
     */
    public static function beginTransaction(): void
    {
        Db::beginTransaction();
    }

    public static function commit(): void
    {
        Db::commit();
    }

    public static function rollBack(): void
    {
        Db::rollBack();
    }
}
