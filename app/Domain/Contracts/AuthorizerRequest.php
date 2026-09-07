<?php

declare(strict_types=1);

namespace App\Domain\Contracts;

final class AuthorizerRequest
{
    public function __construct(
        public readonly int $payerId,
        public readonly int $payeeId,
        public readonly string $value,
        public readonly string $correlationId,
    ) {
    }
}
