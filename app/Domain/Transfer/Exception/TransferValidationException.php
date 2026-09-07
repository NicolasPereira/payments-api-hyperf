<?php

declare(strict_types=1);

namespace App\Domain\Transfer\Exception;

use App\Domain\Shared\Exception\DomainException;
use Throwable;

final class TransferValidationException extends DomainException
{
    public function __construct(string $message = 'Dados de transferência inválidos', ?Throwable $previous = null)
    {
        parent::__construct($message, 'transfer_validation', 422, $previous);
    }
}
