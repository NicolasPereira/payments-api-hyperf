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
