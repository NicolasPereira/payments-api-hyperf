<?php

declare(strict_types=1);

namespace HyperfTest\Unit\Domain\User;

use App\Domain\User\Exception\InvalidDocumentException;
use App\Domain\User\ValueObject\DocumentConsumer;
use PHPUnit\Framework\TestCase;

/**
 * T016 Unit test CPF normalização/validação
 * spec: 529.982.247-25 valid, 11111111111 rejected, DV invalid, format invalid
 */
final class CpfTest extends TestCase
{
    public function testValidCpfFormatted(): void
    {
        $doc = new DocumentConsumer('529.982.247-25');
        self::assertSame('52998224725', $doc->getValue());
        self::assertSame('cpf', $doc->getType()->value);
    }

    public function testValidCpfNormalizedStripsFormattingAndSpaces(): void
    {
        $doc = new DocumentConsumer(' 529.982.247-25 ');
        self::assertSame('52998224725', $doc->getValue());
    }

    public function testValidCpfDigitsOnly(): void
    {
        $doc = new DocumentConsumer('52998224725');
        self::assertSame('52998224725', $doc->getValue());
    }

    public function testInvalidCpfAllEqual111(): void
    {
        $this->expectException(InvalidDocumentException::class);
        new DocumentConsumer('111.111.111-11');
    }

    public function testInvalidCpfAllEqual000(): void
    {
        $this->expectException(InvalidDocumentException::class);
        new DocumentConsumer('000.000.000-00');
    }

    public function testInvalidCpfAllEqual111Raw(): void
    {
        $this->expectException(InvalidDocumentException::class);
        new DocumentConsumer('11111111111');
    }

    public function testInvalidCpfWrongDv(): void
    {
        $this->expectException(InvalidDocumentException::class);
        // valid is 52998224725, change last digit to 6
        new DocumentConsumer('529.982.247-26');
    }

    public function testInvalidCpfFormatShort(): void
    {
        $this->expectException(InvalidDocumentException::class);
        new DocumentConsumer('123.456');
    }

    public function testInvalidCpfFormatWithLetters(): void
    {
        $this->expectException(InvalidDocumentException::class);
        new DocumentConsumer('529.982.247-2A');
    }

    public function testInvalidCpfEmpty(): void
    {
        $this->expectException(InvalidDocumentException::class);
        new DocumentConsumer('');
    }

    public function testInvalidCpfWithCnpjLength(): void
    {
        $this->expectException(InvalidDocumentException::class);
        new DocumentConsumer('11.222.333/0001-81');
    }

    public function testNormalizeStripsDotDashSlashSpace(): void
    {
        self::assertSame('52998224725', DocumentConsumer::normalize('529.982.247-25'));
        self::assertSame('52998224725', DocumentConsumer::normalize(' 529.982.247-25 '));
        self::assertSame('52998224725', DocumentConsumer::normalize('529/982/247-25'));
    }

    public function testHttpStatusIs422(): void
    {
        try {
            new DocumentConsumer('11111111111');
            self::fail('Expected InvalidDocumentException');
        } catch (InvalidDocumentException $e) {
            self::assertSame(422, $e->getHttpStatus());
            self::assertSame('invalid_document', $e->getBusinessCode());
        }
    }
}
