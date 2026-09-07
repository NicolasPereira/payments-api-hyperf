<?php

declare(strict_types=1);

namespace App\Infrastructure\Http\Middleware;

use Hyperf\Context\Context;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * Ensures X-Correlation-Id is present for every HTTP request.
 * - If client sends X-Correlation-Id, reuses it.
 * - Otherwise generates a UUIDv4-like ID.
 * - Stores in Context and echoes back in response header.
 * - Also adds trace correlation to logs via Context.
 */
final class CorrelationMiddleware implements MiddlewareInterface
{
    public const HEADER = 'X-Correlation-Id';

    public const CONTEXT_KEY = 'correlation_id';

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $correlationId = $request->getHeaderLine(self::HEADER);
        if ($correlationId === '') {
            $correlationId = $this->generateId();
        }

        // Store in coroutine context for downstream use (ExceptionMapper, UseCases, logs)
        Context::set(self::CONTEXT_KEY, $correlationId);
        // Also set as attribute for framework convenience
        $request = $request->withAttribute(self::CONTEXT_KEY, $correlationId);

        $response = $handler->handle($request);

        return $response->withHeader(self::HEADER, $correlationId);
    }

    private function generateId(): string
    {
        // UUID v4 without external lib
        $bytes = random_bytes(16);
        $bytes[6] = chr((ord($bytes[6]) & 0x0f) | 0x40);
        $bytes[8] = chr((ord($bytes[8]) & 0x3f) | 0x80);

        return sprintf(
            '%s-%s-%s-%s-%s',
            bin2hex(substr($bytes, 0, 4)),
            bin2hex(substr($bytes, 4, 2)),
            bin2hex(substr($bytes, 6, 2)),
            bin2hex(substr($bytes, 8, 2)),
            bin2hex(substr($bytes, 10, 6)),
        );
    }
}
