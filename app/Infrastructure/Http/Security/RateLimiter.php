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

namespace App\Infrastructure\Http\Security;

use Hyperf\Redis\RedisFactory;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * T062 Rate limiting per Constitution V (abuse-prone financial operations).
 * Sliding window via Redis INCR + EXPIRE. Fallback permissivo se Redis indisponível.
 */
final class RateLimiter
{
    public function __construct(
        private readonly RedisFactory $redisFactory,
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * Verifica se requisição deve ser permitida.
     * @param string $key chave (ex: ip:/transfer ou user:123)
     * @param int $maxRequests limite na janela
     * @param int $windowSeconds janela em segundos
     * @return bool true se permitido, false se rate limited
     */
    public function allow(string $key, int $maxRequests, int $windowSeconds): bool
    {
        $redisKey = 'ratelimit:' . $key;

        try {
            $redis = $this->redisFactory->get('default');
            $current = $redis->incr($redisKey);
            if ($current === 1) {
                $redis->expire($redisKey, $windowSeconds);
            }

            if ($current > $maxRequests) {
                $this->logger->warning('rate limited', ['key' => $key, 'current' => $current, 'limit' => $maxRequests]);
                return false;
            }

            return true;
        } catch (Throwable $e) {
            $this->logger->warning('rate limiter fallback allow', ['key' => $key, 'error' => $e->getMessage()]);
            return true;
        }
    }

    /**
     * Retorna TTL restante da janela para header Retry-After.
     */
    public function ttl(string $key): int
    {
        try {
            $redis = $this->redisFactory->get('default');
            $ttl = $redis->ttl('ratelimit:' . $key);
            return $ttl > 0 ? $ttl : 0;
        } catch (Throwable) {
            return 0;
        }
    }
}
