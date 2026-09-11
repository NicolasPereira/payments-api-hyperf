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

use App\Domain\User\Exception\InvalidPasswordException;
use App\Domain\User\ValueObject\PasswordHash;
use App\Domain\User\ValueObject\PlainPassword;
use PHPUnit\Framework\TestCase;

/**
 * @internal
 * @coversNothing
 */
final class PasswordTest extends TestCase
{
    public function testRejectsShortPassword(): void
    {
        $this->expectException(InvalidPasswordException::class);
        PlainPassword::fromString('short');
    }

    public function testHashesAndVerifies(): void
    {
        $hash = PasswordHash::fromPlain(PlainPassword::fromString('password123'));
        $this->assertTrue(password_verify('password123', $hash->getValue()));
    }

    public function testRejectsEmptyHash(): void
    {
        $this->expectException(InvalidPasswordException::class);
        PasswordHash::fromHash('');
    }
}
