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

/**
 * Port for external authorizer GET https://util.devi.tools/api/v2/authorize
 * per research Decision 6 and contracts/external-services.md:4.
 */
interface AuthorizerPort
{
    public function authorize(AuthorizerRequest $request): AuthorizerResult;
}
