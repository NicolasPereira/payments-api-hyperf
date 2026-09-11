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

use RuntimeException;
use Throwable;

abstract class DomainException extends RuntimeException
{
    public function __construct(
        string $message = '',
        private readonly string $businessCode = 'domain_error',
        private readonly int $httpStatus = 422,
        ?Throwable $previous = null,
    ) {
        parent::__construct($message, 0, $previous);
    }

    public function getBusinessCode(): string
    {
        return $this->businessCode;
    }

    public function getHttpStatus(): int
    {
        return $this->httpStatus;
    }
}
