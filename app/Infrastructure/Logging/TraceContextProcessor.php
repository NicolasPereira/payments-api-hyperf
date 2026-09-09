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

namespace App\Infrastructure\Logging;

use Hyperf\Context\Context;
use Monolog\LogRecord;
use OpenTelemetry\API\Trace\Span;
use Throwable;

/**
 * Monolog processor injecting correlation_id, trace_id and span_id for structured logs
 * per Constitution VI observability and OTEL.
 */
final class TraceContextProcessor
{
    public function __invoke(LogRecord $record): LogRecord
    {
        $extra = $record->extra;

        // Correlation ID from middleware context
        try {
            $correlationId = Context::get('correlation_id');
            if (is_string($correlationId) && $correlationId !== '') {
                $extra['correlation_id'] = $correlationId;
            }
        } catch (Throwable) {
            // ignore when context not available
        }

        // OTEL trace context if SDK is available
        if (class_exists(Span::class)) {
            try {
                $span = Span::getCurrent();
                $ctx = $span->getContext();
                if ($ctx->isValid()) {
                    $extra['trace_id'] = $ctx->getTraceId();
                    $extra['span_id'] = $ctx->getSpanId();
                    $extra['trace_flags'] = $ctx->getTraceFlags();
                }
            } catch (Throwable) {
                // ignore OTEL failures, logs should not crash app
            }
        }

        // Also try to get trace from Hyperf Context if manually set by instrumentation
        try {
            $traceId = Context::get('trace_id');
            if (is_string($traceId) && $traceId !== '' && ! isset($extra['trace_id'])) {
                $extra['trace_id'] = $traceId;
            }
            $spanId = Context::get('span_id');
            if (is_string($spanId) && $spanId !== '' && ! isset($extra['span_id'])) {
                $extra['span_id'] = $spanId;
            }
        } catch (Throwable) {
        }

        return $record->with(extra: $extra);
    }
}
