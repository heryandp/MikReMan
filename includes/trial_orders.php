<?php

require_once __DIR__ . '/trial_stats.php';
require_once __DIR__ . '/wg_easy.php';

function getTrialDisplayTimezone(): DateTimeZone
{
    return new DateTimeZone(date_default_timezone_get() ?: 'Asia/Jakarta');
}

function formatTrialDisplayDate(DateTimeImmutable $dateTime, string $format = 'Y-m-d H:i'): string
{
    return $dateTime->setTimezone(getTrialDisplayTimezone())->format($format);
}

function getTrialOrdersBaseDir(): string
{
    return dirname(__DIR__) . '/runtime/trials';
}

function getTrialOrdersIndexDir(): string
{
    return getTrialOrdersBaseDir() . '/_index';
}

function getTrialOrdersLogDir(): string
{
    return getTrialOrdersBaseDir() . '/_logs';
}

function ensureTrialOrdersDirectory(string $directory): void
{
    if (is_dir($directory)) {
        return;
    }

    if (!mkdir($directory, 0775, true) && !is_dir($directory)) {
        throw new RuntimeException('Failed to create trial runtime directory: ' . $directory);
    }
}

function normalizeTrialEmail(string $email): string
{
    return strtolower(trim($email));
}

function normalizeTrialIp(string $ip): string
{
    return trim($ip);
}

function hashTrialIndexValue(string $value): string
{
    return hash('sha256', $value);
}

function buildTrialEmailIndexPath(string $email): string
{
    $directory = getTrialOrdersIndexDir() . '/email';
    ensureTrialOrdersDirectory($directory);
    return $directory . '/' . hashTrialIndexValue(normalizeTrialEmail($email)) . '.json';
}

function buildTrialIpIndexPath(string $ip): string
{
    $directory = getTrialOrdersIndexDir() . '/ip';
    ensureTrialOrdersDirectory($directory);
    return $directory . '/' . hashTrialIndexValue(normalizeTrialIp($ip)) . '.json';
}

function writeTrialIndexRecord(string $path, array $payload): void
{
    $encoded = json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    if ($encoded === false || file_put_contents($path, $encoded, LOCK_EX) === false) {
        throw new RuntimeException('Failed to write trial index: ' . $path);
    }
}

function loadTrialIndexRecord(string $path): ?array
{
    if (!is_file($path)) {
        return null;
    }

    $contents = file_get_contents($path);
    if ($contents === false) {
        return null;
    }

    $decoded = json_decode($contents, true);
    return is_array($decoded) ? $decoded : null;
}

function touchTrialRequestIndexes(array $record): void
{
    $created_at = trim((string) ($record['created_at'] ?? ''));
    $expires_at = trim((string) ($record['expires_at'] ?? ''));
    $request_code = trim((string) ($record['request_code'] ?? ''));
    $username = trim((string) ($record['username'] ?? ''));
    $record_path = trim((string) ($record['record_path'] ?? ''));
    $email = normalizeTrialEmail((string) ($record['email'] ?? ''));
    $ip = normalizeTrialIp((string) ($record['client_ip'] ?? ''));

    $payload = [
        'request_code' => $request_code,
        'username' => $username,
        'email' => $email,
        'client_ip' => $ip,
        'created_at' => $created_at,
        'expires_at' => $expires_at,
        'record_path' => $record_path,
        'status' => 'active',
    ];

    if ($email !== '') {
        writeTrialIndexRecord(buildTrialEmailIndexPath($email), $payload);
    }

    if ($ip !== '') {
        writeTrialIndexRecord(buildTrialIpIndexPath($ip), $payload);
    }
}

function recordTrialRequestEvent(array $event): void
{
    $date = (new DateTimeImmutable())->format('Y-m-d');
    $directory = getTrialOrdersLogDir();
    ensureTrialOrdersDirectory($directory);
    $path = $directory . '/' . $date . '.ndjson';

    $event['logged_at'] = (new DateTimeImmutable())->format(DATE_ATOM);
    $line = json_encode($event, JSON_UNESCAPED_SLASHES);

    if ($line === false) {
        return;
    }

    @file_put_contents($path, $line . PHP_EOL, FILE_APPEND | LOCK_EX);
}

