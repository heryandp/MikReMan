<?php

function getCctvUsageStoragePath(): string
{
    $directory = dirname(__DIR__) . '/runtime';

    if (!is_dir($directory) && !mkdir($directory, 0775, true) && !is_dir($directory)) {
        throw new RuntimeException('Failed to create runtime directory: ' . $directory);
    }

    return $directory . '/cctv-usage.json';
}

function loadCctvUsageState(): array
{
    $path = getCctvUsageStoragePath();
    if (!is_file($path)) {
        return [
            'version' => 1,
            'last_collected_at' => '',
            'streams' => [],
            'days' => [],
        ];
    }

    $contents = file_get_contents($path);
    if ($contents === false || trim($contents) === '') {
        return [
            'version' => 1,
            'last_collected_at' => '',
            'streams' => [],
            'days' => [],
        ];
    }

    $decoded = json_decode($contents, true);
    if (!is_array($decoded)) {
        return [
            'version' => 1,
            'last_collected_at' => '',
            'streams' => [],
            'days' => [],
        ];
    }

    $decoded['streams'] = is_array($decoded['streams'] ?? null) ? $decoded['streams'] : [];
    $decoded['days'] = is_array($decoded['days'] ?? null) ? $decoded['days'] : [];
    $decoded['version'] = 1;

    return $decoded;
}

function saveCctvUsageState(array $state): void
{
    $path = getCctvUsageStoragePath();
    $payload = json_encode($state, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);

    if ($payload === false || file_put_contents($path, $payload) === false) {
        throw new RuntimeException('Failed to write CCTV usage state');
    }
}

function normalizeCctvUsageKind(array $stream, array $youtubeAliases): string
{
    $name = trim((string)($stream['name'] ?? ''));
    return in_array($name, $youtubeAliases, true) ? 'youtube' : 'source';
}

function sumCctvUsageBytes(array $items, string $field): int
{
    $total = 0;

    foreach ($items as $item) {
        if (!is_array($item)) {
            continue;
        }

        $value = $item[$field] ?? 0;
        if (is_numeric($value)) {
            $total += (int)$value;
        }
    }

    return $total;
}

