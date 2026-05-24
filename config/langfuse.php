<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Langfuse configuration (app-level override of mliviu79/laravel-langfuse-prism)
|--------------------------------------------------------------------------
|
| This file mirrors the vendor config but flips the tracing switches OFF BY
| DEFAULT. Reason: with a non-empty LANGFUSE_HOST but placeholder/blank keys,
| the OpenTelemetry OTLP exporter fires on every Claude call and the export is
| rejected "Unauthorized" — dumping a multi-line stack trace per agent call to
| stdout. Generation still succeeds and ClaudeClient tolerates the missing
| trace id, so this is pure noise.
|
| Laravel loads this app config before the package's service provider runs its
| mergeConfigFrom(), so these values win. To actually USE Langfuse later:
| bootstrap it, paste real keys, and set LANGFUSE_TRACING_ENABLED=true (and
| LANGFUSE_OTEL_ENABLED=true) in .env.
*/

return [
    'public_key' => env('LANGFUSE_PUBLIC_KEY'),
    'secret_key' => env('LANGFUSE_SECRET_KEY'),
    'host' => env('LANGFUSE_HOST', 'https://cloud.langfuse.com'),
    'timeout' => (int) env('LANGFUSE_TIMEOUT', 5),
    'debug' => (bool) env('LANGFUSE_DEBUG', false),

    // ── Master switches — DEFAULT OFF (see file header) ──────────────────
    'tracing_enabled' => (bool) env('LANGFUSE_TRACING_ENABLED', false),
    'environment' => env('LANGFUSE_TRACING_ENVIRONMENT', 'production'),
    'sample_rate' => (float) env('LANGFUSE_SAMPLE_RATE', 1.0),

    'release' => env('LANGFUSE_RELEASE'),
    'media_upload_thread_count' => (int) env('LANGFUSE_MEDIA_UPLOAD_THREAD_COUNT', 1),
    'additional_headers' => [],
    'blocked_instrumentation_scopes' => [],

    'prism' => [
        // Default OFF — no auto-instrumentation of Prism calls until configured.
        'auto_trace' => (bool) env('LANGFUSE_PRISM_AUTO_TRACE', false),
        'trace_model_params' => (bool) env('LANGFUSE_PRISM_TRACE_MODEL_PARAMS', true),
        'trace_usage' => (bool) env('LANGFUSE_PRISM_TRACE_USAGE', true),
        'trace_cost' => (bool) env('LANGFUSE_PRISM_TRACE_COST', true),
    ],

    // OpenTelemetry exporter — DEFAULT OFF → null tracer, no export attempts.
    'otel_enabled' => env('LANGFUSE_OTEL_ENABLED', false),
    'otel_endpoint' => env('LANGFUSE_OTEL_ENDPOINT', env('LANGFUSE_HOST', 'https://cloud.langfuse.com').'/api/public/otel'),
    'otel_protocol' => env('LANGFUSE_OTEL_PROTOCOL', env('OTEL_EXPORTER_OTLP_PROTOCOL', 'http/json')),

    'otel_use_simple_processor' => env('LANGFUSE_OTEL_USE_SIMPLE_PROCESSOR'),
    'otel_max_queue_size' => (int) env('LANGFUSE_OTEL_MAX_QUEUE_SIZE', 2048),
    'otel_schedule_delay' => (int) env('LANGFUSE_OTEL_SCHEDULE_DELAY', 1000),
    'otel_export_timeout' => (int) env('LANGFUSE_OTEL_EXPORT_TIMEOUT', 30000),
    'otel_max_export_batch_size' => (int) env('LANGFUSE_OTEL_MAX_EXPORT_BATCH_SIZE', 512),
];
