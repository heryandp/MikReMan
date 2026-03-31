#!/usr/bin/env bash
set -euo pipefail

ROOT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
APP_CONTAINER="${MIKREMAN_CONTAINER:-mikreman-app}"
SOCKET_PATH="${QEMU_HMP_SOCKET:-/opt/ros7-monitor/hmp.sock}"
PORT_START="${PORT_START:-16000}"
PORT_END="${PORT_END:-20000}"

if ! command -v docker >/dev/null 2>&1; then
  echo "docker not found" >&2
  exit 1
fi

if ! docker ps --format '{{.Names}}' | grep -qx "${APP_CONTAINER}"; then
  echo "MikReMan container is not running: ${APP_CONTAINER}" >&2
  exit 1
fi

mapfile -t port_lines < <(
  docker exec -i "${APP_CONTAINER}" php <<'PHP'
<?php
declare(strict_types=1);

require_once '/var/www/html/includes/config.php';
require_once '/var/www/html/includes/mikrotik.php';
require_once '/var/www/html/includes/ppp_actions.php';

$portStart = (int) (getenv('PORT_START') ?: 16000);
$portEnd = (int) (getenv('PORT_END') ?: 20000);
$config = getConfig('mikrotik') ?? [];

if (empty($config['qemu_hostfwd_enabled'])) {
    exit(0);
}

$mikrotik = new MikroTikAPI();
$secrets = $mikrotik->getPPPSecrets();
$ruleMap = [];

if (is_array($secrets)) {
    foreach ($secrets as $secret) {
        $username = trim((string) ($secret['name'] ?? ''));
        $remoteAddress = trim((string) ($secret['remote-address'] ?? ''));

        foreach (collectUserNatRules($mikrotik, $username, $remoteAddress) as $rule) {
            $ruleId = (string) ($rule['.id'] ?? md5(json_encode($rule)));
            $ruleMap[$ruleId] = $rule;
        }
    }
}

$seen = [];
foreach ($ruleMap as $rule) {
    $chain = strtolower(trim((string) ($rule['chain'] ?? '')));
    $action = strtolower(trim((string) ($rule['action'] ?? '')));
    $protocol = strtolower(trim((string) ($rule['protocol'] ?? 'tcp')));
    $externalPort = trim((string) ($rule['dst-port'] ?? ''));
    $internalPort = trim((string) ($rule['to-ports'] ?? ''));

    if ($chain !== 'dstnat' || $action !== 'dst-nat') {
        continue;
    }

    if (!in_array($protocol, ['tcp', 'udp'], true)) {
        continue;
    }

    if (!ctype_digit($externalPort) || !ctype_digit($internalPort)) {
        continue;
    }

    $port = (int) $externalPort;
    if ($port < $portStart || $port > $portEnd) {
        continue;
    }

    $key = $protocol . ':' . $externalPort;
    if (isset($seen[$key])) {
        continue;
    }

    $seen[$key] = true;
    echo $protocol . ' ' . $externalPort . PHP_EOL;
}
PHP
)

if [[ "${#port_lines[@]}" -eq 0 ]]; then
  echo "No dynamic QEMU hostfwd ports found in current PPP NAT rules."
  exit 0
fi

restored=0

for line in "${port_lines[@]}"; do
  protocol="${line%% *}"
  port="${line##* }"

  if [[ -z "${protocol}" || -z "${port}" ]]; then
    continue
  fi

  QEMU_HMP_SOCKET="${SOCKET_PATH}" "${ROOT_DIR}/scripts/replay-qemu-hostfwd.sh" "${protocol}" "${port}"
  restored=$((restored + 1))
done

echo "Restored ${restored} dynamic QEMU hostfwd entries from ${APP_CONTAINER}."
