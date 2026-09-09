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

namespace App\Domain\Shared\Exception;

use Throwable;

final class NotFoundException extends DomainException
{
    public function __construct(string $message = 'Recurso não encontrado', string $businessCode = 'not_found', ?Throwable $previous = null)
    {
        parent::__construct($message, $businessCode, 404, $previous);
    }
}
