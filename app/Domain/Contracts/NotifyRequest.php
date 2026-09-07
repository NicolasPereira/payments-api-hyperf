<?php

declare(strict_types=1);

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
