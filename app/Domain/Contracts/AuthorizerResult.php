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
        public readonly string $rawResponse,
        public readonly ?string $errorCode = null,
        public readonly ?string $errorMessage = null,
    ) {
    }

    public static function authorized(string $rawResponse): self
    {
        return new self(true, $rawResponse);
    }

    public static function denied(string $rawResponse, ?string $reason = null): self
    {
        return new self(false, $rawResponse, 'authorizer_denied', $reason ?? 'Authorizer denied');
    }

    public static function failed(string $rawResponse, string $errorCode, string $errorMessage): self
    {
        return new self(false, $rawResponse, $errorCode, $errorMessage);
    }

    public function isAuthorized(): bool
    {
        return $this->authorized;
    }
}