function inspectTrialIndexState(?array $index, DateTimeImmutable $now): ?array
{
    if (!$index) {
        return null;
    }

    $created_at_raw = trim((string) ($index['created_at'] ?? ''));
    $expires_at_raw = trim((string) ($index['expires_at'] ?? ''));
    $record_path = trim((string) ($index['record_path'] ?? ''));
    $is_active = false;

    try {
        $created_at = $created_at_raw !== '' ? new DateTimeImmutable($created_at_raw) : null;
    } catch (Exception $e) {
        $created_at = null;
    }

    try {
        $expires_at = $expires_at_raw !== '' ? new DateTimeImmutable($expires_at_raw) : null;
    } catch (Exception $e) {
        $expires_at = null;
    }

    if ($record_path !== '' && is_file($record_path)) {
        $record = loadTrialOrderRecord($record_path);
        if (is_array($record) && !empty($record['expires_at'])) {
            try {
                $record_expires_at = new DateTimeImmutable((string) $record['expires_at']);
                if ($record_expires_at > $now) {
                    $is_active = true;
                    $expires_at = $record_expires_at;
                }
            } catch (Exception $e) {
            }
        }
    }

    return [
        'request_code' => (string) ($index['request_code'] ?? ''),
        'username' => (string) ($index['username'] ?? ''),
        'created_at' => $created_at,
        'expires_at' => $expires_at,
        'is_active' => $is_active,
    ];
}

function getExistingTrialConstraint(string $email, string $ip, int $emailCooldownSeconds, int $ipCooldownSeconds, ?DateTimeImmutable $now = null): ?array
{
    $now = $now ?? new DateTimeImmutable('now', getTrialDisplayTimezone());
    $email = normalizeTrialEmail($email);
    $ip = normalizeTrialIp($ip);

    if ($email !== '') {
        $emailState = inspectTrialIndexState(loadTrialIndexRecord(buildTrialEmailIndexPath($email)), $now);
        if ($emailState) {
            if ($emailState['is_active'] && $emailState['expires_at'] instanceof DateTimeImmutable) {
                return [
                    'type' => 'email_active',
                    'message' => 'An active trial already exists for this email until ' . formatTrialDisplayDate($emailState['expires_at']) . ' WIB.',
                ];
            }

            if ($emailState['created_at'] instanceof DateTimeImmutable) {
                $retry_at = $emailState['created_at']->modify('+' . $emailCooldownSeconds . ' seconds');
                if ($retry_at > $now) {
                    return [
                        'type' => 'email_cooldown',
                        'message' => 'A recent trial request already used this email. Please try again after ' . formatTrialDisplayDate($retry_at) . ' WIB.',
                    ];
                }
            }
        }
    }

    if ($ip !== '') {
        $ipState = inspectTrialIndexState(loadTrialIndexRecord(buildTrialIpIndexPath($ip)), $now);
        if ($ipState && $ipState['created_at'] instanceof DateTimeImmutable) {
            $retry_at = $ipState['created_at']->modify('+' . $ipCooldownSeconds . ' seconds');
            if ($retry_at > $now) {
                return [
                    'type' => 'ip_cooldown',
                    'message' => 'A recent trial request already came from this IP. Please try again after ' . formatTrialDisplayDate($retry_at) . ' WIB.',
                ];
            }
        }
    }

    return null;
}

function buildTrialOrderRecordPath(string $request_code, string $expires_at): string
{
    $date_directory = (new DateTimeImmutable($expires_at))->format('Y-m-d');
    $base_directory = getTrialOrdersBaseDir() . '/' . $date_directory;
    ensureTrialOrdersDirectory($base_directory);

    return $base_directory . '/' . strtolower($request_code) . '.json';
}

