<?php

declare(strict_types=1);

namespace HyperfTest\Unit\Domain\User;

use App\Domain\Shared\Exception\DomainException;
use App\Domain\User\ValueObject\Email;
use PHPUnit\Framework\TestCase;

/**
 * T020 Unit test Email normalização
 * case-insensitive A@b.com ≡ a@b.com, unicidade via normalize, formato
 */
final class EmailTest extends TestCase
{
    public function testValidEmailNormalizedLowercase(): void
    {
        $email = new Email('A@B.COM');
        self::assertSame('a@b.com', $email->getValue());
        self::assertSame('a@b.com', (string) $email);
    }

    public function testValidEmailTrimsSpaces(): void
    {
        $email = new Email('  Maria@Example.COM  ');
        self::assertSame('maria@example.com', $email->getValue());
    }

    public function testCaseInsensitiveEquality(): void
    {
        $a = new Email('A@b.com');
        $b = new Email('a@B.com');
        self::assertTrue($a->equals($b));
        self::assertSame($a->getValue(), $b->getValue());
    }

    public function testNormalizeStatic(): void
    {
        self::assertSame('a@b.com', Email::normalize('A@b.com'));
        self::assertSame('a@b.com', Email::normalize(' A@b.com '));
    }

    public function testInvalidEmailMissingAt(): void
    {
        $this->expectException(DomainException::class);
        new Email('invalid-email');
    }

    public function testInvalidEmailMissingDomain(): void
    {
        $this->expectException(DomainException::class);
        new Email('a@');
    }

    public function testInvalidEmailEmpty(): void
    {
        $this->expectException(DomainException::class);
        new Email('');
    }

    public function testInvalidEmailWithSpacesInside(): void
    {
        $this->expectException(DomainException::class);
        new Email('a @b.com');
    }

    public function testHttpStatusIs422(): void
    {
        try {
            new Email('not-email');
            self::fail('Expected DomainException');
        } catch (DomainException $e) {
            self::assertSame(422, $e->getHttpStatus());
        }
    }
}
