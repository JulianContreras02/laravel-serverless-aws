<?php

return [

    /*
    | Bearer token required by the management API (/api/*). When empty, every
    | management request is rejected: the API fails closed, never open.
    */
    'api_token' => env('API_TOKEN'),

    /*
    | Base URL used to build short links. In AWS this is the API Gateway URL
    | (or a custom domain); locally it is the Docker app.
    */
    'short_url_base' => env('SHORT_URL_BASE') ?: env('APP_URL', 'http://localhost:8000'),

    'code_length' => 7,

    /*
    | Single-segment paths that must never become short codes.
    */
    'reserved_codes' => ['api', 'health', 'up', 'admin', 'static', 'assets', 'login', 'docs'],

    /*
    | Raw clicks are pruned after this many days. Daily aggregates are kept.
    */
    'click_retention_days' => (int) env('CLICK_RETENTION_DAYS', 90),

    'stats' => [
        'default_days' => 30,
        'max_days' => 90,
        'top_referrers' => 10,
    ],

    /*
    | Custom metrics are written to stderr in CloudWatch Embedded Metric Format:
    | Lambda ships them to CloudWatch Logs, which extracts the metrics. No SDK
    | call and no network egress from the VPC.
    */
    'metrics' => [
        'enabled' => (bool) env('METRICS_ENABLED', true),
        'namespace' => env('METRICS_NAMESPACE', 'Shortlinks'),
        'environment' => env('APP_ENV', 'production'),
    ],

];
