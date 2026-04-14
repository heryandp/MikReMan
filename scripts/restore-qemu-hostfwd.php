#!/usr/bin/env php
<?php

declare(strict_types=1);

require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/mikrotik.php';
require_once __DIR__ . '/../includes/qemu_hostfwd.php';
require_once __DIR__ . '/../includes/ppp_nat.php';
require_once __DIR__ . '/../includes/ppp_actions.php';
require_once __DIR__ . '/../includes/locks.php';

const RESTORE_PORT_START = 16000;
const RESTORE_PORT_END = 20000;

function buildRestoreHostFwdConfig(array $mikrotikConfig): array
{
    $socketPath = trim((string)(getenv('QEMU_HMP_SOCKET') ?: '/opt/mikreman/runtime/ros7-monitor/hmp.sock'));

    $mikrotikConfig['qemu_hostfwd_enabled'] = true;
    $mikrotikConfig['qemu_hostfwd_mode'] = 'local';
    $mikrotikConfig['qemu_hmp_socket'] = $socketPath;
    $mikrotikConfig['qemu_hostfwd_binary'] = trim((string)(getenv('SOCAT_BIN') ?: ($mikrotikConfig['qemu_hostfwd_binary'] ?? '/usr/bin/socat')));

    return $mikrotikConfig;
}

withAppLock('router-mutation', function (): void {
    $mikrotikConfig = getConfig('mikrotik') ?? [];
    $qemuHostFwd = getQemuHostFwdManager(buildRestoreHostFwdConfig($mikrotikConfig));

    if (!$qemuHostFwd->isEnabled()) {
        throw new RuntimeException('QEMU hostfwd integration is disabled in MikReMan config.');
    }

    $mikrotik = new MikroTikAPI();
    $secrets = $mikrotik->getPPPSecrets();

    if (!is_array($secrets) || $secrets === []) {
        echo "[restore-qemu-hostfwd] No PPP secrets found.\n";
        return;
    }

    $natRuleMap = [];

    foreach ($secrets as $secret) {
        $username = trim((string)($secret['name'] ?? ''));
        $remoteAddress = trim((string)($secret['remote-address'] ?? ''));

        if ($username === '' && $remoteAddress === '') {
            continue;
        }

        foreach (collectUserNatRules($mikrotik, $username, $remoteAddress) as $rule) {
            $ruleId = (string)($rule['.id'] ?? md5(json_encode($rule)));
            $natRuleMap[$ruleId] = $rule;
        }
    }

    if ($natRuleMap === []) {
        echo "[restore-qemu-hostfwd] No PPP NAT rules found.\n";
        return;
    }

    $restored = 0;
    $skipped = 0;
    $failed = 0;
    $seen = [];

    foreach ($natRuleMap as $rule) {
        $chain = strtolower(trim((string)($rule['chain'] ?? '')));
        $action = strtolower(trim((string)($rule['action'] ?? '')));
        $protocol = strtolower(trim((string)($rule['protocol'] ?? 'tcp')));
        $externalPort = trim((string)($rule['dst-port'] ?? ''));
        $internalPort = trim((string)($rule['to-ports'] ?? ''));
        $comment = trim((string)($rule['comment'] ?? ''));

        if ($chain !== 'dstnat' || $action !== 'dst-nat') {
            $skipped++;
            continue;
        }

        if (!in_array($protocol, ['tcp', 'udp'], true)) {
            $skipped++;
            continue;
        }

        if (!ctype_digit($externalPort) || !ctype_digit($internalPort)) {
            $skipped++;
            continue;
        }

        $portNumber = (int)$externalPort;
        if ($portNumber < RESTORE_PORT_START || $portNumber > RESTORE_PORT_END) {
            $skipped++;
            continue;
        }

        $key = $protocol . ':' . $externalPort;
        if (isset($seen[$key])) {
            continue;
        }
        $seen[$key] = true;

        $result = $qemuHostFwd->addForward($portNumber, $portNumber, $protocol);
        if (!empty($result['success'])) {
            $restored++;
            echo sprintf(
                "[restore-qemu-hostfwd] restored %s -> guest %s (%s)\n",
                $externalPort,
                $internalPort,
                $comment !== '' ? $comment : 'no-comment'
            );
            continue;
        }

        $failed++;
        echo sprintf(
            "[restore-qemu-hostfwd] failed %s/%s: %s\n",
            $protocol,
            $externalPort,
            $result['message'] ?? 'unknown error'
        );
    }

    echo sprintf(
        "[restore-qemu-hostfwd] done. restored=%d skipped=%d failed=%d\n",
        $restored,
        $skipped,
        $failed
    );
}, 30);
