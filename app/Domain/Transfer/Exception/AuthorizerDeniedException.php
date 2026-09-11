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

namespace App\Domain\Transfer\Exception;

use App\Domain\Shared\Exception\DomainException;
use Throwable;

final class AuthorizerDeniedException extends DomainException
{
    public function __construct(string $message = 'Transfer not authorized.', ?Throwable $previous = null)
    {
        parent::__construct($message, 'authorizer_denied', 403, $previous);
    }
}
