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

namespace HyperfTest\Contract;

use HyperfTest\HttpTestCase;

/**
 * T021 — POST /users contract (TDD Red until Lote 3 controller).
 * @internal
 * @coversNothing
 */
final class UsersContractTest extends HttpTestCase
{
    public function testCreatesCommonUser(): void
    {
        $response = $this->client->post('/users', [
            'full_name' => 'Maria Silva',
            'document' => '529.982.247-25',
            'email' => 'maria.lote2@example.com',
            'password' => 'password123',
            'type' => 'common',
        ]);
        $this->assertContains($response->getStatusCode(), [200, 201]);
        $body = (string) $response->getBody();
        $data = json_decode($body, true);
        $this->assertSame('52998224725', $data['document'] ?? null);
    }

    public function testCreatesMerchantAlfa(): void
    {
        $response = $this->client->post('/users', [
            'full_name' => 'Loja Alfa',
            'document' => '12.ABC.345/01DE-35',
            'email' => 'alfa.lote2@example.com',
            'password' => 'password123',
            'type' => 'merchant',
        ]);
        $this->assertContains($response->getStatusCode(), [200, 201]);
    }

    public function testRejectsDuplicateDocument(): void
    {
        $this->client->post('/users', [
            'full_name' => 'Dup',
            'document' => '529.982.247-25',
            'email' => 'dup.lote2@example.com',
            'password' => 'password123',
            'type' => 'common',
        ]);
        $second = $this->client->post('/users', [
            'full_name' => 'Dup2',
            'document' => '52998224725',
            'email' => 'dup2.lote2@example.com',
            'password' => 'password123',
            'type' => 'common',
        ]);
        $this->assertSame(409, $second->getStatusCode());
    }

    public function testRejectsInvalidDv(): void
    {
        $response = $this->client->post('/users', [
            'full_name' => 'Bad',
            'document' => '529.982.247-26',
            'email' => 'bad.lote2@example.com',
            'password' => 'password123',
            'type' => 'common',
        ]);
        $this->assertSame(422, $response->getStatusCode());
    }
}
