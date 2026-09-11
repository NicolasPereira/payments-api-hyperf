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

use App\Domain\Transfer\Entity\Transfer;
use App\Domain\Transfer\Entity\TransferStatus;
use App\Domain\Transfer\ValueObject\TransferValue;
use App\Domain\User\Entity\UserType;
use Hyperf\DbConnection\Db;

class TransferRepository
{
    public function create(Transfer $transfer): Transfer
    {
        $id = (int) Db::table('transfers')->insertGetId([
            'value' => $transfer->value->amount(),
            'payer_id' => $transfer->payerId,
            'payee_id' => $transfer->payeeId,
            'status' => $transfer->status->value,
            'idempotency_key' => $transfer->idempotencyKey,
            'correlation_id' => $transfer->correlationId,
            'authorized_at' => $transfer->status !== TransferStatus::PENDING ? date('Y-m-d H:i:s') : null,
            'completed_at' => $transfer->status === TransferStatus::COMPLETED ? date('Y-m-d H:i:s') : null,
            'created_at' => date('Y-m-d H:i:s'),
            'updated_at' => date('Y-m-d H:i:s'),
        ]);

        return $transfer->withId($id);
    }

    public function updateStatus(int $id, TransferStatus $status): void
    {
        Db::table('transfers')->where('id', $id)->update([
            'status' => $status->value,
            'authorized_at' => $status !== TransferStatus::PENDING ? date('Y-m-d H:i:s') : null,
            'completed_at' => $status === TransferStatus::COMPLETED ? date('Y-m-d H:i:s') : null,
            'updated_at' => date('Y-m-d H:i:s'),
        ]);
    }

    public function findById(int $id): ?Transfer
    {
        $row = Db::table('transfers')->where('id', $id)->first();
        if ($row === null) {
            return null;
        }
        $r = is_array($row) ? (object) $row : $row;

        // Payer type unknown at hydration; entity guard needs it — default CONSUMER
        // is safe here because merchant-payer transfers can never persist.
        $transfer = Transfer::create(
            (int) $r->payer_id,
            (int) $r->payee_id,
            TransferValue::fromString((string) $r->value),
            UserType::CONSUMER,
            isset($r->idempotency_key) ? (string) $r->idempotency_key : null,
            isset($r->correlation_id) ? (string) $r->correlation_id : null,
        );

        return $transfer->withId((int) $r->id);
    }
}
