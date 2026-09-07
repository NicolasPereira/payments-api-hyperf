<?php

declare(strict_types=1);

namespace App\Domain\Transfer\Exception;

use App\Domain\Shared\Exception\DomainException;
use Throwable;

final class MerchantPayerNotAllowedException extends DomainException
{
    public function __construct(string $message = 'Lojista não pode enviar transferência', ?Throwable $previous = null)
    {
        parent::__construct($message, 'merchant_payer_blocked', 403, $previous);
    }
}
