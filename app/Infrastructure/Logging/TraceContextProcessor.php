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
use OpenTelemetry\API\Trace\Span;

final class TraceContextProcessor
{
    public function __invoke(array $record): array
    {
        $correlationId = Context::get('correlation_id');
        $record['extra']['correlation_id'] = $correlationId;
        // OTEL trace context is attached here when SDK is present.
        if (class_exists(Span::class)) {
            $span = Span::getCurrent();
            $ctx = $span->getContext();
            if ($ctx->isValid()) {
                $record['extra']['trace_id'] = $ctx->getTraceId();
                $record['extra']['span_id'] = $ctx->getSpanId();
            }
        }

        return $record;
    }
}
