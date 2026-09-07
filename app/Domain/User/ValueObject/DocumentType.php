<?php

declare(strict_types=1);

namespace App\Domain\User\ValueObject;

enum DocumentType: string
{
    case CPF = 'cpf';
    case CNPJ = 'cnpj';
}
