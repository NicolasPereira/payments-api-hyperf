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
        public readonly string $response,
    ) {
    }

    public static function sent(string $response = '204'): self
    {
        return new self(true, $response);
    }

    public static function failed(string $response): self
    {
        return new self(false, $response);
    }
}
