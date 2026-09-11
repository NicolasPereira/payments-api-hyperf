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

use App\Domain\Shared\Exception\DomainException;
use App\Domain\User\ValueObject\Email;
use PHPUnit\Framework\TestCase;

/**
 * T020 — Email normalization.
 * @internal
 * @coversNothing
 */
final class EmailTest extends TestCase
{
    public function testLowercases(): void
    {
        $email = new Email('Maria@Example.COM');
        $this->assertSame('maria@example.com', $email->getValue());
    }

    public function testTrimsSpaces(): void
    {
        $email = new Email('  maria@example.com  ');
        $this->assertSame('maria@example.com', $email->getValue());
    }

    public function testCaseInsensitiveEquality(): void
    {
        $a = new Email('A@b.com');
        $b = new Email('a@B.COM');
        $this->assertTrue($a->equals($b));
    }

    public function testRejectsInvalid(): void
    {
        $this->expectException(DomainException::class);
        new Email('not-an-email');
    }
}
