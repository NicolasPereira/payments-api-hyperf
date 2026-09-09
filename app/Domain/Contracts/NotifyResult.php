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

final class NotifyResult
{
    private function __construct(
        public readonly bool $sent,
        public readonly int $httpStatus,
        public readonly string $rawResponse,
        public readonly ?string $errorMessage = null,
    ) {
    }

    public static function sent(int $httpStatus, string $rawResponse): self
    {
        return new self(true, $httpStatus, $rawResponse);
    }

    public static function failed(int $httpStatus, string $rawResponse, string $errorMessage): self
    {
        return new self(false, $httpStatus, $rawResponse, $errorMessage);
    }

    public function isSent(): bool
    {
        return $this->sent;
    }
}
