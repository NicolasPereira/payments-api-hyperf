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

namespace HyperfTest\Unit\Domain\User;

use App\Domain\User\Exception\InvalidDocumentException;
use App\Domain\User\ValueObject\DocumentMerchant;
use PHPUnit\Framework\TestCase;

/**
 * T017 — Legacy numeric CNPJ.
 * @internal
 * @coversNothing
 */
final class CnpjLegacyTest extends TestCase
{
    public function testAcceptsFormattedLegacyCnpj(): void
    {
        $doc = new DocumentMerchant('11.222.333/0001-81');
        $this->assertSame('11222333000181', $doc->getValue());
    }

    public function testAcceptsUnformattedLegacyCnpj(): void
    {
        $doc = new DocumentMerchant('11222333000181');
        $this->assertSame('11222333000181', $doc->getValue());
    }

    public function testRejectsInvalidDv(): void
    {
        $this->expectException(InvalidDocumentException::class);
        new DocumentMerchant('11.222.333/0001-82');
    }

    public function testRejectsAllEqual(): void
    {
        $this->expectException(InvalidDocumentException::class);
        new DocumentMerchant('11111111111111');
    }
}
