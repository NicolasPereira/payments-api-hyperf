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

use App\Domain\User\Entity\User;
use App\Domain\User\Entity\UserType;
use App\Domain\User\Exception\InvalidDocumentException;
use App\Domain\User\Exception\InvalidUserTypeException;
use App\Domain\User\ValueObject\DocumentConsumer;
use App\Domain\User\ValueObject\DocumentFactory;
use App\Domain\User\ValueObject\DocumentMerchant;
use App\Domain\User\ValueObject\DocumentType;
use App\Domain\User\ValueObject\Email;
use PHPUnit\Framework\TestCase;

/**
 * T019 Unit test compatibilidade tipo-documento
 * common + CNPJ => 422, merchant + CPF => 422.
 * @internal
 * @coversNothing
 */
final class UserTypeDocumentTest extends TestCase
{
    public function testCommonWithValidCpfSucceeds(): void
    {
        $doc = DocumentFactory::for(UserType::COMMON, '529.982.247-25');
        self::assertSame(DocumentType::CPF, $doc->getType());
        self::assertSame('52998224725', $doc->getValue());
    }

    public function testMerchantWithLegacyCnpjSucceeds(): void
    {
        $doc = DocumentFactory::for(UserType::MERCHANT, '11.222.333/0001-81');
        self::assertSame(DocumentType::CNPJ, $doc->getType());
        self::assertSame('11222333000181', $doc->getValue());
    }

    public function testMerchantWithAlfaCnpjSucceeds(): void
    {
        $doc = DocumentFactory::for(UserType::MERCHANT, '12.ABC.345/01DE-35');
        self::assertSame(DocumentType::CNPJ, $doc->getType());
        self::assertSame('12ABC34501DE35', $doc->getValue());
    }

    public function testCommonWithCnpjShouldFail(): void
    {
        $this->expectException(InvalidDocumentException::class);
        DocumentFactory::for(UserType::COMMON, '11.222.333/0001-81');
    }

    public function testMerchantWithCpfShouldFail(): void
    {
        $this->expectException(InvalidDocumentException::class);
        DocumentFactory::for(UserType::MERCHANT, '529.982.247-25');
    }

    public function testUserEntityCommonWithCnpjThrowsInvalidUserType(): void
    {
        $this->expectException(InvalidUserTypeException::class);
        $doc = new DocumentMerchant('11.222.333/0001-81');
        $email = new Email('a@b.com');
        new User('Maria Silva', $doc, $email, 'hashed', UserType::COMMON);
    }

    public function testUserEntityMerchantWithCpfThrowsInvalidUserType(): void
    {
        $this->expectException(InvalidUserTypeException::class);
        $doc = new DocumentConsumer('529.982.247-25');
        $email = new Email('loja@example.com');
        new User('Loja Exemplo', $doc, $email, 'hashed', UserType::MERCHANT);
    }

    public function testUserEntityValidCommonWithCpf(): void
    {
        $doc = new DocumentConsumer('529.982.247-25');
        $email = new Email('maria@example.com');
        $user = new User('Maria Silva', $doc, $email, 'hashed', UserType::COMMON);
        self::assertSame(UserType::COMMON, $user->getType());
        self::assertSame(DocumentType::CPF, $user->getDocumentType());
    }

    public function testUserEntityValidMerchantWithAlfa(): void
    {
        $doc = new DocumentMerchant('12.ABC.345/01DE-35');
        $email = new Email('loja.alfa@example.com');
        $user = new User('Loja Alfa', $doc, $email, 'hashed', UserType::MERCHANT);
        self::assertSame(UserType::MERCHANT, $user->getType());
        self::assertSame(DocumentType::CNPJ, $user->getDocumentType());
        self::assertSame('12ABC34501DE35', $user->getDocument()->getValue());
    }
}
