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

final class ErrorResponse
{
    public function __construct(
        public readonly string $code,
        public readonly string $message,
        public readonly ?string $correlationId = null,
    ) {
    }

    public function toArray(): array
    {
        $data = ['code' => $this->code, 'message' => $this->message];
        if ($this->correlationId !== null) {
            $data['correlation_id'] = $this->correlationId;
        }

        return $data;
    }
}
