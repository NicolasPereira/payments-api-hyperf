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

namespace App\Domain\Transfer\Entity;

enum TransferStatus: string
{
    case PENDING = 'pending';
    case AUTHORIZED = 'authorized';
    case COMPLETED = 'completed';
    case FAILED = 'failed';

    public function canTransitionTo(self $next): bool
    {
        return match ($this) {
            self::PENDING => $next === self::AUTHORIZED || $next === self::FAILED,
            self::AUTHORIZED => $next === self::COMPLETED || $next === self::FAILED,
            self::COMPLETED, self::FAILED => false,
        };
    }
}
