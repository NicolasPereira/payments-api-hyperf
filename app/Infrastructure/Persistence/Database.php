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

use Hyperf\DbConnection\Db;

final class Database
{
    /**
     * @template T
     * @param callable():T $fn
     * @return T
     */
    public static function transaction(callable $fn): mixed
    {
        return Db::transaction($fn);
    }

    /**
     * Locks wallet rows in ascending user_id order to avoid deadlocks.
     * @param int[] $userIds
     * @return array<int, object> keyed by user_id
     */
    public static function lockWalletsForUpdate(array $userIds): array
    {
        $ids = array_values(array_unique(array_map('intval', $userIds)));
        sort($ids, SORT_NUMERIC);
        if ($ids === []) {
            return [];
        }
        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $rows = Db::select("SELECT * FROM wallets WHERE user_id IN ({$placeholders}) ORDER BY user_id ASC FOR UPDATE", $ids);
        $map = [];
        foreach ($rows as $row) {
            $map[(int) $row->user_id] = $row;
        }

        return $map;
    }
}