function persistTrialOrderRecord(array $record): string
{
    $request_code = trim((string)($record['request_code'] ?? ''));
    $expires_at = trim((string)($record['expires_at'] ?? ''));

    if ($request_code === '' || $expires_at === '') {
        throw new InvalidArgumentException('Trial record requires request_code and expires_at');
    }

    $path = buildTrialOrderRecordPath($request_code, $expires_at);
    $payload = json_encode($record, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);

    if ($payload === false) {
        throw new RuntimeException('Failed to encode trial record JSON');
    }

    if (file_put_contents($path, $payload, LOCK_EX) === false) {
        throw new RuntimeException('Failed to write trial record: ' . $path);
    }

    $record['record_path'] = $path;
    touchTrialRequestIndexes($record);
    syncTrialStatsRecord($record);

    return $path;
}

function loadTrialOrderRecord(string $path): ?array
{
    if (!is_file($path)) {
        return null;
    }

    $contents = file_get_contents($path);
    if ($contents === false) {
        return null;
    }

    $decoded = json_decode($contents, true);
    return is_array($decoded) ? $decoded : null;
}

function updateTrialOrderRecord(string $path, array $record): void
{
    $payload = json_encode($record, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);

    if ($payload === false) {
        throw new RuntimeException('Failed to encode updated trial record JSON');
    }

    if (file_put_contents($path, $payload, LOCK_EX) === false) {
        throw new RuntimeException('Failed to update trial record: ' . $path);
    }

    $record['record_path'] = $path;
    syncTrialStatsRecord($record);
}

function deleteTrialOrderRecord(string $path): void
{
    if (is_file($path)) {
        @unlink($path);
    }
}

function listExpiredTrialOrderPaths(?DateTimeImmutable $now = null): array
{
    $base_directory = getTrialOrdersBaseDir();
    if (!is_dir($base_directory)) {
        return [];
    }

    $now = $now ?? new DateTimeImmutable();
    $today = $now->format('Y-m-d');
    $paths = [];

    $directories = scandir($base_directory);
    if (!is_array($directories)) {
        return [];
    }

    foreach ($directories as $directory) {
        if ($directory === '.' || $directory === '..') {
            continue;
        }

        $full_directory = $base_directory . '/' . $directory;
        if (!is_dir($full_directory) || strcmp($directory, $today) > 0) {
            continue;
        }

        $files = glob($full_directory . '/*.json') ?: [];
        foreach ($files as $file) {
            $record = loadTrialOrderRecord($file);
            if (!$record) {
                continue;
            }

            $expires_at = trim((string)($record['expires_at'] ?? ''));
            if ($expires_at === '') {
                continue;
            }

            try {
                $expires = new DateTimeImmutable($expires_at);
            } catch (Exception $e) {
                continue;
            }

            if ($expires <= $now) {
                $paths[] = $file;
            }
        }
    }

    sort($paths);
    return $paths;
}

