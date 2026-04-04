<?php

require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/go2rtc.php';

try {
    $overview = go2rtcGetOverview(getConfig('mikrotik') ?: []);
    $summary = $overview['usage_summary'] ?? [];

    echo json_encode([
        'success' => true,
        'collected_at' => $summary['last_collected_at'] ?? '',
        'today_total_bytes' => (int)($summary['today']['rx_bytes'] ?? 0) + (int)($summary['today']['tx_bytes'] ?? 0),
        'month_30d_total_bytes' => (int)($summary['month_30d']['rx_bytes'] ?? 0) + (int)($summary['month_30d']['tx_bytes'] ?? 0),
        'stream_count' => (int)($overview['stream_count'] ?? 0),
    ], JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT) . PHP_EOL;
    exit(0);
} catch (Throwable $e) {
    fwrite(STDERR, $e->getMessage() . PHP_EOL);
    exit(1);
}

