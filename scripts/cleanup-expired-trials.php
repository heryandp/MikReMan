#!/usr/bin/env php
<?php

declare(strict_types=1);

require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/mikrotik.php';
require_once __DIR__ . '/../includes/qemu_hostfwd.php';
require_once __DIR__ . '/../includes/ppp_nat.php';
require_once __DIR__ . '/../includes/ppp_actions.php';
require_once __DIR__ . '/../includes/trial_orders.php';
require_once __DIR__ . '/../includes/trial_stats.php';
require_once __DIR__ . '/../includes/locks.php';

withAppLock('trial-cleanup', function () {
    $now = new DateTimeImmutable();
    $expired_paths = listExpiredTrialOrderPaths($now);

    if (empty($expired_paths)) {
        echo "[cleanup-expired-trials] No expired trial records found.\n";
        return;
    }

    $success_count = 0;
    $failure_count = 0;

    foreach ($expired_paths as $path) {
        $record = loadTrialOrderRecord($path);
        if (!$record) {
            deleteTrialOrderRecord($path);
            continue;
        }

        try {
            $result = withAppLock('router-mutation', function () use ($record) {
                return cleanupExpiredTrialRecord($record);
            }, 30);
            markTrialStatsCleaned($record, $result, $now);
            deleteTrialOrderRecord($path);
            $success_count++;

            echo sprintf(
                "[cleanup-expired-trials] cleaned %s (%s), nat=%d, hostfwd=%d\n",
                $result['request_code'] ?: 'unknown',
                $result['username'],
                (int)($result['nat_deleted'] ?? 0),
                (int)($result['hostfwd_removed'] ?? 0)
            );
        } catch (Throwable $e) {
            $failure_count++;
            $record['cleanup_attempts'] = (int)($record['cleanup_attempts'] ?? 0) + 1;
            $record['last_cleanup_error'] = $e->getMessage();
            $record['last_cleanup_attempt_at'] = $now->format(DATE_ATOM);
            updateTrialOrderRecord($path, $record);
            markTrialStatsCleanupFailure($record, $now, $e->getMessage(), (int)$record['cleanup_attempts']);

            echo sprintf(
                "[cleanup-expired-trials] failed %s: %s\n",
                $record['request_code'] ?? basename($path, '.json'),
                $e->getMessage()
            );
        }
    }

    echo sprintf(
        "[cleanup-expired-trials] done. success=%d failed=%d\n",
        $success_count,
        $failure_count
    );
}, 5);
