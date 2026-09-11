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

final class MerchantPayerNotAllowedException extends DomainException
{
    public function __construct(string $message = 'Merchant cannot send transfers.', ?Throwable $previous = null)
    {
        parent::__construct($message, 'merchant_payer_blocked', 403, $previous);
    }
}
