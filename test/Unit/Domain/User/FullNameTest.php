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

use App\Domain\User\Exception\InvalidFullNameException;
use App\Domain\User\ValueObject\FullName;
use PHPUnit\Framework\TestCase;

/**
 * @internal
 * @coversNothing
 */
final class FullNameTest extends TestCase
{
    public function testAcceptsValidName(): void
    {
        $this->assertSame('Maria Silva', FullName::fromString('  Maria Silva  ')->getValue());
    }

    public function testRejectsEmpty(): void
    {
        $this->expectException(InvalidFullNameException::class);
        FullName::fromString('   ');
    }
}