function cleanupExpiredTrialRecord(array $record): array
{
    $mikrotik = new MikroTikAPI();
    $mikrotik_config = getConfig('mikrotik') ?? [];
    $qemu_hostfwd = getQemuHostFwdManager($mikrotik_config);

    $username = trim((string)($record['username'] ?? ''));
    $remote_address = trim((string)($record['remote_address'] ?? ''));
    $request_code = trim((string)($record['request_code'] ?? ''));
    $service = strtoupper(trim((string)($record['service'] ?? 'PPP')));

    if ($username === '') {
        throw new InvalidArgumentException('Expired trial record is missing username');
    }

    if ($service === 'WIREGUARD') {
        if (($record['wireguard_backend'] ?? '') === 'wg-easy' || !empty($record['wg_easy_client_id'])) {
            $clientId = (int)($record['wg_easy_client_id'] ?? 0);
            $clientDeleted = false;

            try {
                if ($clientId > 0) {
                    $wgEasy = getWgEasyClient($mikrotik_config);
                    $wgEasy->login();
                    $clientDeleted = $wgEasy->deleteClient($clientId);
                }
            } catch (Exception $e) {
                error_log('[TRIAL CLEANUP] wg-easy cleanup failed for ' . $username . ': ' . $e->getMessage());
                throw $e;
            }

            return [
                'request_code' => $request_code,
                'username' => $username,
                'peer_deleted' => $clientDeleted,
                'errors' => [],
            ];
        }

        $peer_id = trim((string)($record['peer_id'] ?? ''));
        $peer_deleted = false;

        try {
            if ($peer_id !== '' && $mikrotik->getWireGuardPeer($peer_id)) {
                $peer_deleted = $mikrotik->deleteWireGuardPeer($peer_id);
            } else {
                foreach ($mikrotik->getWireGuardPeers() as $peer) {
                    if (($peer['name'] ?? '') === $username || ($peer['comment'] ?? '') === $username) {
                        if (!empty($peer['.id'])) {
                            $peer_deleted = $mikrotik->deleteWireGuardPeer((string)$peer['.id']);
                        }
                        break;
                    }
                }
            }
        } catch (Exception $e) {
            error_log('[TRIAL CLEANUP] WireGuard cleanup failed for ' . $username . ': ' . $e->getMessage());
            throw $e;
        }

        return [
            'request_code' => $request_code,
            'username' => $username,
            'peer_deleted' => $peer_deleted,
            'errors' => [],
        ];
    }

    $nat_rules = collectUserNatRules($mikrotik, $username, $remote_address);
    $nat_cleanup = deletePPPUserNatRules($mikrotik, $nat_rules, $qemu_hostfwd);

    $netwatch_deleted = false;
    try {
        $netwatch_deleted = $mikrotik->deleteNetwatchByComment($username);
    } catch (Exception $e) {
        error_log('[TRIAL CLEANUP] Netwatch-by-comment cleanup failed for ' . $username . ': ' . $e->getMessage());
    }

    if (!$netwatch_deleted && $remote_address !== '') {
        try {
            $netwatch_deleted = $mikrotik->deleteNetwatchByHost($remote_address);
        } catch (Exception $e) {
            error_log('[TRIAL CLEANUP] Netwatch-by-host cleanup failed for ' . $username . ': ' . $e->getMessage());
        }
    }

    try {
        $mikrotik->runScript('/ppp active remove [find where name=' . routerOsQuote($username) . ']');
    } catch (Exception $e) {
        error_log('[TRIAL CLEANUP] PPP active cleanup failed for ' . $username . ': ' . $e->getMessage());
    }

    $deleted_secret = false;
    $secrets = $mikrotik->getPPPSecrets();
    if (is_array($secrets)) {
        foreach ($secrets as $secret) {
            if (($secret['name'] ?? '') !== $username || empty($secret['.id'])) {
                continue;
            }

            $mikrotik->deletePPPSecret($secret['.id']);
            $deleted_secret = true;
            break;
        }
    }

    try {
        $mikrotik->deleteSystemSchedulerByName('trial-expire-' . $username);
    } catch (Exception $e) {
        error_log('[TRIAL CLEANUP] Legacy scheduler cleanup failed for ' . $username . ': ' . $e->getMessage());
    }

    return [
        'request_code' => $request_code,
        'username' => $username,
        'nat_deleted' => $nat_cleanup['deleted_count'] ?? 0,
        'hostfwd_removed' => $nat_cleanup['hostfwd_removed_count'] ?? 0,
        'netwatch_deleted' => $netwatch_deleted,
        'secret_deleted' => $deleted_secret,
        'errors' => $nat_cleanup['errors'] ?? [],
    ];
}

function routerOsQuote($value): string
{
    $value = str_replace(['\\', '"'], ['\\\\', '\"'], (string)$value);
    return '"' . $value . '"';
}
