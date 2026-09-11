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

use App\Domain\Transfer\Exception\TransferValidationException;

/**
 * Boundary validation for POST /transfer.
 * Shape/syntax here; financial meaning stays in TransferValue + UseCase.
 */
final class TransferRequest
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
            'value' => ['required', 'string', 'regex:/^(?!0+\.00$)[0-9]+\.[0-9]{2}$/'],
            'payer' => ['required', 'integer', 'min:1'],
            'payee' => ['required', 'integer', 'min:1'],
        ];
    }

    /**
     * @return array{value:string, payer:int, payee:int}
     */
    public function validate(mixed $data): array
    {
        if (! is_array($data)) {
            throw new TransferValidationException('Invalid request body.');
        }
        if (! isset($data['value']) || ! is_string($data['value'])) {
            throw new TransferValidationException('Field value must be a string like "10.00".');
        }
        if (preg_match('/^(?!0+\.00$)[0-9]+\.[0-9]{2}$/', $data['value']) !== 1) {
            throw new TransferValidationException('Field value must match ^(?!0+\.00$)[0-9]+\.[0-9]{2}$.');
        }
        foreach (['payer', 'payee'] as $field) {
            if (! isset($data[$field]) || filter_var($data[$field], FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) === false) {
                throw new TransferValidationException("Field {$field} must be an integer >= 1.");
            }
        }

        return [
            'value' => $data['value'],
            'payer' => (int) $data['payer'],
            'payee' => (int) $data['payee'],
        ];
    }
}
