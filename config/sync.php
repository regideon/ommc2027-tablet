<?php

return [
    'server_url' => env('SYNC_SERVER_URL', ''),
    'timeout' => env('SYNC_TIMEOUT', 10),

    // Auto-sync only fires once upload throughput is at least this fast.
    'min_speed_mbps' => (float) env('SYNC_MIN_SPEED_MBPS', 10),

    // Size of the dummy payload uploaded to measure real throughput.
    'speedtest_bytes' => (int) env('SYNC_SPEEDTEST_BYTES', 300_000),
];
