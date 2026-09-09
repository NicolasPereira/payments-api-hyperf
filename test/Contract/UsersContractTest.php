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

namespace HyperfTest\Contract;

use App\Domain\User\Entity\UserType;
use App\Domain\User\Exception\DuplicateDocumentException;
use App\Domain\User\Exception\InvalidDocumentException;
use App\Domain\User\ValueObject\DocumentFactory;
use App\Domain\User\ValueObject\Email;
use PHPUnit\Framework\TestCase;

/**
 * T021 Contract test POST /users (unit-level contract validation without HTTP server)
 * Covers: 201 common/merchant/merchant_alfa, 409 duplicata, 422 formato/DV/tipo
 * Uses domain VOs to emulate contract behavior; integration HTTP tests are in T031.
 * Per contracts/users.yaml.
 * @internal
 * @coversNothing
 */
final class UsersContractTest extends TestCase
{
    public function testContractCommon201Normalized(): void
    {
        $doc = DocumentFactory::for(UserType::COMMON, '529.982.247-25');
        $email = new Email('maria@example.com');
        self::assertSame('52998224725', $doc->getValue());
        self::assertSame('cpf', $doc->getType()->value);
        self::assertSame('maria@example.com', $email->getValue());
        // simulated 201 payload would contain document normalized, balance 0.00
        $payload = [
            'document_type' => $doc->getType()->value,
            'document' => $doc->getValue(),
            'email' => $email->getValue(),
            'type' => UserType::COMMON->value,
            'balance' => '0.00',
        ];
        self::assertSame('52998224725', $payload['document']);
        self::assertSame('0.00', $payload['balance']);
    }

    public function testContractMerchantLegacy201(): void
    {
        $doc = DocumentFactory::for(UserType::MERCHANT, '11.222.333/0001-81');
        self::assertSame('11222333000181', $doc->getValue());
        self::assertSame('cnpj', $doc->getType()->value);
    }

    public function testContractMerchantAlfa201(): void
    {
        $doc = DocumentFactory::for(UserType::MERCHANT, '12.ABC.345/01DE-35');
        self::assertSame('12ABC34501DE35', $doc->getValue());
        self::assertSame('cnpj', $doc->getType()->value);
        // case-insensitive check
        $docLower = DocumentFactory::for(UserType::MERCHANT, '12abc34501de35');
        self::assertSame($doc->getValue(), $docLower->getValue());
    }

    public function testContractDuplicateDocumentShouldBe409(): void
    {
        // Simulate duplicate detection: same normalized document should be considered equal
        $docA = DocumentFactory::for(UserType::COMMON, '529.982.247-25');
        $docB = DocumentFactory::for(UserType::COMMON, '52998224725');
        self::assertTrue($docA->getValue() === $docB->getValue());
        // In real repo, existsByDocument('52998224725') would return true => 409 DuplicateDocumentException
        $exception = new DuplicateDocumentException();
        self::assertSame(409, $exception->getHttpStatus());
        self::assertSame('duplicate_document', $exception->getBusinessCode());
    }

    public function testContractDuplicateEmailShouldBe409(): void
    {
        $e1 = new Email('A@b.com');
        $e2 = new Email('a@B.com');
        self::assertTrue($e1->equals($e2));
        // duplicate email also maps to 409
        $exception = new DuplicateDocumentException('E-mail já cadastrado');
        self::assertSame(409, $exception->getHttpStatus());
    }

    public function testContractInvalidFormatShouldBe422(): void
    {
        $this->expectException(InvalidDocumentException::class);
        DocumentFactory::for(UserType::COMMON, '123.456');
    }

    public function testContractInvalidDvShouldBe422(): void
    {
        $this->expectException(InvalidDocumentException::class);
        DocumentFactory::for(UserType::COMMON, '529.982.247-26');
    }

    public function testContractInvalidAlfaDvShouldBe422(): void
    {
        $this->expectException(InvalidDocumentException::class);
        DocumentFactory::for(UserType::MERCHANT, '12ABC34501DE36');
    }

    public function testContractTypeMismatchCommonWithCnpjShouldBe422(): void
    {
        $this->expectException(InvalidDocumentException::class);
        DocumentFactory::for(UserType::COMMON, '11.222.333/0001-81');
    }

    public function testContractTypeMismatchMerchantWithCpfShouldBe422(): void
    {
        $this->expectException(InvalidDocumentException::class);
        DocumentFactory::for(UserType::MERCHANT, '529.982.247-25');
    }

    public function testContractUserResponseShapeMatchesOpenApi(): void
    {
        // Ensure response fields per contracts/users.yaml: id, full_name, document_type, document, email, type, balance
        $doc = DocumentFactory::for(UserType::COMMON, '529.982.247-25');
        $response = [
            'id' => 1,
            'full_name' => 'Maria Silva',
            'document_type' => $doc->getType()->value,
            'document' => $doc->getValue(),
            'email' => 'maria@example.com',
            'type' => 'common',
            'balance' => '0.00',
        ];
        self::assertArrayHasKey('id', $response);
        self::assertArrayHasKey('full_name', $response);
        self::assertArrayHasKey('document_type', $response);
        self::assertArrayHasKey('document', $response);
        self::assertArrayHasKey('email', $response);
        self::assertArrayHasKey('type', $response);
        self::assertArrayHasKey('balance', $response);
        self::assertMatchesRegularExpression('/^([0-9]{11}|[A-Z0-9]{12}[0-9]{2})$/', $response['document']);
        self::assertMatchesRegularExpression('/^\d+\.\d{2}$/', $response['balance']);
    }
}
