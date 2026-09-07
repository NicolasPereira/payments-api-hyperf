<?php

declare(strict_types=1);

namespace App\Domain\Transfer\Exception;

use App\Domain\Shared\Exception\DomainException;
use Throwable;

final class SelfTransferException extends DomainException
{
    public function __construct(string $message = 'Transferência para si mesmo não é permitida', ?Throwable $previous = null)
    {
        parent::__construct($message, 'self_transfer', 422, $previous);
    }
}
