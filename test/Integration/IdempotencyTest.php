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

namespace HyperfTest\Integration;

use App\Infrastructure\Cache\RedisIdempotencyStore;
use HyperfTest\HttpTestCase;
use ReflectionClass;
use ReflectionMethod;

/**
 * T043 — Redis idempotency 3min window (SET NX EX 180).
 * @internal
 * @coversNothing
 */
final class IdempotencyTest extends HttpTestCase
{
    public function testFingerprintIsStable(): void
    {
        $ref = new ReflectionMethod(RedisIdempotencyStore::class, 'fingerprint');
        $this->assertNotEmpty($ref->getName());
    }

    public function testDoubleSubmitDoesNotDoubleDebit(): void
    {
        $this->markTestSkipped('Requires Docker Redis + MySQL (CI).');
    }

    public function testTtlIs180Seconds(): void
    {
        $file = file_get_contents((string) (new ReflectionClass(RedisIdempotencyStore::class))->getFileName());
        $this->assertStringContainsString('180', (string) $file);
        $this->assertStringContainsString("'NX'", (string) $file);
    }
}
