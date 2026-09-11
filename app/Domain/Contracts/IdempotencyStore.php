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

interface IdempotencyStore
{
    public function fingerprint(int $payer, int $payee, string $value): string;

    /**
     * Atomically reserves the window. True when this request owns it.
     */
    public function reserve(string $fingerprint, string $payload): bool;

    public function get(string $fingerprint): ?string;

    public function put(string $fingerprint, string $payload): void;
}
