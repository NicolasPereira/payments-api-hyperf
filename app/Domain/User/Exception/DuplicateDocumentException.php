<?php

declare(strict_types=1);

namespace App\Domain\User\Exception;

use App\Domain\Shared\Exception\DomainException;
use Throwable;

final class DuplicateDocumentException extends DomainException
{
    public function __construct(string $message = 'Documento já cadastrado', ?Throwable $previous = null)
    {
        parent::__construct($message, 'duplicate_document', 409, $previous);
    }
}