function updateCctvUsageMetrics(array $streams, array $youtubeRestreams): array
{
    $state = loadCctvUsageState();
    $now = new DateTimeImmutable('now');
    $todayKey = $now->format('Y-m-d');
    $cutoffKey = $now->sub(new DateInterval('P35D'))->format('Y-m-d');

    foreach (array_keys($state['days']) as $dayKey) {
        if ($dayKey < $cutoffKey) {
            unset($state['days'][$dayKey]);
        }
    }

    if (!isset($state['days'][$todayKey]) || !is_array($state['days'][$todayKey])) {
        $state['days'][$todayKey] = [];
    }

    $youtubeAliases = array_values(array_filter(array_map(static function ($restream) {
        return trim((string)($restream['alias'] ?? ''));
    }, $youtubeRestreams)));

    $youtubeSources = [];
    foreach ($youtubeRestreams as $restream) {
        $alias = trim((string)($restream['alias'] ?? ''));
        if ($alias === '') {
            continue;
        }

        $youtubeSources[$alias] = trim((string)($restream['source_name'] ?? ''));
    }

    foreach ($streams as &$stream) {
        $name = trim((string)($stream['name'] ?? ''));
        if ($name === '') {
            continue;
        }

        $summary = is_array($stream['summary'] ?? null) ? $stream['summary'] : [];
        $producers = is_array($summary['producers'] ?? null) ? $summary['producers'] : [];
        $consumers = is_array($summary['consumers'] ?? null) ? $summary['consumers'] : [];
        $rxBytes = sumCctvUsageBytes($producers, 'bytes_recv');
        $txBytes = sumCctvUsageBytes($consumers, 'bytes_send');

        $kind = normalizeCctvUsageKind($stream, $youtubeAliases);
        $sourceName = $kind === 'youtube' ? ($youtubeSources[$name] ?? '') : '';

        $previous = is_array($state['streams'][$name] ?? null) ? $state['streams'][$name] : [];
        $previousRx = is_numeric($previous['last_rx_bytes'] ?? null) ? (int)$previous['last_rx_bytes'] : 0;
        $previousTx = is_numeric($previous['last_tx_bytes'] ?? null) ? (int)$previous['last_tx_bytes'] : 0;

        $deltaRx = $rxBytes >= $previousRx ? ($rxBytes - $previousRx) : $rxBytes;
        $deltaTx = $txBytes >= $previousTx ? ($txBytes - $previousTx) : $txBytes;

        if (!isset($state['days'][$todayKey][$name]) || !is_array($state['days'][$todayKey][$name])) {
            $state['days'][$todayKey][$name] = [
                'rx_bytes' => 0,
                'tx_bytes' => 0,
                'samples' => 0,
                'kind' => $kind,
                'source_name' => $sourceName,
                'last_seen' => '',
            ];
        }

        $state['days'][$todayKey][$name]['rx_bytes'] = (int)($state['days'][$todayKey][$name]['rx_bytes'] ?? 0) + max(0, $deltaRx);
        $state['days'][$todayKey][$name]['tx_bytes'] = (int)($state['days'][$todayKey][$name]['tx_bytes'] ?? 0) + max(0, $deltaTx);
        $state['days'][$todayKey][$name]['samples'] = (int)($state['days'][$todayKey][$name]['samples'] ?? 0) + 1;
        $state['days'][$todayKey][$name]['kind'] = $kind;
        $state['days'][$todayKey][$name]['source_name'] = $sourceName;
        $state['days'][$todayKey][$name]['last_seen'] = $now->format(DATE_ATOM);

        $state['streams'][$name] = [
            'last_rx_bytes' => $rxBytes,
            'last_tx_bytes' => $txBytes,
            'kind' => $kind,
            'source_name' => $sourceName,
            'last_seen' => $now->format(DATE_ATOM),
        ];

        $todayTotals = [
            'rx_bytes' => 0,
            'tx_bytes' => 0,
        ];
        $monthTotals = [
            'rx_bytes' => 0,
            'tx_bytes' => 0,
        ];

        foreach ($state['days'] as $dayKey => $dayStreams) {
            if (!is_array($dayStreams) || !isset($dayStreams[$name]) || !is_array($dayStreams[$name])) {
                continue;
            }

            $entry = $dayStreams[$name];
            $entryRx = (int)($entry['rx_bytes'] ?? 0);
            $entryTx = (int)($entry['tx_bytes'] ?? 0);

            if ($dayKey === $todayKey) {
                $todayTotals['rx_bytes'] += $entryRx;
                $todayTotals['tx_bytes'] += $entryTx;
            }

            if ($dayKey >= $now->sub(new DateInterval('P30D'))->format('Y-m-d')) {
                $monthTotals['rx_bytes'] += $entryRx;
                $monthTotals['tx_bytes'] += $entryTx;
            }
        }

        $stream['usage'] = [
            'current' => [
                'rx_bytes' => $rxBytes,
                'tx_bytes' => $txBytes,
            ],
            'delta' => [
                'rx_bytes' => max(0, $deltaRx),
                'tx_bytes' => max(0, $deltaTx),
            ],
            'today' => $todayTotals,
            'month_30d' => $monthTotals,
            'kind' => $kind,
            'source_name' => $sourceName,
            'last_seen' => $state['streams'][$name]['last_seen'],
        ];
    }
    unset($stream);

    $state['last_collected_at'] = $now->format(DATE_ATOM);
    saveCctvUsageState($state);

    $totals = [
        'today' => ['rx_bytes' => 0, 'tx_bytes' => 0],
        'month_30d' => ['rx_bytes' => 0, 'tx_bytes' => 0],
    ];

    foreach ($streams as $stream) {
        $usage = is_array($stream['usage'] ?? null) ? $stream['usage'] : [];
        $totals['today']['rx_bytes'] += (int)($usage['today']['rx_bytes'] ?? 0);
        $totals['today']['tx_bytes'] += (int)($usage['today']['tx_bytes'] ?? 0);
        $totals['month_30d']['rx_bytes'] += (int)($usage['month_30d']['rx_bytes'] ?? 0);
        $totals['month_30d']['tx_bytes'] += (int)($usage['month_30d']['tx_bytes'] ?? 0);
    }

    return [
        'streams' => $streams,
        'summary' => [
            'today' => $totals['today'],
            'month_30d' => $totals['month_30d'],
            'last_collected_at' => $state['last_collected_at'],
        ],
    ];
}

