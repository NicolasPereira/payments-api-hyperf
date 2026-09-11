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
 * T018 — Alphanumeric CNPJ IN 2.229/2024.
 * @internal
 * @coversNothing
 */
final class CnpjAlfaTest extends TestCase
{
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

    public function testRejectsInvalidDv(): void
    {
        $this->expectException(InvalidDocumentException::class);
        new DocumentMerchant('12ABC34501DE36');
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
