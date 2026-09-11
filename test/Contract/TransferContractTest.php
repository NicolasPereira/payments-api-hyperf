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
 * T035 — POST /transfer contract (requires Docker: MySQL + authorizer mock).
 * @internal
 * @coversNothing
 */
final class TransferContractTest extends HttpTestCase
{
    public function testTransferValidationRejectsSelfTransfer(): void
    {
        $response = $this->client->post('/transfer', [
            'value' => '10.00',
            'payer' => 1,
            'payee' => 1,
        ]);
        $this->assertSame(422, $response->getStatusCode());
    }

    public function testTransferValidationRejectsNumberValue(): void
    {
        $response = $this->client->post('/transfer', [
            'value' => 10.0,
            'payer' => 1,
            'payee' => 2,
        ]);
        $this->assertSame(422, $response->getStatusCode());
    }

    public function testTransferValidationRejectsZero(): void
    {
        $response = $this->client->post('/transfer', [
            'value' => '0.00',
            'payer' => 1,
            'payee' => 2,
        ]);
        $this->assertSame(422, $response->getStatusCode());
    }

    public function testTransferMissingPayerReturns404(): void
    {
        $response = $this->client->post('/transfer', [
            'value' => '10.00',
            'payer' => 999999,
            'payee' => 999998,
        ]);
        $this->assertContains($response->getStatusCode(), [404, 503]);
    }
}
