<?php

declare(strict_types=1);

namespace App\Domain\Transfer\Entity;

enum TransferStatus: string
{
    case PENDING = 'pending';
    case AUTHORIZED = 'authorized';
    case COMPLETED = 'completed';
    case FAILED = 'failed';

    public function isTerminal(): bool
    {
        return $this === self::COMPLETED || $this === self::FAILED;
    }
}
