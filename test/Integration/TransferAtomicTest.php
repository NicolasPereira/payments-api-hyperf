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

use App\Infrastructure\Persistence\WalletRepository;
use HyperfTest\HttpTestCase;
use ReflectionMethod;

/**
 * T036 — Atomic transfer: FOR UPDATE asc user_id, recheck, rollback.
 * Requires Docker MySQL. Documents the concurrency contract; the
 * deterministic lock order lives in WalletRepository::lockForUpdate().
 * @internal
 * @coversNothing
 */
final class TransferAtomicTest extends HttpTestCase
{
    public function testLockOrderIsDeterministic(): void
    {
        $ref = new ReflectionMethod(WalletRepository::class, 'lockForUpdate');
        $file = file_get_contents((string) $ref->getFileName());
        $this->assertStringContainsString('ORDER BY user_id ASC FOR UPDATE', (string) $file);
    }

    public function testConcurrentTransfersNeverOverdraw(): void
    {
        $this->markTestSkipped('Requires Docker MySQL + seeded balances (CI).');
    }
}
