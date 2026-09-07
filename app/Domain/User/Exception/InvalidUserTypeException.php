<?php

declare(strict_types=1);

namespace App\Domain\User\Exception;

use App\Domain\Shared\Exception\DomainException;
use Throwable;

final class InvalidUserTypeException extends DomainException
{
    public function __construct(string $message = 'Tipo de documento incompatível com tipo de usuário', ?Throwable $previous = null)
    {
        parent::__construct($message, 'invalid_user_type', 422, $previous);
    }
}
