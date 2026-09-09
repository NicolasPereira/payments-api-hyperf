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

namespace App\Domain\Wallet\Exception;

use App\Domain\Shared\Exception\DomainException;
use Throwable;

final class InsufficientBalanceException extends DomainException
{
    public function __construct(string $message = 'Saldo insuficiente', ?Throwable $previous = null)
    {
        parent::__construct($message, 'insufficient_balance', 422, $previous);
    }
}
