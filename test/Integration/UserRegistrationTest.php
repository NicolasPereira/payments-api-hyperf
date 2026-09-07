<?php

declare(strict_types=1);

namespace HyperfTest\Integration;

use App\Application\User\CreateUserUseCase;
use App\Domain\Shared\ValueObject\Money;
use App\Domain\User\Exception\DuplicateDocumentException;
use App\Domain\User\Exception\InvalidDocumentException;
use App\Infrastructure\Persistence\UserRepository;
use App\Infrastructure\Persistence\WalletRepository;
use App\Infrastructure\Persistence\Database;
use Hyperf\DbConnection\Db;
use PHPUnit\Framework\TestCase;

/**
 * T031 Integration test atomicidade User+Wallet
 * Tests: rollback em DV inválido, constraint única, User+Wallet atomic creation
 * Requires MySQL via Database::transaction. If DB not available, tests are skipped.
 */
final class UserRegistrationTest extends TestCase
{
    private UserRepository $users;
    private WalletRepository $wallets;
    private CreateUserUseCase $useCase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->users = new UserRepository();
        $this->wallets = new WalletRepository();
        $this->useCase = new CreateUserUseCase($this->users, $this->wallets);
    }

    private function isDbAvailable(): bool
    {
        try {
            Db::select('SELECT 1');
            return true;
        } catch (\Throwable) {
            return false;
        }
    }

    private function cleanTestArtifacts(string $document, string $email): void
    {
        try {
            $user = $this->users->findByDocument($document);
            if ($user !== null && $user->getId() !== null) {
                Db::table('wallets')->where('user_id', $user->getId())->delete();
                Db::table('users')->where('id', $user->getId())->delete();
            }
            $userByEmail = $this->users->findByEmail($email);
            if ($userByEmail !== null && $userByEmail->getId() !== null) {
                Db::table('wallets')->where('user_id', $userByEmail->getId())->delete();
                Db::table('users')->where('id', $userByEmail->getId())->delete();
            }
        } catch (\Throwable) {
            // ignore cleanup errors
        }
    }

    public function testCreateCommonUserCreatesWalletZero(): void
    {
        if (!$this->isDbAvailable()) {
            self::markTestSkipped('DB not available for integration test');
        }

        $doc = '52998224725';
        $email = 'test.common.' . uniqid() . '@example.com';
        $this->cleanTestArtifacts($doc, $email);

        $result = $this->useCase->execute([
            'full_name' => 'Maria Silva',
            'document' => '529.982.247-25',
            'email' => $email,
            'password' => 'password123',
            'type' => 'common',
        ]);

        self::assertNotNull($result['user']->getId());
        self::assertSame('52998224725', $result['user']->getDocument()->getValue());
        self::assertSame('common', $result['user']->getType()->value);
        self::assertSame('0.00', $result['wallet']->getBalance()->getAmount());
        self::assertSame($result['user']->getId(), $result['wallet']->getUserId());

        // Verify password hash is ARGON2ID or BCRYPT and verifies
        self::assertTrue(password_verify('password123', $result['user']->getPasswordHash()));

        // Cleanup
        $this->cleanTestArtifacts($doc, $email);
    }

    public function testCreateMerchantAlfaCreatesWalletZero(): void
    {
        if (!$this->isDbAvailable()) {
            self::markTestSkipped('DB not available for integration test');
        }

        $doc = '12ABC34501DE35';
        $email = 'test.merchant.alfa.' . uniqid() . '@example.com';
        $this->cleanTestArtifacts($doc, $email);

        $result = $this->useCase->execute([
            'full_name' => 'Loja Alfa',
            'document' => '12.ABC.345/01DE-35',
            'email' => $email,
            'password' => 'password123',
            'type' => 'merchant',
        ]);

        self::assertSame('12ABC34501DE35', $result['user']->getDocument()->getValue());
        self::assertSame('cnpj', $result['user']->getDocumentType()->value);
        self::assertSame('0.00', $result['wallet']->getBalance()->getAmount());

        $this->cleanTestArtifacts($doc, $email);
    }

    public function testInvalidDocumentDoesNotPersistUserOrWallet(): void
    {
        if (!$this->isDbAvailable()) {
            // Pure domain check still validates without DB: ensure no persistence would happen
            $this->expectException(InvalidDocumentException::class);
            $this->useCase->execute([
                'full_name' => 'Invalid',
                'document' => '529.982.247-26', // DV invalid
                'email' => 'invalid.' . uniqid() . '@example.com',
                'password' => 'password123',
                'type' => 'common',
            ]);
            return;
        }

        $doc = '52998224726'; // invalid DV
        $email = 'invalid.' . uniqid() . '@example.com';
        $countBeforeUsers = (int) Db::table('users')->count();
        $countBeforeWallets = (int) Db::table('wallets')->count();

        try {
            $this->useCase->execute([
                'full_name' => 'Invalid',
                'document' => '529.982.247-26',
                'email' => $email,
                'password' => 'password123',
                'type' => 'common',
            ]);
            self::fail('Expected InvalidDocumentException');
        } catch (InvalidDocumentException $e) {
            self::assertSame(422, $e->getHttpStatus());
        }

        $countAfterUsers = (int) Db::table('users')->count();
        $countAfterWallets = (int) Db::table('wallets')->count();

        self::assertSame($countBeforeUsers, $countAfterUsers, 'Invalid doc must not create user');
        self::assertSame($countBeforeWallets, $countAfterWallets, 'Invalid doc must not create wallet');
        self::assertNull($this->users->findByEmail($email));
    }

    public function testDuplicateDocumentIsRejectedWith409(): void
    {
        if (!$this->isDbAvailable()) {
            self::markTestSkipped('DB not available for integration test');
        }

        $docRaw = '529.982.247-25';
        $docNorm = '52998224725';
        $email1 = 'dup1.' . uniqid() . '@example.com';
        $email2 = 'dup2.' . uniqid() . '@example.com';
        $this->cleanTestArtifacts($docNorm, $email1);
        $this->cleanTestArtifacts($docNorm, $email2);

        $this->useCase->execute([
            'full_name' => 'User One',
            'document' => $docRaw,
            'email' => $email1,
            'password' => 'password123',
            'type' => 'common',
        ]);

        try {
            $this->useCase->execute([
                'full_name' => 'User Two',
                'document' => $docNorm, // same normalized
                'email' => $email2,
                'password' => 'password123',
                'type' => 'common',
            ]);
            self::fail('Expected DuplicateDocumentException for duplicate document');
        } catch (DuplicateDocumentException $e) {
            self::assertSame(409, $e->getHttpStatus());
        } finally {
            $this->cleanTestArtifacts($docNorm, $email1);
            $this->cleanTestArtifacts($docNorm, $email2);
        }
    }

    public function testDuplicateEmailCaseInsensitiveIsRejected(): void
    {
        if (!$this->isDbAvailable()) {
            self::markTestSkipped('DB not available for integration test');
        }

        // Need two valid distinct CPFs: generate known valid ones
        // Use 52998224725 and 11144477735 (known valid CPF)
        $email = 'CaseTest.' . uniqid() . '@Example.COM';
        $emailLower = strtolower($email);
        $doc1 = '52998224725';
        $doc2 = '11144477735';
        $this->cleanTestArtifacts($doc1, $emailLower);
        $this->cleanTestArtifacts($doc2, $emailLower);

        $this->useCase->execute([
            'full_name' => 'User One',
            'document' => '529.982.247-25',
            'email' => $email,
            'password' => 'password123',
            'type' => 'common',
        ]);

        try {
            $this->useCase->execute([
                'full_name' => 'User Two',
                'document' => '111.444.777-35',
                'email' => strtolower($email), // same normalized
                'password' => 'password123',
                'type' => 'common',
            ]);
            self::fail('Expected DuplicateDocumentException for duplicate email');
        } catch (DuplicateDocumentException $e) {
            self::assertSame(409, $e->getHttpStatus());
        } finally {
            $this->cleanTestArtifacts($doc1, $emailLower);
            $this->cleanTestArtifacts($doc2, $emailLower);
        }
    }

    public function testAtomicityCnpjAlfaDuplicateCaseInsensitive(): void
    {
        if (!$this->isDbAvailable()) {
            self::markTestSkipped('DB not available for integration test');
        }

        $docUpper = '12ABC34501DE35';
        $docLower = '12abc34501de35';
        $email1 = 'alfa1.' . uniqid() . '@example.com';
        $email2 = 'alfa2.' . uniqid() . '@example.com';
        $this->cleanTestArtifacts($docUpper, $email1);
        $this->cleanTestArtifacts($docUpper, $email2);

        $this->useCase->execute([
            'full_name' => 'Loja Alfa 1',
            'document' => '12.ABC.345/01DE-35',
            'email' => $email1,
            'password' => 'password123',
            'type' => 'merchant',
        ]);

        try {
            $this->useCase->execute([
                'full_name' => 'Loja Alfa 2',
                'document' => $docLower, // same normalized case-insensitive
                'email' => $email2,
                'password' => 'password123',
                'type' => 'merchant',
            ]);
            self::fail('Expected duplicate for CNPJ alfa case-insensitive');
        } catch (DuplicateDocumentException $e) {
            self::assertSame(409, $e->getHttpStatus());
        } finally {
            $this->cleanTestArtifacts($docUpper, $email1);
            $this->cleanTestArtifacts($docUpper, $email2);
        }
    }

    public function testWalletBalanceNeverNegativeAndStartsZero(): void
    {
        if (!$this->isDbAvailable()) {
            self::markTestSkipped('DB not available for integration test');
        }

        $doc = '52998224725';
        $email = 'balance.' . uniqid() . '@example.com';
        $this->cleanTestArtifacts($doc, $email);

        $result = $this->useCase->execute([
            'full_name' => 'Balance Test',
            'document' => '529.982.247-25',
            'email' => $email,
            'password' => 'password123',
            'type' => 'common',
        ]);

        self::assertTrue($result['wallet']->getBalance()->equals(Money::zero()));
        self::assertFalse($result['wallet']->getBalance()->lessThan(Money::zero()));

        $this->cleanTestArtifacts($doc, $email);
    }
}
