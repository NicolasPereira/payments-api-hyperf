<?php

declare(strict_types=1);

namespace App\Domain\Shared\Exception;

use Throwable;

final class NotFoundException extends DomainException
{
    public function __construct(string $message = 'Recurso não encontrado', string $businessCode = 'not_found', ?Throwable $previous = null)
    {
        parent::__construct($message, $businessCode, 404, $previous);
    }
}
