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

namespace App\Domain\Contracts;

final class NotifyRequest
{
    public function __construct(
        public readonly int $transferId,
        public readonly int $payeeId,
        public readonly string $correlationId,
        public readonly array $payload = [],
    ) {
    }
}
