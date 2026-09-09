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

class NotificationOutboxRepository
{
    public const STATUS_PENDING = 'pending';

    public const STATUS_PROCESSING = 'processing';

    public const STATUS_SENT = 'sent';

    public const STATUS_FAILED = 'failed';

    /**
     * Insert pending outbox row. Called inside transfer transaction.
     *
     * @param array<string,mixed> $payload
     */
    public function create(int $transferId, int $payeeId, array $payload, string $status = self::STATUS_PENDING): int
    {
        $now = date('Y-m-d H:i:s');

        return (int) Db::table('notification_outbox')->insertGetId([
            'transfer_id' => $transferId,
            'payee_id' => $payeeId,
            'payload' => json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'status' => $status,
            'attempts' => 0,
            'available_at' => $now,
            'lease_until' => null,
            'last_response' => null,
            'sent_at' => null,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }

    public function findByTransferId(int $transferId): ?object
    {
        $row = Db::table('notification_outbox')->where('transfer_id', $transferId)->first();
        return $row !== null ? (object) $row : null;
    }

    /**
     * @return array<int,object>
     */
    public function findPending(int $limit = 10): array
    {
        $rows = Db::table('notification_outbox')
            ->where('status', self::STATUS_PENDING)
            ->where('available_at', '<=', date('Y-m-d H:i:s'))
            ->limit($limit)
            ->get();

        $result = [];
        foreach ($rows as $row) {
            $result[] = (object) $row;
        }

        return $result;
    }

    public function countPending(): int
    {
        return (int) Db::table('notification_outbox')->where('status', self::STATUS_PENDING)->count();
    }

    public function deleteByTransferId(int $transferId): void
    {
        Db::table('notification_outbox')->where('transfer_id', $transferId)->delete();
    }
}
