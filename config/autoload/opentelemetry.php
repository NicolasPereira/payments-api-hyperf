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
use function Hyperf\Support\env;

return [
    // Enable OpenTelemetry SDK per Constitution VI and plan.md:68
    'enable' => (bool) env('OTEL_ENABLED', true),

    // Service name for traces/metrics/logs
    'service_name' => env('OTEL_SERVICE_NAME', 'payments-api-hyperf'),

    // Environment (dev, staging, prod)
    'service_version' => env('APP_VERSION', '1.0.0'),

    // OTLP exporter configuration
    'exporter' => [
        'endpoint' => env('OTEL_EXPORTER_OTLP_ENDPOINT', 'http://localhost:4317'),
        'protocol' => env('OTEL_EXPORTER_OTLP_PROTOCOL', 'grpc'), // grpc or http/protobuf
        'headers' => env('OTEL_EXPORTER_OTLP_HEADERS', ''),
        'timeout' => (int) env('OTEL_EXPORTER_OTLP_TIMEOUT', 1000),
        'insecure' => (bool) env('OTEL_EXPORTER_OTLP_INSECURE', true),
    ],

    // Traces
    'traces' => [
        'enabled' => (bool) env('OTEL_TRACES_ENABLED', true),
        'sampler' => env('OTEL_TRACES_SAMPLER', 'always_on'), // always_on, always_off, traceidratio
        'sampler_arg' => env('OTEL_TRACES_SAMPLER_ARG', '1.0'),
    ],

    // Metrics
    'metrics' => [
        'enabled' => (bool) env('OTEL_METRICS_ENABLED', true),
        'export_interval_millis' => (int) env('OTEL_METRIC_EXPORT_INTERVAL', 30000),
    ],

    // Logs correlation with traces
    'logs' => [
        'enabled' => (bool) env('OTEL_LOGS_ENABLED', true),
        'inject_trace_context' => true,
    ],

    // Resource attributes
    'resource' => [
        'service.namespace' => env('OTEL_RESOURCE_NAMESPACE', 'picpay'),
        'deployment.environment' => env('APP_ENV', 'dev'),
    ],

    // Instrumentation: which hyperf components to auto-instrument
    'instrumentation' => [
        'http_server' => true,
        'http_client' => true,
        'database' => true,
        'redis' => true,
    ],
];
