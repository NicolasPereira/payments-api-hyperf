<?php

declare(strict_types=1);

namespace App\Domain\Contracts;

/**
 * Port for external authorizer GET https://util.devi.tools/api/v2/authorize
 * per research Decision 6 and contracts/external-services.md:4
 */
interface AuthorizerPort
{
    public function authorize(AuthorizerRequest $request): AuthorizerResult;
}
