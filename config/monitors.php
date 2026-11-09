<?php

return [
    // Let monitors reach private and loopback addresses. Off in production:
    // checks run from the panel, inside its own network.
    'allow_private' => (bool) env('MONITORS_ALLOW_PRIVATE', false),

    // Read HTTPS certificates' expiry dates (every few hours per monitor).
    'check_certificates' => (bool) env('MONITORS_CHECK_CERTIFICATES', true),
];
