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

namespace App\Domain\Contracts;

interface AuthorizerPort
{
    public function authorize(int $payer, int $payee, string $value, ?string $correlationId = null): AuthorizerResult;
}
