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

namespace HyperfTest\Unit\Http\Requests;

use App\Domain\Transfer\Exception\TransferValidationException;
use App\Http\Requests\TransferRequest;
use PHPUnit\Framework\TestCase;

/**
 * @internal
 * @coversNothing
 */
final class TransferRequestTest extends TestCase
{
    private TransferRequest $request;

    protected function setUp(): void
    {
        $this->request = new TransferRequest();
    }

    public function testValidatesHappyPath(): void
    {
        $validated = $this->request->validate(['value' => '10.00', 'payer' => 1, 'payee' => 2]);
        $this->assertSame('10.00', $validated['value']);
    }

    public function testRejectsNumberValue(): void
    {
        $this->expectException(TransferValidationException::class);
        $this->request->validate(['value' => 10.0, 'payer' => 1, 'payee' => 2]);
    }

    public function testRejectsZero(): void
    {
        $this->expectException(TransferValidationException::class);
        $this->request->validate(['value' => '0.00', 'payer' => 1, 'payee' => 2]);
    }

    public function testRejectsMissingPayee(): void
    {
        $this->expectException(TransferValidationException::class);
        $this->request->validate(['value' => '10.00', 'payer' => 1]);
    }
}
