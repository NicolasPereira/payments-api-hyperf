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
 * T018 Unit test CNPJ alfanumérico IN 2.229/2024
 * 12.ABC.345/01DE-35 => 12ABC34501DE35, case-insensitive, ASCII-48 pesos 2-9, DV invalid 12ABC34501DE36 => 422.
 * @internal
 * @coversNothing
 */
final class CnpjAlfaTest extends TestCase
{
    public function testValidAlfaFormatted(): void
    {
        $doc = new DocumentMerchant('12.ABC.345/01DE-35');
        self::assertSame('12ABC34501DE35', $doc->getValue());
        self::assertSame('cnpj', $doc->getType()->value);
    }

    public function testValidAlfaLowercaseCaseInsensitive(): void
    {
        $doc = new DocumentMerchant('12abc34501de35');
        self::assertSame('12ABC34501DE35', $doc->getValue());
    }

    public function testValidAlfaWithSpacesAndLowercase(): void
    {
        $doc = new DocumentMerchant(' 12.abc.345/01de-35 ');
        self::assertSame('12ABC34501DE35', $doc->getValue());
    }

    public function testValidAlfaDigitsOnlyUppercasePersisted(): void
    {
        $doc = new DocumentMerchant('12ABC34501DE35');
        self::assertSame('12ABC34501DE35', $doc->getValue());
        self::assertMatchesRegularExpression('/^[A-Z0-9]{12}[0-9]{2}$/', $doc->getValue());
    }

    public function testInvalidAlfaWrongDv(): void
    {
        $this->expectException(InvalidDocumentException::class);
        new DocumentMerchant('12ABC34501DE36');
    }

    public function testInvalidAlfaWrongDvFirst(): void
    {
        $this->expectException(InvalidDocumentException::class);
        // change DV 35 -> 34 (first DV wrong)
        new DocumentMerchant('12ABC34501DE34');
    }

    public function testInvalidAlfaAllEqualA(): void
    {
        $this->expectException(InvalidDocumentException::class);
        new DocumentMerchant('AAAAAAAAAAAAAAAA');
    }

    public function testInvalidAlfaAllEqualBase12Plus00(): void
    {
        $this->expectException(InvalidDocumentException::class);
        new DocumentMerchant('AAAAAAAAAAAA00');
    }

    public function testInvalidAlfaFormatContainsInvalidChar(): void
    {
        $this->expectException(InvalidDocumentException::class);
        // contains '@' not allowed
        new DocumentMerchant('12ABC34501DE@5');
    }

    public function testInvalidAlfaPatternLastTwoMustBeNumeric(): void
    {
        $this->expectException(InvalidDocumentException::class);
        // last two must be digits, not letters
        new DocumentMerchant('12ABC34501DEAB');
    }

    public function testNormalizeUppercaseAndStripFormatting(): void
    {
        self::assertSame('12ABC34501DE35', DocumentMerchant::normalize('12.ABC.345/01DE-35'));
        self::assertSame('12ABC34501DE35', DocumentMerchant::normalize('12abc34501de35'));
        self::assertSame('12ABC34501DE35', DocumentMerchant::normalize(' 12.abc.345/01de-35 '));
    }

    public function testAscii48WeightsM11Calculation(): void
    {
        // Validate that both legacy and alfa use same ASCII-48 logic
        // 12ABC34501DE base should yield DV 35, not 36
        $valid = new DocumentMerchant('12ABC34501DE35');
        self::assertSame('35', substr($valid->getValue(), 12, 2));

        $this->expectException(InvalidDocumentException::class);
        new DocumentMerchant('12ABC34501DE36');
    }

    public function testHttpStatusIs422(): void
    {
        try {
            new DocumentMerchant('12ABC34501DE36');
            self::fail('Expected InvalidDocumentException');
        } catch (InvalidDocumentException $e) {
            self::assertSame(422, $e->getHttpStatus());
        }
    }
}
