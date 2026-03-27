#!/usr/bin/env bash
set -euo pipefail

ROOT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
COMPOSE_FILE="${ROOT_DIR}/docker-compose.yml"
MONITOR_DIR="${ROOT_DIR}/runtime/ros7-monitor"
MONITOR_USER="${ROS7_MONITOR_USER:-mikreman-fwd}"

"${ROOT_DIR}/scripts/init-ros7-qcow.sh"
mkdir -p "${MONITOR_DIR}"

if id -g "${MONITOR_USER}" >/dev/null 2>&1; then
  export ROS7_MONITOR_GID="${ROS7_MONITOR_GID:-$(id -g "${MONITOR_USER}")}"
else
  export ROS7_MONITOR_GID="${ROS7_MONITOR_GID:-0}"
fi

export ROS7_MONITOR_MODE="${ROS7_MONITOR_MODE:-660}"

docker compose -f "${COMPOSE_FILE}" up -d --force-recreate ros7

echo "Waiting for QEMU monitor sockets..."
for _ in $(seq 1 20); do
  if [[ -S "${MONITOR_DIR}/hmp.sock" && -S "${MONITOR_DIR}/qmp.sock" ]]; then
    echo "QEMU monitor is ready:"
    ls -l "${MONITOR_DIR}"
    echo "ROS7_MONITOR_GID=${ROS7_MONITOR_GID}"
    exit 0
  fi
  sleep 1
done

echo "QEMU monitor socket did not appear in time." >&2
exit 1
