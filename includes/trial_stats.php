<?php

function isTrialStatsStorageAvailable(): bool
{
    return extension_loaded('pdo_sqlite');
}

function getTrialStatsDatabasePath(): string
{
    $directory = dirname(__DIR__) . '/runtime';

    if (!is_dir($directory) && !mkdir($directory, 0775, true) && !is_dir($directory)) {
        throw new RuntimeException('Failed to create runtime directory: ' . $directory);
    }

    return $directory . '/trial-stats.sqlite';
}

function getTrialOrdersStoragePath(): string
{
    return dirname(__DIR__) . '/runtime/trials';
}

function getTrialStatsPdo(): ?PDO
{
    static $pdo = null;

    if ($pdo instanceof PDO) {
        return $pdo;
    }

    if (!isTrialStatsStorageAvailable()) {
        return null;
    }

    $pdo = new PDO('sqlite:' . getTrialStatsDatabasePath(), null, null, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);

    $pdo->exec('PRAGMA journal_mode = WAL');
    $pdo->exec('PRAGMA synchronous = NORMAL');
    $pdo->exec('PRAGMA busy_timeout = 5000');
    ensureTrialStatsSchema($pdo);
    backfillTrialStatsFromFilesystem($pdo);

    return $pdo;
}

function ensureTrialStatsSchema(PDO $pdo): void
{
    $pdo->exec(
        'CREATE TABLE IF NOT EXISTS trial_users (
            request_code TEXT PRIMARY KEY,
            username TEXT NOT NULL,
            email TEXT NOT NULL,
            full_name TEXT NOT NULL,
            client_ip TEXT NOT NULL,
            service TEXT NOT NULL,
            service_host TEXT NOT NULL DEFAULT \'\',
            public_host TEXT NOT NULL DEFAULT \'\',
            remote_address TEXT NOT NULL DEFAULT \'\',
            expires_at TEXT NOT NULL,
            created_at TEXT NOT NULL,
            notes TEXT NOT NULL DEFAULT \'\',
            status TEXT NOT NULL DEFAULT \'active\',
            record_path TEXT NOT NULL DEFAULT \'\',
            fixed_ports_json TEXT NOT NULL DEFAULT \'[]\',
            cleanup_attempts INTEGER NOT NULL DEFAULT 0,
            last_cleanup_error TEXT NOT NULL DEFAULT \'\',
            last_cleanup_attempt_at TEXT NOT NULL DEFAULT \'\',
            cleaned_at TEXT NOT NULL DEFAULT \'\',
            nat_deleted INTEGER NOT NULL DEFAULT 0,
            hostfwd_removed INTEGER NOT NULL DEFAULT 0,
            netwatch_deleted INTEGER NOT NULL DEFAULT 0,
            secret_deleted INTEGER NOT NULL DEFAULT 0,
            updated_at TEXT NOT NULL
        )'
    );

    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_trial_users_email ON trial_users(email)');
    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_trial_users_client_ip ON trial_users(client_ip)');
    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_trial_users_status ON trial_users(status)');
    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_trial_users_created_at ON trial_users(created_at)');
    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_trial_users_expires_at ON trial_users(expires_at)');
}

function backfillTrialStatsFromFilesystem(PDO $pdo): void
{
    static $backfilled = false;

    if ($backfilled) {
        return;
    }

    $backfilled = true;

    $baseDirectory = getTrialOrdersStoragePath();
    if (!is_dir($baseDirectory)) {
        return;
    }

    $directories = scandir($baseDirectory);
    if (!is_array($directories)) {
        return;
    }

    foreach ($directories as $directory) {
        if ($directory === '.' || $directory === '..' || $directory === '_index' || $directory === '_logs') {
            continue;
        }

        $fullDirectory = $baseDirectory . '/' . $directory;
        if (!is_dir($fullDirectory)) {
            continue;
        }

        $files = glob($fullDirectory . '/*.json') ?: [];
        foreach ($files as $file) {
            $contents = file_get_contents($file);
            if ($contents === false) {
                continue;
            }

            $record = json_decode($contents, true);
            if (!is_array($record)) {
                continue;
            }

            $record['record_path'] = $record['record_path'] ?? $file;
            syncTrialStatsRecord($record);
        }
    }
}

