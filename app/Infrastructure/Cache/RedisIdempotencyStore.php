<?php

declare(strict_types=1);

namespace App\Infrastructure\Cache;

use Hyperf\Redis\RedisFactory;
use Psr\Log\LoggerInterface;
use Redis;

/**
 * Idempotency store using Redis SET NX EX 180 atomic per research Decision 3.
 * Key = hash(payer+payee+value) with TTL 180s. Value = transfer result JSON.
 */
final class RedisIdempotencyStore
{
    private const TTL_SECONDS = 180;

    private const PREFIX = 'idempotency:transfer:';

    public function __construct(
        private readonly RedisFactory $redisFactory,
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * Attempt to reserve idempotency key atomically.
     * Returns true if key was set (first request), false if already exists (duplicate within window).
     */
    public function tryReserve(string $fingerprint, string $value = '1', int $ttl = self::TTL_SECONDS): bool
    {
        $redis = $this->getRedis();
        $key = self::PREFIX . $fingerprint;

        // SET key value NX EX ttl atomic
        $result = $redis->set($key, $value, ['NX', 'EX' => $ttl]);

        if ($result) {
            $this->logger->debug('idempotency reserved', ['key' => $key, 'ttl' => $ttl]);
            return true;
        }

        $this->logger->info('idempotency hit', ['key' => $key]);

        return false;
    }

    /**
     * Get stored idempotency value if exists.
     */
    public function get(string $fingerprint): ?string
    {
        $redis = $this->getRedis();
        $key = self::PREFIX . $fingerprint;
        $value = $redis->get($key);

        if ($value === false || $value === null) {
            $this->logger->debug('idempotency miss', ['key' => $key]);
            return null;
        }

        return (string) $value;
    }

    /**
     * Store result for the fingerprint (for second-phase set after transfer completes).
     * Uses SET with EX if not already reserved? Typically reserve then store result.
     */
    public function storeResult(string $fingerprint, string $resultJson, int $ttl = self::TTL_SECONDS): void
    {
        $redis = $this->getRedis();
        $key = self::PREFIX . $fingerprint;
        $redis->setex($key, $ttl, $resultJson);
        $this->logger->debug('idempotency result stored', ['key' => $key]);
    }

    /**
     * Generate fingerprint hash per spec: hash(payer+payee+value)
     */
    public static function fingerprint(int $payerId, int $payeeId, string $value): string
    {
        $normalized = sprintf('%d:%d:%s', $payerId, $payeeId, $value);
        return hash('sha256', $normalized);
    }

    public static function key(string $fingerprint): string
    {
        return self::PREFIX . $fingerprint;
    }

    public function exists(string $fingerprint): bool
    {
        $redis = $this->getRedis();
        return (bool) $redis->exists(self::PREFIX . $fingerprint);
    }

    public function delete(string $fingerprint): void
    {
        $redis = $this->getRedis();
        $redis->del(self::PREFIX . $fingerprint);
    }

    private function getRedis(): Redis
    {
        /** @var Redis $redis */
        $redis = $this->redisFactory->get('default');
        return $redis;
    }
}
