<?php

return [
    /*
     * Requests a minute for each API key, by the workspace's plan. API
     * calls don't use the workspace's AI tool calls.
     */
    'rate_limits' => [
        'starter' => (int) env('API_RATE_STARTER', 60),
        'pro' => (int) env('API_RATE_PRO', 300),
        'business' => (int) env('API_RATE_BUSINESS', 1000),
    ],
];
