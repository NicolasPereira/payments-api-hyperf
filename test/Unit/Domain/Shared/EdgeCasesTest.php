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

namespace HyperfTest\Unit\Domain\Shared;

use App\Domain\Shared\Exception\DomainException;
use App\Domain\Shared\ValueObject\Money;
use App\Domain\Transfer\Entity\Transfer;
use App\Domain\Transfer\Exception\SelfTransferException;
use App\Domain\Transfer\Exception\TransferValidationException;
use App\Domain\Transfer\ValueObject\TransferValue;
use App\Domain\User\ValueObject\DocumentConsumer;
use App\Domain\User\ValueObject\DocumentMerchant;
use App\Domain\User\ValueObject\Email;
use PHPUnit\Framework\TestCase;

/**
 * T060 Unit tests adicionais edge cases
 * - value zero/negativo/number, payer==payee, documento lowercase com espaços, email case-insensitive
 * per spec Edge Cases + T060.
 * @internal
 * @coversNothing
 */
final class EdgeCasesTest extends TestCase
{
    // TransferValue: zero rejected
    public function testTransferValueZeroRejected(): void
    {
        $this->expectException(TransferValidationException::class);
        TransferValue::fromString('0.00');
    }

    public function testTransferValueZeroWithLeadingZerosRejected(): void
    {
        $this->expectException(TransferValidationException::class);
        TransferValue::fromString('00.00');
    }

    public function testTransferValueZeroViaMixedRejected(): void
    {
        $this->expectException(TransferValidationException::class);
        TransferValue::fromMixed('0.00');
    }

    // TransferValue: negative rejected
    public function testTransferValueNegativeRejected(): void
    {
        $this->expectException(TransferValidationException::class);
        TransferValue::fromString('-10.00');
    }

    public function testTransferValueNegativeViaMoneyRejected(): void
    {
        $this->expectException(TransferValidationException::class);
        TransferValue::fromMoney(Money::zero());
    }

    // TransferValue: number type rejected (int, float)
    public function testTransferValueNumberFloatRejected(): void
    {
        $this->expectException(TransferValidationException::class);
        TransferValue::fromMixed(10.0);
    }

    public function testTransferValueNumberIntRejected(): void
    {
        $this->expectException(TransferValidationException::class);
        TransferValue::fromMixed(10);
    }

    public function testTransferValueNumberStringNumericWithoutDecimalsRejected(): void
    {
        $this->expectException(TransferValidationException::class);
        TransferValue::fromString('10');
    }

    public function testTransferValueMoreThanTwoDecimalsRejected(): void
    {
        $this->expectException(TransferValidationException::class);
        TransferValue::fromString('10.001');
    }

    public function testTransferValueEmptyRejected(): void
    {
        $this->expectException(TransferValidationException::class);
        TransferValue::fromMixed('');
    }

    // payer==payee via Transfer entity
    public function testTransferPayerEqualsPayeeRejected(): void
    {
        $this->expectException(SelfTransferException::class);
        new Transfer(Money::fromString('10.00'), 1, 1);
    }

    public function testTransferPayerEqualsPayee422(): void
    {
        try {
            new Transfer(Money::fromString('5.00'), 42, 42);
            self::fail('Expected SelfTransferException');
        } catch (SelfTransferException $e) {
            self::assertSame(422, $e->getHttpStatus());
            self::assertSame('self_transfer', $e->getBusinessCode());
        }
    }

    // Documento lowercase com espaços (CPF formatado + CNPJ alfa)
    public function testCpfLowercaseWithSpacesNormalized(): void
    {
        $doc = new DocumentConsumer(' 529.982.247-25 ');
        self::assertSame('52998224725', $doc->getValue());
    }

    public function testCpfUppercaseNotApplicableButSpacesTrimmed(): void
    {
        $doc = new DocumentConsumer(' 52998224725 ');
        self::assertSame('52998224725', $doc->getValue());
    }

    public function testCnpjAlfaLowercaseWithSpacesNormalizedUppercase(): void
    {
        $doc = new DocumentMerchant(' 12.abc.345/01de-35 ');
        self::assertSame('12ABC34501DE35', $doc->getValue());
        self::assertSame('cnpj', $doc->getType()->value);
    }

    public function testCnpjAlfaLowercaseWithoutFormattingNormalized(): void
    {
        $doc = new DocumentMerchant('12abc34501de35');
        self::assertSame('12ABC34501DE35', $doc->getValue());
    }

    public function testCnpjAlfaUppercaseWithFormattingNormalized(): void
    {
        $doc = new DocumentMerchant('12.ABC.345/01DE-35');
        self::assertSame('12ABC34501DE35', $doc->getValue());
    }

    public function testCnpjLegacyNumericNormalized(): void
    {
        $doc = new DocumentMerchant(' 11.222.333/0001-81 ');
        self::assertSame('11222333000181', $doc->getValue());
    }

    public function testCnpjAlfaDvInvalidEvenWithLowercaseSpacesRejected(): void
    {
        $this->expectException(DomainException::class);
        new DocumentMerchant(' 12abc34501de36 ');
    }

    public function testDocumentConsumerRejectsAllEqualEvenWithSpaces(): void
    {
        $this->expectException(DomainException::class);
        new DocumentConsumer(' 111.111.111-11 ');
    }

    // Email case-insensitive
    public function testEmailCaseInsensitiveNormalizedLowercase(): void
    {
        $a = new Email('A@B.COM');
        $b = new Email('a@b.com');
        self::assertTrue($a->equals($b));
        self::assertSame('a@b.com', $a->getValue());
        self::assertSame('a@b.com', $b->getValue());
    }

    public function testEmailCaseInsensitiveWithSpacesTrimmed(): void
    {
        $a = new Email('  Test@Example.COM  ');
        $b = new Email('test@example.com');
        self::assertTrue($a->equals($b));
        self::assertSame('test@example.com', $a->getValue());
    }

    public function testEmailUppercaseLocalAndDomainNormalized(): void
    {
        $email = new Email('MARIA@EXAMPLE.COM');
        self::assertSame('maria@example.com', $email->getValue());
    }

    public function testEmailMixedCaseWithPlusTagNormalized(): void
    {
        $email = new Email('User+Tag@Example.COM');
        self::assertSame('user+tag@example.com', $email->getValue());
    }

    // Money edge: zero is allowed in Money but not in TransferValue
    public function testMoneyZeroAllowedButTransferValueRejectsZero(): void
    {
        $zero = Money::fromString('0.00');
        self::assertTrue($zero->isZero());
        self::assertSame('0.00', $zero->getAmount());

        $this->expectException(TransferValidationException::class);
        TransferValue::fromString($zero->getAmount());
    }

    public function testMoneyPositiveButTransferRequiresPositive(): void
    {
        $m = Money::fromString('0.01');
        $tv = TransferValue::fromMoney($m);
        self::assertSame('0.01', $tv->getAmount());
    }
}
