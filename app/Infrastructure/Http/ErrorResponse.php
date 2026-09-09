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

namespace App\Infrastructure\Http;

use JsonSerializable;

/**
 * Consistent JSON error envelope per contracts/transfer.yaml:103 / users.yaml:95
 * { code, message, correlation_id }.
 */
final class ErrorResponse implements JsonSerializable
{
    public function __construct(
        public readonly string $code,
        public readonly string $message,
        public readonly ?string $correlationId = null,
    ) {
    }

    /**
     * @return array{code:string,message:string,correlation_id?:string}
     */
    public function toArray(): array
    {
        $data = [
            'code' => $this->code,
            'message' => $this->message,
        ];

        if ($this->correlationId !== null) {
            $data['correlation_id'] = $this->correlationId;
        }

        return $data;
    }

    public function jsonSerialize(): array
    {
        return $this->toArray();
    }

    public function withCorrelationId(string $correlationId): self
    {
        return new self($this->code, $this->message, $correlationId);
    }
}
