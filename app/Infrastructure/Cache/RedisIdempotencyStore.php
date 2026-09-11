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

namespace App\Infrastructure\Cache;

use App\Domain\Contracts\IdempotencyStore;
use Hyperf\Redis\Redis;

final class RedisIdempotencyStore implements IdempotencyStore
{
    private const PREFIX = 'idempotency:transfer:';

    private const TTL_SECONDS = 180;

    public function __construct(private readonly Redis $redis)
    {
    }

    public function fingerprint(int $payer, int $payee, string $value): string
    {
        return hash('sha256', $payer . ':' . $payee . ':' . $value);
    }

    /**
     * Atomic reserve via SET NX EX 180.
     * Returns true when this request owns the window, false on replay.
     */
    public function reserve(string $fingerprint, string $payload): bool
    {
        $result = $this->redis->set(self::PREFIX . $fingerprint, $payload, ['NX', 'EX' => self::TTL_SECONDS]);

        return (bool) $result;
    }

    public function get(string $fingerprint): ?string
    {
        $value = $this->redis->get(self::PREFIX . $fingerprint);

        return $value === false ? null : (string) $value;
    }

    public function put(string $fingerprint, string $payload): void
    {
        $this->redis->setex(self::PREFIX . $fingerprint, self::TTL_SECONDS, $payload);
    }
}
