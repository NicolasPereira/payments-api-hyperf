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

namespace App\Http\Requests;

use App\Domain\User\Exception\InvalidDocumentException;
use App\Domain\User\Exception\InvalidEmailException;
use App\Domain\User\Exception\InvalidFullNameException;
use App\Domain\User\Exception\InvalidPasswordException;
use App\Domain\User\Exception\InvalidUserTypeException;

/**
 * Boundary validation for POST /users.
 *
 * Mirrors Laravel FormRequest shape (authorize + rules) without
 * framework magic: shape/syntax lives here, domain meaning
 * (check digits, uniqueness) stays in VOs + UseCase.
 */
final class CreateUserRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, string[]>
     */
    public function rules(): array
    {
        return [
            'full_name' => ['required', 'string', 'max:255'],
            'document' => ['required', 'string'],
            'email' => ['required', 'string', 'email', 'max:255'],
            'password' => ['required', 'string', 'min:8'],
            'type' => ['required', 'string', 'in:common,merchant,consumer'],
        ];
    }

    /**
     * @return array{full_name:string, document:string, email:string, password:string, type:string}
     */
    public function validate(mixed $data): array
    {
        if (! is_array($data)) {
            throw new InvalidFullNameException('Invalid request body.');
        }

        $fullName = isset($data['full_name']) ? (string) $data['full_name'] : '';
        $document = isset($data['document']) ? (string) $data['document'] : '';
        $email = isset($data['email']) ? (string) $data['email'] : '';
        $password = isset($data['password']) ? (string) $data['password'] : '';
        $type = isset($data['type']) ? (string) $data['type'] : '';

        if (trim($fullName) === '') {
            throw new InvalidFullNameException('Field full_name is required.');
        }
        if (mb_strlen(trim($fullName)) > 255) {
            throw new InvalidFullNameException('Field full_name must not exceed 255 characters.');
        }
        if (trim($document) === '') {
            throw new InvalidDocumentException('Field document is required.');
        }
        if (trim($email) === '' || filter_var(strtolower(trim($email)), FILTER_VALIDATE_EMAIL) === false) {
            throw new InvalidEmailException('Field email must be a valid email.');
        }
        if ($password === '' || mb_strlen($password) < 8) {
            throw new InvalidPasswordException('Field password must be at least 8 characters.');
        }
        if (! in_array(strtolower(trim($type)), ['common', 'merchant', 'consumer'], true)) {
            throw new InvalidUserTypeException('Field type must be common or merchant.');
        }

        return [
            'full_name' => $fullName,
            'document' => $document,
            'email' => $email,
            'password' => $password,
            'type' => $type,
        ];
    }
}
