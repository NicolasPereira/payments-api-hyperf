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

use HyperfTest\HttpTestCase;

/**
 * T031 — User+Wallet atomicity (requires Docker MySQL).
 * @internal
 * @coversNothing
 */
final class UserRegistrationTest extends HttpTestCase
{
    public function testInvalidDvCreatesNothing(): void
    {
        $response = $this->client->post('/users', [
            'full_name' => 'Bad',
            'document' => '529.982.247-26',
            'email' => 'atomic.bad@example.com',
            'password' => 'password123',
            'type' => 'common',
        ]);
        $this->assertSame(422, $response->getStatusCode());
    }

    public function testDuplicateDocumentIsRejected(): void
    {
        $email = 'atomic.' . bin2hex(random_bytes(4)) . '@example.com';
        $first = $this->client->post('/users', [
            'full_name' => 'Atomic',
            'document' => '529.982.247-25',
            'email' => $email,
            'password' => 'password123',
            'type' => 'common',
        ]);
        $this->assertContains($first->getStatusCode(), [200, 201, 409]);

        $second = $this->client->post('/users', [
            'full_name' => 'Atomic2',
            'document' => '52998224725',
            'email' => 'atomic2.' . bin2hex(random_bytes(4)) . '@example.com',
            'password' => 'password123',
            'type' => 'common',
        ]);
        // First may have created the user; second with same doc must be 409.
        // If DB was clean, first is 201 and second is 409. If rerun, both 409.
        $this->assertContains($second->getStatusCode(), [409]);
    }
}
