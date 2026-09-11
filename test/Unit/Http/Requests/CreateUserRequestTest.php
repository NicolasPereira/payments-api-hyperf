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

namespace HyperfTest\Unit\Http\Requests;

use App\Domain\User\Exception\InvalidEmailException;
use App\Domain\User\Exception\InvalidFullNameException;
use App\Domain\User\Exception\InvalidPasswordException;
use App\Domain\User\Exception\InvalidUserTypeException;
use App\Http\Requests\CreateUserRequest;
use PHPUnit\Framework\TestCase;

/**
 * @internal
 * @coversNothing
 */
final class CreateUserRequestTest extends TestCase
{
    private CreateUserRequest $request;

    protected function setUp(): void
    {
        $this->request = new CreateUserRequest();
    }

    public function testAuthorizes(): void
    {
        $this->assertTrue($this->request->authorize());
    }

    public function testExposesRules(): void
    {
        $rules = $this->request->rules();
        foreach (['full_name', 'document', 'email', 'password', 'type'] as $field) {
            $this->assertArrayHasKey($field, $rules);
            $this->assertContains('required', $rules[$field]);
        }
    }

    public function testValidatesHappyPath(): void
    {
        $validated = $this->request->validate([
            'full_name' => 'Maria Silva',
            'document' => '529.982.247-25',
            'email' => 'Maria@Example.com',
            'password' => 'password123',
            'type' => 'common',
        ]);
        $this->assertSame('Maria Silva', $validated['full_name']);
        $this->assertSame('common', $validated['type']);
    }

    public function testRejectsMissingFullName(): void
    {
        $this->expectException(InvalidFullNameException::class);
        $this->request->validate([
            'document' => '529.982.247-25',
            'email' => 'a@b.com',
            'password' => 'password123',
            'type' => 'common',
        ]);
    }

    public function testRejectsInvalidEmail(): void
    {
        $this->expectException(InvalidEmailException::class);
        $this->request->validate([
            'full_name' => 'Maria',
            'document' => '529.982.247-25',
            'email' => 'not-an-email',
            'password' => 'password123',
            'type' => 'common',
        ]);
    }

    public function testRejectsShortPassword(): void
    {
        $this->expectException(InvalidPasswordException::class);
        $this->request->validate([
            'full_name' => 'Maria',
            'document' => '529.982.247-25',
            'email' => 'a@b.com',
            'password' => 'short',
            'type' => 'common',
        ]);
    }

    public function testRejectsInvalidType(): void
    {
        $this->expectException(InvalidUserTypeException::class);
        $this->request->validate([
            'full_name' => 'Maria',
            'document' => '529.982.247-25',
            'email' => 'a@b.com',
            'password' => 'password123',
            'type' => 'admin',
        ]);
    }
}
