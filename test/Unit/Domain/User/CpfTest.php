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
use App\Domain\User\ValueObject\DocumentConsumer;
use PHPUnit\Framework\TestCase;

/**
 * T016 — CPF normalization/validation (TDD).
 * @internal
 * @coversNothing
 */
final class CpfTest extends TestCase
{
    public function testAcceptsFormattedCpf(): void
    {
        $doc = new DocumentConsumer('529.982.247-25');
        $this->assertSame('52998224725', $doc->getValue());
        $this->assertSame('52998224725', (string) $doc);
    }

    public function testAcceptsUnformattedCpf(): void
    {
        $doc = new DocumentConsumer('52998224725');
        $this->assertSame('52998224725', $doc->getValue());
    }

    public function testRejectsInvalidCheckDigits(): void
    {
        $this->expectException(InvalidDocumentException::class);
        new DocumentConsumer('529.982.247-26');
    }

    public function testRejectsAllEqual(): void
    {
        $this->expectException(InvalidDocumentException::class);
        new DocumentConsumer('11111111111');
    }

    public function testRejectsWrongLength(): void
    {
        $this->expectException(InvalidDocumentException::class);
        new DocumentConsumer('123.456');
    }

    public function testRejectsLetters(): void
    {
        $this->expectException(InvalidDocumentException::class);
        new DocumentConsumer('5299822472A');
    }
}
