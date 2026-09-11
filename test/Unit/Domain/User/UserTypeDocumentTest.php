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

use App\Domain\User\Entity\UserType;
use App\Domain\User\Exception\InvalidUserTypeException;
use App\Domain\User\ValueObject\DocumentFactory;
use PHPUnit\Framework\TestCase;

/**
 * T019 — Type/document compatibility.
 * @internal
 * @coversNothing
 */
final class UserTypeDocumentTest extends TestCase
{
    public function testCommonAcceptsCpf(): void
    {
        $doc = DocumentFactory::for(UserType::COMMON, '529.982.247-25');
        $this->assertSame('52998224725', $doc->getValue());
    }

    public function testMerchantAcceptsCnpj(): void
    {
        $doc = DocumentFactory::for(UserType::MERCHANT, '12.ABC.345/01DE-35');
        $this->assertSame('12ABC34501DE35', $doc->getValue());
    }

    public function testCommonRejectsCnpj(): void
    {
        $this->expectException(InvalidUserTypeException::class);
        DocumentFactory::for(UserType::COMMON, '11222333000181');
    }

    public function testMerchantRejectsCpf(): void
    {
        $this->expectException(InvalidUserTypeException::class);
        DocumentFactory::for(UserType::MERCHANT, '52998224725');
    }
}
