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
    public function createPending(int $transferId, int $payeeId, array $payload): int
    {
        return (int) Db::table('notification_outbox')->insertGetId([
            'transfer_id' => $transferId,
            'payee_id' => $payeeId,
            'payload' => json_encode($payload),
            'status' => 'pending',
            'attempts' => 0,
            'available_at' => date('Y-m-d H:i:s'),
            'created_at' => date('Y-m-d H:i:s'),
            'updated_at' => date('Y-m-d H:i:s'),
        ]);
    }

    public function findPending(int $limit = 10): array
    {
        return Db::table('notification_outbox')
            ->where('status', 'pending')
            ->where('available_at', '<=', date('Y-m-d H:i:s'))
            ->orderBy('id')
            ->limit($limit)
            ->get()
            ->toArray();
    }

    public function markProcessing(int $id, string $leaseUntil): void
    {
        Db::table('notification_outbox')->where('id', $id)->update([
            'status' => 'processing',
            'lease_until' => $leaseUntil,
            'updated_at' => date('Y-m-d H:i:s'),
        ]);
    }

    public function markSent(int $id): void
    {
        Db::table('notification_outbox')->where('id', $id)->update([
            'status' => 'sent',
            'sent_at' => date('Y-m-d H:i:s'),
            'updated_at' => date('Y-m-d H:i:s'),
        ]);
    }

    public function markRetry(int $id, int $attempts, string $availableAt, string $lastResponse): void
    {
        Db::table('notification_outbox')->where('id', $id)->update([
            'status' => $attempts >= 3 ? 'failed' : 'pending',
            'attempts' => $attempts,
            'available_at' => $availableAt,
            'last_response' => substr($lastResponse, 0, 2000),
            'updated_at' => date('Y-m-d H:i:s'),
        ]);
    }
}
