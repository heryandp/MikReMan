#!/usr/bin/env bash
set -euo pipefail

ROOT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
APP_CONTAINER="${MIKREMAN_CONTAINER:-mikreman-app}"
SOCKET_PATH="${QEMU_HMP_SOCKET:-/opt/mikreman/runtime/ros7-monitor/hmp.sock}"
HOSTFWD_SNAPSHOT_FILE="${HOSTFWD_SNAPSHOT_FILE:-${ROOT_DIR}/runtime/ros7-monitor/hostfwd-snapshot.txt}"
PORT_START="${PORT_START:-16000}"
PORT_END="${PORT_END:-20000}"
MAX_RETRIES="${MAX_RETRIES:-12}"
RETRY_DELAY_SECONDS="${RETRY_DELAY_SECONDS:-5}"

if ! command -v docker >/dev/null 2>&1; then
  echo "docker not found" >&2
  exit 1
fi

if ! docker ps --format '{{.Names}}' | grep -qx "${APP_CONTAINER}"; then
  echo "MikReMan container is not running: ${APP_CONTAINER}" >&2
  exit 1
fi

load_port_lines() {
  docker exec -i "${APP_CONTAINER}" php <<'PHP'
<?php
declare(strict_types=1);

require_once '/var/www/html/includes/config.php';
require_once '/var/www/html/includes/mikrotik.php';
require_once '/var/www/html/includes/ppp_actions.php';

try {
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
} catch (Throwable $e) {
    fwrite(STDERR, '[restore-qemu-hostfwd] router data unavailable yet: ' . $e->getMessage() . PHP_EOL);
    exit(1);
}
PHP
}

load_current_hostfwd_keys() {
  if [[ ! -S "${SOCKET_PATH}" ]]; then
    return 0
  fi

  if ! command -v socat >/dev/null 2>&1; then
    return 0
  fi

  printf 'info usernet\n' | socat - "UNIX-CONNECT:${SOCKET_PATH}" 2>/dev/null \
    | awk '
        $1 ~ /\[HOST_FORWARD\]/ {
          protocol = tolower($1)
          sub(/\[.*/, "", protocol)
          port = $4
          if (port ~ /^[0-9]+$/) {
            print protocol, port
          }
        }
      ' \
    | sort -u
}

port_lines=()
for attempt in $(seq 1 "${MAX_RETRIES}"); do
  if mapfile -t port_lines < <(load_port_lines 2>/dev/null); then
    break
  fi

  if [[ "${attempt}" -lt "${MAX_RETRIES}" ]]; then
    sleep "${RETRY_DELAY_SECONDS}"
  fi
done

if [[ "${#port_lines[@]}" -eq 0 ]]; then
  if [[ -s "${HOSTFWD_SNAPSHOT_FILE}" ]]; then
    mapfile -t port_lines < "${HOSTFWD_SNAPSHOT_FILE}" || true
    if [[ "${#port_lines[@]}" -gt 0 ]]; then
      echo "Falling back to hostfwd snapshot: ${HOSTFWD_SNAPSHOT_FILE}"
    fi
  fi
fi

if [[ "${#port_lines[@]}" -eq 0 ]]; then
  echo "No dynamic QEMU hostfwd ports found in current PPP NAT rules or snapshot."
  exit 0
fi

current_lines=()
if mapfile -t current_lines < <(load_current_hostfwd_keys 2>/dev/null); then
  :
fi

declare -A current_seen=()
for line in "${current_lines[@]}"; do
  protocol="${line%% *}"
  port="${line##* }"
  if [[ -n "${protocol}" && -n "${port}" ]]; then
    current_seen["${protocol}:${port}"]=1
  fi
done

restored=0

for line in "${port_lines[@]}"; do
  protocol="${line%% *}"
  port="${line##* }"

  if [[ -z "${protocol}" || -z "${port}" ]]; then
    continue
  fi

  if [[ "${protocol}" != "tcp" && "${protocol}" != "udp" ]]; then
    echo "Skipping unexpected restore line: ${line}" >&2
    continue
  fi

  if [[ ! "${port}" =~ ^[0-9]+$ ]]; then
    echo "Skipping unexpected restore port: ${line}" >&2
    continue
  fi

  current_key="${protocol}:${port}"
  if [[ -n "${current_seen[$current_key]:-}" ]]; then
    echo "already:${protocol}:${port}"
    continue
  fi

  QEMU_HMP_SOCKET="${SOCKET_PATH}" "${ROOT_DIR}/scripts/replay-qemu-hostfwd.sh" "${protocol}" "${port}"
  restored=$((restored + 1))
done

echo "Restored ${restored} dynamic QEMU hostfwd entries from ${APP_CONTAINER}."
