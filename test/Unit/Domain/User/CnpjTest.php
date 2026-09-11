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
 * T017+T018 — CNPJ agnóstico: legado numérico é subset do alfa IN 2.229/2024.
 * Uma única classe de domínio resolve sozinha.
 *
 * @internal
 * @coversNothing
 */
final class CnpjTest extends TestCase
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

    public function testAcceptsFormattedAlfa(): void
    {
        $doc = new DocumentMerchant('12.ABC.345/01DE-35');
        $this->assertSame('12ABC34501DE35', $doc->getValue());
    }

    public function testCaseInsensitive(): void
    {
        $doc = new DocumentMerchant('12abc34501de35');
        $this->assertSame('12ABC34501DE35', $doc->getValue());
    }

    public function testRejectsInvalidLegacyDv(): void
    {
        $this->expectException(InvalidDocumentException::class);
        new DocumentMerchant('11.222.333/0001-82');
    }

    public function testRejectsInvalidAlfaDv(): void
    {
        $this->expectException(InvalidDocumentException::class);
        new DocumentMerchant('12ABC34501DE36');
    }

    public function testRejectsAllEqual(): void
    {
        $this->expectException(InvalidDocumentException::class);
        new DocumentMerchant('11111111111111');
    }

    public function testRejectsAllEqualAlfa(): void
    {
        $this->expectException(InvalidDocumentException::class);
        new DocumentMerchant('AAAAAAAAAAAAAAAA');
    }

    public function testRejectsBaseAllEqual(): void
    {
        $this->expectException(InvalidDocumentException::class);
        new DocumentMerchant('AAAAAAAAAAAA00');
    }
}
