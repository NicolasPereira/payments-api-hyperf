<?php

declare(strict_types=1);

namespace App\Infrastructure\Http;

use App\Domain\Shared\Exception\DomainException;
use Hyperf\Contract\StdoutLoggerInterface;
use Psr\Http\Message\ResponseInterface;
use Throwable;

/**
 * Maps DomainException -> ErrorResponse envelope + OTEL + metrics.
 * Per Constitution VI:152, VI:164 and tasks T008b.
 */
final class ExceptionMapper
{
    public function __construct(
        private readonly StdoutLoggerInterface $logger,
    ) {
    }

    /**
     * Map throwable to HTTP status and ErrorResponse.
     *
     * @return array{status:int,body:ErrorResponse}
     */
    public function map(Throwable $throwable, ?string $correlationId = null): array
    {
        $correlationId ??= $this->resolveCorrelationId();

        if ($throwable instanceof DomainException) {
            $status = $throwable->getHttpStatus();
            $code = $throwable->getBusinessCode();

            $this->recordMetrics($code, $throwable);
            $this->logWithOtel($throwable, $correlationId, $code);

            $response = new ErrorResponse($code, $throwable->getMessage(), $correlationId);

            return ['status' => $status, 'body' => $response];
        }

        // Fallback: unexpected exception -> 500
        $this->logger->error('Unhandled exception', [
            'exception.type' => $throwable::class,
            'exception.message' => $throwable->getMessage(),
            'correlation_id' => $correlationId,
            'trace' => $throwable->getTraceAsString(),
        ]);

        $response = new ErrorResponse('internal_error', 'Internal server error', $correlationId);

        return ['status' => 500, 'body' => $response];
    }

    public function toResponse(Throwable $throwable, ResponseInterface $response, ?string $correlationId = null): ResponseInterface
    {
        $mapped = $this->map($throwable, $correlationId);
        $status = $mapped['status'];
        /** @var ErrorResponse $body */
        $body = $mapped['body'];

        $payload = json_encode($body->toArray(), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        return $response
            ->withStatus($status)
            ->withHeader('Content-Type', 'application/json')
            ->withHeader('X-Correlation-Id', $body->correlationId ?? $correlationId ?? '')
            ->withBody(new \Hyperf\HttpMessage\Stream\SwooleStream($payload !== false ? $payload : '{}'));
    }

    private function recordMetrics(string $businessCode, Throwable $throwable): void
    {
        // Placeholder for metrics counter domain_exception_total{code}
        // In production this would increment a Prometheus/OTEL counter.
        // We log for observability without requiring a metrics library in this phase.
        $this->logger->debug('metrics domain_exception_total increment', [
            'code' => $businessCode,
            'exception.type' => $throwable::class,
        ]);
    }

    private function logWithOtel(Throwable $throwable, string $correlationId, string $businessCode): void
    {
        $this->logger->warning($throwable->getMessage(), [
            'exception.type' => $throwable::class,
            'exception.message' => $throwable->getMessage(),
            'business_code' => $businessCode,
            'http_status' => $throwable instanceof DomainException ? $throwable->getHttpStatus() : 500,
            'correlation_id' => $correlationId,
            // OTEL trace context would be injected via baggage/active span if SDK configured.
        ]);
    }

    private function resolveCorrelationId(): string
    {
        // Try to get from coroutine context if middleware already set it.
        try {
            $ctx = \Hyperf\Context\Context::get('correlation_id');
            if (is_string($ctx) && $ctx !== '') {
                return $ctx;
            }
        } catch (Throwable) {
            // ignore
        }

        return bin2hex(random_bytes(8));
    }
}
