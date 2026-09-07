<?php

declare(strict_types=1);

namespace App\Domain\User\Exception;

use App\Domain\Shared\Exception\DomainException;
use Throwable;

final class InvalidDocumentException extends DomainException
{
    public function __construct(string $message = 'Documento inválido', ?Throwable $previous = null)
    {
        parent::__construct($message, 'invalid_document', 422, $previous);
    }
}
