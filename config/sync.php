<?php

return [
    'server_url' => env('SYNC_SERVER_URL', 'https://henri.motolite.com'),
    'timeout' => env('SYNC_TIMEOUT', 10),

    // Pull requests download whole pages of data (customers, ~42K barangays),
    // so they get a longer timeout than pushes and pings.
    'pull_timeout' => (int) env('SYNC_PULL_TIMEOUT', 60),

    // Auto-sync only fires once upload throughput is at least this fast.
    'min_speed_mbps' => (float) env('SYNC_MIN_SPEED_MBPS', 10),

    // Size of the dummy payload uploaded to measure real throughput.
    'speedtest_bytes' => (int) env('SYNC_SPEEDTEST_BYTES', 300_000),
];