function syncTrialStatsRecord(array $record): void
{
    $pdo = getTrialStatsPdo();
    if (!$pdo) {
        return;
    }

    $request_code = trim((string)($record['request_code'] ?? ''));
    if ($request_code === '') {
        return;
    }

    $now = (new DateTimeImmutable())->format(DATE_ATOM);
    $statement = $pdo->prepare(
        'INSERT INTO trial_users (
            request_code, username, email, full_name, client_ip, service, service_host, public_host,
            remote_address, expires_at, created_at, notes, status, record_path, fixed_ports_json, updated_at
        ) VALUES (
            :request_code, :username, :email, :full_name, :client_ip, :service, :service_host, :public_host,
            :remote_address, :expires_at, :created_at, :notes, :status, :record_path, :fixed_ports_json, :updated_at
        )
        ON CONFLICT(request_code) DO UPDATE SET
            username = excluded.username,
            email = excluded.email,
            full_name = excluded.full_name,
            client_ip = excluded.client_ip,
            service = excluded.service,
            service_host = excluded.service_host,
            public_host = excluded.public_host,
            remote_address = excluded.remote_address,
            expires_at = excluded.expires_at,
            created_at = excluded.created_at,
            notes = excluded.notes,
            status = excluded.status,
            record_path = excluded.record_path,
            fixed_ports_json = excluded.fixed_ports_json,
            updated_at = excluded.updated_at'
    );

    $statement->execute([
        ':request_code' => $request_code,
        ':username' => trim((string)($record['username'] ?? '')),
        ':email' => strtolower(trim((string)($record['email'] ?? ''))),
        ':full_name' => trim((string)($record['full_name'] ?? '')),
        ':client_ip' => trim((string)($record['client_ip'] ?? '')),
        ':service' => strtoupper(trim((string)($record['service'] ?? ''))),
        ':service_host' => trim((string)($record['service_host'] ?? '')),
        ':public_host' => trim((string)($record['host'] ?? '')),
        ':remote_address' => trim((string)($record['remote_address'] ?? '')),
        ':expires_at' => trim((string)($record['expires_at'] ?? '')),
        ':created_at' => trim((string)($record['created_at'] ?? $now)),
        ':notes' => trim((string)($record['notes'] ?? '')),
        ':status' => trim((string)($record['status'] ?? 'active')),
        ':record_path' => trim((string)($record['record_path'] ?? '')),
        ':fixed_ports_json' => json_encode($record['fixed_ports'] ?? [], JSON_UNESCAPED_SLASHES) ?: '[]',
        ':updated_at' => $now,
    ]);
}

function markTrialStatsCleanupFailure(array $record, DateTimeImmutable $attemptedAt, string $errorMessage, int $attempts): void
{
    $pdo = getTrialStatsPdo();
    if (!$pdo) {
        return;
    }

    $request_code = trim((string)($record['request_code'] ?? ''));
    if ($request_code === '') {
        return;
    }

    $iso = $attemptedAt->format(DATE_ATOM);
    $statement = $pdo->prepare(
        'UPDATE trial_users
         SET status = :status,
             cleanup_attempts = :cleanup_attempts,
             last_cleanup_error = :last_cleanup_error,
             last_cleanup_attempt_at = :last_cleanup_attempt_at,
             updated_at = :updated_at
         WHERE request_code = :request_code'
    );

    $statement->execute([
        ':status' => 'cleanup_failed',
        ':cleanup_attempts' => $attempts,
        ':last_cleanup_error' => $errorMessage,
        ':last_cleanup_attempt_at' => $iso,
        ':updated_at' => $iso,
        ':request_code' => $request_code,
    ]);
}

