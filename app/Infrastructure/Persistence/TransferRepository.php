<?php

declare(strict_types=1);

namespace App\Infrastructure\Persistence;

use App\Domain\Shared\ValueObject\Money;
use App\Domain\Transfer\Entity\Transfer;
use App\Domain\Transfer\Entity\TransferStatus;
use Hyperf\DbConnection\Db;

final class TransferRepository
{
    public function findById(int $id): ?Transfer
    {
        $row = Db::table('transfers')->where('id', $id)->first();
        if ($row === null) {
            return null;
        }

        return $this->hydrate($row);
    }

    /**
     * @return Transfer[]|array<int,Transfer>
     */
    public function findByPayerId(int $payerId): array
    {
        $rows = Db::table('transfers')->where('payer_id', $payerId)->get();
        $result = [];
        foreach ($rows as $row) {
            $result[] = $this->hydrate($row);
        }

        return $result;
    }

    public function create(Transfer $transfer): Transfer
    {
        $now = date('Y-m-d H:i:s');
        $id = (int) Db::table('transfers')->insertGetId([
            'value' => $transfer->getValue()->getAmount(),
            'payer_id' => $transfer->getPayerId(),
            'payee_id' => $transfer->getPayeeId(),
            'status' => $transfer->getStatus()->value,
            'idempotency_key' => $transfer->getIdempotencyKey(),
            'authorization_result' => $transfer->getAuthorizationResult(),
            'correlation_id' => $transfer->getCorrelationId(),
            'authorized_at' => $transfer->getStatus() === TransferStatus::COMPLETED ? $now : null,
            'completed_at' => $transfer->getStatus() === TransferStatus::COMPLETED ? $now : null,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        return $transfer->withId($id);
    }

    /**
     * Persist transfer with explicit data array (used by UseCase transaction).
     */
    public function insert(array $data): int
    {
        return (int) Db::table('transfers')->insertGetId($data);
    }

    /**
     * @param object|array $row
     */
    private function hydrate(object|array $row): Transfer
    {
        $r = is_array($row) ? (object) $row : $row;

        $value = Money::fromString((string) $r->value);
        $status = TransferStatus::from((string) $r->status);

        return new Transfer(
            $value,
            (int) $r->payer_id,
            (int) $r->payee_id,
            $status,
            (int) $r->id,
            $r->idempotency_key ?? null,
            $r->correlation_id ?? null,
            $r->authorization_result ?? null,
            isset($r->created_at) ? (string) $r->created_at : null,
            isset($r->updated_at) ? (string) $r->updated_at : null,
        );
    }
}
