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

final class AuthorizerResult
{
    private function __construct(
        public readonly bool $authorized,
        public readonly string $reason = '',
    ) {
    }

    public static function authorized(): self
    {
        return new self(true, 'authorized');
    }

    public static function denied(string $reason = 'denied'): self
    {
        return new self(false, $reason);
    }

    public static function unavailable(string $reason = 'authorizer_unavailable'): self
    {
        return new self(false, $reason);
    }

    public static function invalid(string $reason = 'authorizer_invalid_response'): self
    {
        return new self(false, $reason);
    }
}
