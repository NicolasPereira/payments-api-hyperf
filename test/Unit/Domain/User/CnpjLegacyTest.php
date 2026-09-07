<?php

declare(strict_types=1);

namespace HyperfTest\Unit\Domain\User;

use App\Domain\User\Exception\InvalidDocumentException;
use App\Domain\User\ValueObject\DocumentMerchant;
use PHPUnit\Framework\TestCase;

/**
 * T017 Unit test CNPJ legado numérico
 * 11.222.333/0001-81 => 11222333000181, uppercase normalization, DV module 11
 */
final class CnpjLegacyTest extends TestCase
{
    public function testValidLegacyFormatted(): void
    {
        $doc = new DocumentMerchant('11.222.333/0001-81');
        self::assertSame('11222333000181', $doc->getValue());
        self::assertSame('cnpj', $doc->getType()->value);
    }

    public function testValidLegacyDigitsOnly(): void
    {
        $doc = new DocumentMerchant('11222333000181');
        self::assertSame('11222333000181', $doc->getValue());
    }

    public function testValidLegacyWithSpaces(): void
    {
        $doc = new DocumentMerchant(' 11.222.333/0001-81 ');
        self::assertSame('11222333000181', $doc->getValue());
    }

    public function testInvalidLegacyWrongDv(): void
    {
        $this->expectException(InvalidDocumentException::class);
        // change last digit 1->2
        new DocumentMerchant('11.222.333/0001-82');
    }

    public function testInvalidLegacyWrongDvSecond(): void
    {
        $this->expectException(InvalidDocumentException::class);
        // 11222333000181 -> 11222333000180
        new DocumentMerchant('11222333000180');
    }

    public function testInvalidLegacyAllEqualZeros(): void
    {
        $this->expectException(InvalidDocumentException::class);
        new DocumentMerchant('00.000.000/0000-00');
    }

    public function testInvalidLegacyAllEqualOnes(): void
    {
        $this->expectException(InvalidDocumentException::class);
        new DocumentMerchant('11111111111111');
    }

    public function testInvalidLegacyAllEqualBase(): void
    {
        $this->expectException(InvalidDocumentException::class);
        // AAAAAAAAAAAA00 case extended — first 12 all equal should reject even though DV might be 00
        new DocumentMerchant('AAAAAAAAAAAA00');
    }

    public function testInvalidFormatShort(): void
    {
        $this->expectException(InvalidDocumentException::class);
        new DocumentMerchant('11222333');
    }

    public function testNormalizeUppercaseAndStrip(): void
    {
        self::assertSame('11222333000181', DocumentMerchant::normalize('11.222.333/0001-81'));
        self::assertSame('11222333000181', DocumentMerchant::normalize(' 11.222.333/0001-81 '));
    }

    public function testLegacyIsSubsetOfAlfaPattern(): void
    {
        // Legacy numeric must still be accepted as valid CNPJ alfa pattern subset
        $doc = new DocumentMerchant('11222333000181');
        self::assertMatchesRegularExpression('/^[A-Z0-9]{12}[0-9]{2}$/', $doc->getValue());
        self::assertMatchesRegularExpression('/^[0-9]{14}$/', $doc->getValue());
    }
}
