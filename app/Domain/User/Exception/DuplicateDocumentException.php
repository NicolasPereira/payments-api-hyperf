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

final class DuplicateDocumentException extends DomainException
{
    public function __construct(string $message = 'Documento já cadastrado', ?Throwable $previous = null)
    {
        parent::__construct($message, 'duplicate_document', 409, $previous);
    }
}
