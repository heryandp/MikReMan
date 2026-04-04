<?php

function isCctvUsageStorageAvailable(): bool
{
    return extension_loaded('pdo_sqlite');
}

function getCctvUsageRuntimeDirectory(): string
{
    $directory = dirname(__DIR__) . '/runtime';

    if (!is_dir($directory) && !mkdir($directory, 0775, true) && !is_dir($directory)) {
        throw new RuntimeException('Failed to create runtime directory: ' . $directory);
    }

    return $directory;
}

function getLegacyCctvUsageJsonPath(): string
{
    return getCctvUsageRuntimeDirectory() . '/cctv-usage.json';
}

function getCctvUsageDatabasePath(): string
{
    return getCctvUsageRuntimeDirectory() . '/cctv-usage.sqlite';
}

function getCctvUsagePdo(): ?PDO
{
    static $pdo = null;

    if ($pdo instanceof PDO) {
        return $pdo;
    }

    if (!isCctvUsageStorageAvailable()) {
        return null;
    }

    $pdo = new PDO('sqlite:' . getCctvUsageDatabasePath(), null, null, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);

    $pdo->exec('PRAGMA journal_mode = WAL');
    $pdo->exec('PRAGMA synchronous = NORMAL');
    $pdo->exec('PRAGMA busy_timeout = 5000');

    ensureCctvUsageSchema($pdo);
    backfillLegacyCctvUsageJson($pdo);

    return $pdo;
}

function ensureCctvUsageSchema(PDO $pdo): void
{
    $pdo->exec(
        'CREATE TABLE IF NOT EXISTS cctv_stream_state (
            stream_name TEXT PRIMARY KEY,
            last_rx_bytes INTEGER NOT NULL DEFAULT 0,
            last_tx_bytes INTEGER NOT NULL DEFAULT 0,
            kind TEXT NOT NULL DEFAULT \'source\',
            source_name TEXT NOT NULL DEFAULT \'\',
            last_seen TEXT NOT NULL DEFAULT \'\'
        )'
    );

    $pdo->exec(
        'CREATE TABLE IF NOT EXISTS cctv_stream_daily_usage (
            day_key TEXT NOT NULL,
            stream_name TEXT NOT NULL,
            rx_bytes INTEGER NOT NULL DEFAULT 0,
            tx_bytes INTEGER NOT NULL DEFAULT 0,
            samples INTEGER NOT NULL DEFAULT 0,
            kind TEXT NOT NULL DEFAULT \'source\',
            source_name TEXT NOT NULL DEFAULT \'\',
            last_seen TEXT NOT NULL DEFAULT \'\',
            PRIMARY KEY (day_key, stream_name)
        )'
    );

    $pdo->exec(
        'CREATE TABLE IF NOT EXISTS cctv_usage_meta (
            meta_key TEXT PRIMARY KEY,
            meta_value TEXT NOT NULL DEFAULT \'\'
        )'
    );

    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_cctv_daily_stream_name ON cctv_stream_daily_usage(stream_name)');
    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_cctv_daily_day_key ON cctv_stream_daily_usage(day_key)');
}