function markTrialStatsCleaned(array $record, array $cleanupResult, DateTimeImmutable $cleanedAt): void
{
    $pdo = getTrialStatsPdo();
    if (!$pdo) {
        return;
    }

    $request_code = trim((string)($record['request_code'] ?? ''));
    if ($request_code === '') {
        return;
    }

    $iso = $cleanedAt->format(DATE_ATOM);
    $statement = $pdo->prepare(
        'UPDATE trial_users
         SET status = :status,
             cleaned_at = :cleaned_at,
             nat_deleted = :nat_deleted,
             hostfwd_removed = :hostfwd_removed,
             netwatch_deleted = :netwatch_deleted,
             secret_deleted = :secret_deleted,
             last_cleanup_error = \'\',
             last_cleanup_attempt_at = :last_cleanup_attempt_at,
             updated_at = :updated_at
         WHERE request_code = :request_code'
    );

    $statement->execute([
        ':status' => 'cleaned',
        ':cleaned_at' => $iso,
        ':nat_deleted' => (int)($cleanupResult['nat_deleted'] ?? 0),
        ':hostfwd_removed' => (int)($cleanupResult['hostfwd_removed'] ?? 0),
        ':netwatch_deleted' => !empty($cleanupResult['netwatch_deleted']) ? 1 : 0,
        ':secret_deleted' => !empty($cleanupResult['secret_deleted']) ? 1 : 0,
        ':last_cleanup_attempt_at' => $iso,
        ':updated_at' => $iso,
        ':request_code' => $request_code,
    ]);
}

function getTrialStatsSummary(): array
{
    $pdo = getTrialStatsPdo();
    if (!$pdo) {
        return [
            'available' => false,
            'total' => 0,
            'today' => 0,
            'week' => 0,
            'month' => 0,
            'active' => 0,
            'cleaned' => 0,
            'cleanup_failed' => 0,
        ];
    }

    $timezone = new DateTimeZone(date_default_timezone_get());
    $today_start = (new DateTimeImmutable('today', $timezone))->setTime(0, 0, 0);
    $week_start = $today_start->modify('monday this week');
    $month_start = $today_start->modify('first day of this month');

    $row = $pdo->query(
        'SELECT
            COUNT(*) AS total,
            SUM(CASE WHEN status = \'active\' THEN 1 ELSE 0 END) AS active,
            SUM(CASE WHEN status = \'cleaned\' THEN 1 ELSE 0 END) AS cleaned,
            SUM(CASE WHEN status = \'cleanup_failed\' THEN 1 ELSE 0 END) AS cleanup_failed
         FROM trial_users'
    )->fetch() ?: [];

    $range_statement = $pdo->prepare(
        'SELECT
            SUM(CASE WHEN created_at >= :today_start THEN 1 ELSE 0 END) AS today_total,
            SUM(CASE WHEN created_at >= :week_start THEN 1 ELSE 0 END) AS week_total,
            SUM(CASE WHEN created_at >= :month_start THEN 1 ELSE 0 END) AS month_total
         FROM trial_users'
    );
    $range_statement->execute([
        ':today_start' => $today_start->format(DATE_ATOM),
        ':week_start' => $week_start->format(DATE_ATOM),
        ':month_start' => $month_start->format(DATE_ATOM),
    ]);
    $ranges = $range_statement->fetch() ?: [];

    return [
        'available' => true,
        'total' => (int)($row['total'] ?? 0),
        'today' => (int)($ranges['today_total'] ?? 0),
        'week' => (int)($ranges['week_total'] ?? 0),
        'month' => (int)($ranges['month_total'] ?? 0),
        'active' => (int)($row['active'] ?? 0),
        'cleaned' => (int)($row['cleaned'] ?? 0),
        'cleanup_failed' => (int)($row['cleanup_failed'] ?? 0),
    ];
}

function getRecentTrialStatsRecords(int $limit = 100): array
{
    $pdo = getTrialStatsPdo();
    if (!$pdo) {
        return [];
    }

    $limit = max(1, min($limit, 250));
    $statement = $pdo->prepare(
        'SELECT
            request_code,
            username,
            email,
            full_name,
            client_ip,
            service,
            service_host,
            public_host,
            remote_address,
            expires_at,
            created_at,
            notes,
            status,
            fixed_ports_json,
            cleanup_attempts,
            last_cleanup_error,
            last_cleanup_attempt_at,
            cleaned_at
         FROM trial_users
         ORDER BY created_at DESC
         LIMIT :limit'
    );
    $statement->bindValue(':limit', $limit, PDO::PARAM_INT);
    $statement->execute();

    $rows = $statement->fetchAll() ?: [];

    foreach ($rows as &$row) {
        $fixed_ports = json_decode((string)($row['fixed_ports_json'] ?? '[]'), true);
        $row['fixed_ports'] = is_array($fixed_ports) ? $fixed_ports : [];
    }
    unset($row);

    return $rows;
}