function backfillLegacyCctvUsageJson(PDO $pdo): void
{
    static $backfilled = false;

    if ($backfilled) {
        return;
    }

    $backfilled = true;

    $legacyPath = getLegacyCctvUsageJsonPath();
    if (!is_file($legacyPath)) {
        return;
    }

    $existingRows = (int)$pdo->query('SELECT COUNT(*) FROM cctv_stream_daily_usage')->fetchColumn();
    if ($existingRows > 0) {
        return;
    }

    $contents = @file_get_contents($legacyPath);
    if ($contents === false || trim($contents) === '') {
        return;
    }

    $decoded = json_decode($contents, true);
    if (!is_array($decoded)) {
        return;
    }

    $streams = is_array($decoded['streams'] ?? null) ? $decoded['streams'] : [];
    $days = is_array($decoded['days'] ?? null) ? $decoded['days'] : [];
    $lastCollectedAt = trim((string)($decoded['last_collected_at'] ?? ''));

    $stateStatement = $pdo->prepare(
        'INSERT INTO cctv_stream_state (
            stream_name, last_rx_bytes, last_tx_bytes, kind, source_name, last_seen
        ) VALUES (
            :stream_name, :last_rx_bytes, :last_tx_bytes, :kind, :source_name, :last_seen
        )
        ON CONFLICT(stream_name) DO UPDATE SET
            last_rx_bytes = excluded.last_rx_bytes,
            last_tx_bytes = excluded.last_tx_bytes,
            kind = excluded.kind,
            source_name = excluded.source_name,
            last_seen = excluded.last_seen'
    );

    $dailyStatement = $pdo->prepare(
        'INSERT INTO cctv_stream_daily_usage (
            day_key, stream_name, rx_bytes, tx_bytes, samples, kind, source_name, last_seen
        ) VALUES (
            :day_key, :stream_name, :rx_bytes, :tx_bytes, :samples, :kind, :source_name, :last_seen
        )
        ON CONFLICT(day_key, stream_name) DO UPDATE SET
            rx_bytes = excluded.rx_bytes,
            tx_bytes = excluded.tx_bytes,
            samples = excluded.samples,
            kind = excluded.kind,
            source_name = excluded.source_name,
            last_seen = excluded.last_seen'
    );

    $metaStatement = $pdo->prepare(
        'INSERT INTO cctv_usage_meta (meta_key, meta_value)
         VALUES (:meta_key, :meta_value)
         ON CONFLICT(meta_key) DO UPDATE SET meta_value = excluded.meta_value'
    );

    $pdo->beginTransaction();

    try {
        foreach ($streams as $streamName => $state) {
            if (!is_array($state)) {
                continue;
            }

            $streamName = trim((string)$streamName);
            if ($streamName === '') {
                continue;
            }

            $stateStatement->execute([
                ':stream_name' => $streamName,
                ':last_rx_bytes' => (int)($state['last_rx_bytes'] ?? 0),
                ':last_tx_bytes' => (int)($state['last_tx_bytes'] ?? 0),
                ':kind' => trim((string)($state['kind'] ?? 'source')),
                ':source_name' => trim((string)($state['source_name'] ?? '')),
                ':last_seen' => trim((string)($state['last_seen'] ?? '')),
            ]);
        }

        foreach ($days as $dayKey => $entries) {
            if (!is_array($entries)) {
                continue;
            }

            $dayKey = trim((string)$dayKey);
            if ($dayKey === '') {
                continue;
            }

            foreach ($entries as $streamName => $entry) {
                if (!is_array($entry)) {
                    continue;
                }

                $streamName = trim((string)$streamName);
                if ($streamName === '') {
                    continue;
                }

                $dailyStatement->execute([
                    ':day_key' => $dayKey,
                    ':stream_name' => $streamName,
                    ':rx_bytes' => (int)($entry['rx_bytes'] ?? 0),
                    ':tx_bytes' => (int)($entry['tx_bytes'] ?? 0),
                    ':samples' => (int)($entry['samples'] ?? 0),
                    ':kind' => trim((string)($entry['kind'] ?? 'source')),
                    ':source_name' => trim((string)($entry['source_name'] ?? '')),
                    ':last_seen' => trim((string)($entry['last_seen'] ?? '')),
                ]);
            }
        }

        if ($lastCollectedAt !== '') {
            $metaStatement->execute([
                ':meta_key' => 'last_collected_at',
                ':meta_value' => $lastCollectedAt,
            ]);
        }

        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
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

function setCctvUsageMeta(PDO $pdo, string $key, string $value): void
{
    $statement = $pdo->prepare(
        'INSERT INTO cctv_usage_meta (meta_key, meta_value)
         VALUES (:meta_key, :meta_value)
         ON CONFLICT(meta_key) DO UPDATE SET meta_value = excluded.meta_value'
    );
    $statement->execute([
        ':meta_key' => $key,
        ':meta_value' => $value,
    ]);
}

function getCctvUsageStateRows(PDO $pdo): array
{
    $rows = $pdo->query('SELECT stream_name, last_rx_bytes, last_tx_bytes, kind, source_name, last_seen FROM cctv_stream_state')->fetchAll() ?: [];
    $indexed = [];

    foreach ($rows as $row) {
        $name = trim((string)($row['stream_name'] ?? ''));
        if ($name === '') {
            continue;
        }

        $indexed[$name] = $row;
    }

    return $indexed;
}

function getCctvUsageDailyRows(PDO $pdo, string $fromDayKey): array
{
    $statement = $pdo->prepare(
        'SELECT day_key, stream_name, rx_bytes, tx_bytes, samples, kind, source_name, last_seen
         FROM cctv_stream_daily_usage
         WHERE day_key >= :from_day_key'
    );
    $statement->execute([
        ':from_day_key' => $fromDayKey,
    ]);

    $rows = $statement->fetchAll() ?: [];
    $indexed = [];

    foreach ($rows as $row) {
        $streamName = trim((string)($row['stream_name'] ?? ''));
        $dayKey = trim((string)($row['day_key'] ?? ''));
        if ($streamName === '' || $dayKey === '') {
            continue;
        }

        if (!isset($indexed[$streamName])) {
            $indexed[$streamName] = [];
        }

        $indexed[$streamName][$dayKey] = $row;
    }

    return $indexed;
}

function buildCctvUsageFallback(array $streams, string $warning = ''): array
{
    foreach ($streams as &$stream) {
        if (!isset($stream['usage']) || !is_array($stream['usage'])) {
            $stream['usage'] = [
                'current' => [
                    'rx_bytes' => (int)($stream['rx_bytes_total'] ?? 0),
                    'tx_bytes' => (int)($stream['tx_bytes_total'] ?? 0),
                ],
                'delta' => [
                    'rx_bytes' => 0,
                    'tx_bytes' => 0,
                ],
                'today' => [
                    'rx_bytes' => 0,
                    'tx_bytes' => 0,
                ],
                'month_30d' => [
                    'rx_bytes' => 0,
                    'tx_bytes' => 0,
                ],
                'kind' => 'source',
                'source_name' => '',
                'last_seen' => '',
            ];
        }
    }
    unset($stream);

    return [
        'streams' => $streams,
        'summary' => [
            'today' => ['rx_bytes' => 0, 'tx_bytes' => 0],
            'month_30d' => ['rx_bytes' => 0, 'tx_bytes' => 0],
            'last_collected_at' => '',
            'warning' => $warning,
        ],
    ];
}

function updateCctvUsageMetrics(array $streams, array $youtubeRestreams): array
{
    $pdo = getCctvUsagePdo();
    if (!$pdo) {
        return buildCctvUsageFallback($streams, 'SQLite support is unavailable');
    }

    $now = new DateTimeImmutable('now');
    $todayKey = $now->format('Y-m-d');
    $monthStartKey = $now->sub(new DateInterval('P30D'))->format('Y-m-d');
    $cutoffKey = $now->sub(new DateInterval('P35D'))->format('Y-m-d');
    $nowIso = $now->format(DATE_ATOM);

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

    try {
        $existingState = getCctvUsageStateRows($pdo);

        $deleteStatement = $pdo->prepare('DELETE FROM cctv_stream_daily_usage WHERE day_key < :cutoff_key');
        $dailyStatement = $pdo->prepare(
            'INSERT INTO cctv_stream_daily_usage (
                day_key, stream_name, rx_bytes, tx_bytes, samples, kind, source_name, last_seen
            ) VALUES (
                :day_key, :stream_name, :rx_bytes, :tx_bytes, :samples, :kind, :source_name, :last_seen
            )
            ON CONFLICT(day_key, stream_name) DO UPDATE SET
                rx_bytes = cctv_stream_daily_usage.rx_bytes + excluded.rx_bytes,
                tx_bytes = cctv_stream_daily_usage.tx_bytes + excluded.tx_bytes,
                samples = cctv_stream_daily_usage.samples + excluded.samples,
                kind = excluded.kind,
                source_name = excluded.source_name,
                last_seen = excluded.last_seen'
        );
        $stateStatement = $pdo->prepare(
            'INSERT INTO cctv_stream_state (
                stream_name, last_rx_bytes, last_tx_bytes, kind, source_name, last_seen
            ) VALUES (
                :stream_name, :last_rx_bytes, :last_tx_bytes, :kind, :source_name, :last_seen
            )
            ON CONFLICT(stream_name) DO UPDATE SET
                last_rx_bytes = excluded.last_rx_bytes,
                last_tx_bytes = excluded.last_tx_bytes,
                kind = excluded.kind,
                source_name = excluded.source_name,
                last_seen = excluded.last_seen'
        );

        $pdo->beginTransaction();
        $deleteStatement->execute([
            ':cutoff_key' => $cutoffKey,
        ]);

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

            $previous = is_array($existingState[$name] ?? null) ? $existingState[$name] : [];
            $previousRx = is_numeric($previous['last_rx_bytes'] ?? null) ? (int)$previous['last_rx_bytes'] : 0;
            $previousTx = is_numeric($previous['last_tx_bytes'] ?? null) ? (int)$previous['last_tx_bytes'] : 0;

            $deltaRx = $rxBytes >= $previousRx ? ($rxBytes - $previousRx) : $rxBytes;
            $deltaTx = $txBytes >= $previousTx ? ($txBytes - $previousTx) : $txBytes;

            $dailyStatement->execute([
                ':day_key' => $todayKey,
                ':stream_name' => $name,
                ':rx_bytes' => max(0, $deltaRx),
                ':tx_bytes' => max(0, $deltaTx),
                ':samples' => 1,
                ':kind' => $kind,
                ':source_name' => $sourceName,
                ':last_seen' => $nowIso,
            ]);

            $stateStatement->execute([
                ':stream_name' => $name,
                ':last_rx_bytes' => $rxBytes,
                ':last_tx_bytes' => $txBytes,
                ':kind' => $kind,
                ':source_name' => $sourceName,
                ':last_seen' => $nowIso,
            ]);

            $stream['usage'] = [
                'current' => [
                    'rx_bytes' => $rxBytes,
                    'tx_bytes' => $txBytes,
                ],
                'delta' => [
                    'rx_bytes' => max(0, $deltaRx),
                    'tx_bytes' => max(0, $deltaTx),
                ],
                'today' => [
                    'rx_bytes' => 0,
                    'tx_bytes' => 0,
                ],
                'month_30d' => [
                    'rx_bytes' => 0,
                    'tx_bytes' => 0,
                ],
                'kind' => $kind,
                'source_name' => $sourceName,
                'last_seen' => $nowIso,
            ];
        }
        unset($stream);

        setCctvUsageMeta($pdo, 'last_collected_at', $nowIso);
        $pdo->commit();

        $dailyRows = getCctvUsageDailyRows($pdo, $monthStartKey);
        $totals = [
            'today' => ['rx_bytes' => 0, 'tx_bytes' => 0],
            'month_30d' => ['rx_bytes' => 0, 'tx_bytes' => 0],
        ];

        foreach ($streams as &$stream) {
            $name = trim((string)($stream['name'] ?? ''));
            $todayTotals = ['rx_bytes' => 0, 'tx_bytes' => 0];
            $monthTotals = ['rx_bytes' => 0, 'tx_bytes' => 0];

            foreach ($dailyRows[$name] ?? [] as $dayKey => $row) {
                $entryRx = (int)($row['rx_bytes'] ?? 0);
                $entryTx = (int)($row['tx_bytes'] ?? 0);

                if ($dayKey === $todayKey) {
                    $todayTotals['rx_bytes'] += $entryRx;
                    $todayTotals['tx_bytes'] += $entryTx;
                }

                if ($dayKey >= $monthStartKey) {
                    $monthTotals['rx_bytes'] += $entryRx;
                    $monthTotals['tx_bytes'] += $entryTx;
                }
            }

            $stream['usage']['today'] = $todayTotals;
            $stream['usage']['month_30d'] = $monthTotals;

            $totals['today']['rx_bytes'] += $todayTotals['rx_bytes'];
            $totals['today']['tx_bytes'] += $todayTotals['tx_bytes'];
            $totals['month_30d']['rx_bytes'] += $monthTotals['rx_bytes'];
            $totals['month_30d']['tx_bytes'] += $monthTotals['tx_bytes'];
        }
        unset($stream);

        return [
            'streams' => $streams,
            'summary' => [
                'today' => $totals['today'],
                'month_30d' => $totals['month_30d'],
                'last_collected_at' => $nowIso,
            ],
        ];
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }

        return buildCctvUsageFallback($streams, 'Failed to update CCTV usage state: ' . $e->getMessage());
    }
}
