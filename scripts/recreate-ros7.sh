#!/usr/bin/env bash
set -euo pipefail

ROOT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
COMPOSE_FILE="${ROOT_DIR}/docker-compose.yml"
MONITOR_DIR="${ROOT_DIR}/runtime/ros7-monitor"
MONITOR_USER="${ROS7_MONITOR_USER:-mikreman-fwd}"
ROS7_CONTAINER_NAME="${ROS7_CONTAINER_NAME:-ros7}"
ROS7_NETWORK_NAME="${ROS7_NETWORK_NAME:-chr_net}"
ROS7_NETWORK_SUBNET="${ROS7_NETWORK_SUBNET:-172.20.0.0/24}"
ROS7_NETWORK_GATEWAY="${ROS7_NETWORK_GATEWAY:-172.20.0.1}"
COMPOSE_PROJECT="${COMPOSE_PROJECT_NAME:-$(basename "${ROOT_DIR}")}"
HOSTFWD_SNAPSHOT_FILE="${HOSTFWD_SNAPSHOT_FILE:-${ROOT_DIR}/runtime/ros7-monitor/hostfwd-snapshot.txt}"
HOSTFWD_PORT_START="${HOSTFWD_PORT_START:-16000}"
HOSTFWD_PORT_END="${HOSTFWD_PORT_END:-20000}"

"${ROOT_DIR}/scripts/init-ros7-qcow.sh"
mkdir -p "${MONITOR_DIR}"

capture_dynamic_hostfwd_snapshot() {
  if [[ ! -S "${MONITOR_DIR}/hmp.sock" ]]; then
    return 0
  fi

  if ! command -v socat >/dev/null 2>&1; then
    echo "Warning: socat not found, skipping hostfwd snapshot." >&2
    return 0
  fi

  if printf 'info usernet\n' | socat - "UNIX-CONNECT:${MONITOR_DIR}/hmp.sock" 2>/dev/null \
    | awk -v port_start="${HOSTFWD_PORT_START}" -v port_end="${HOSTFWD_PORT_END}" '
        $1 ~ /\[HOST_FORWARD\]/ {
          protocol = tolower($1)
          sub(/\[.*/, "", protocol)
          port = $4
          if (port ~ /^[0-9]+$/ && port >= port_start && port <= port_end) {
            print protocol, port
          }
        }
      ' \
    | sort -u > "${HOSTFWD_SNAPSHOT_FILE}"; then
    if [[ -s "${HOSTFWD_SNAPSHOT_FILE}" ]]; then
      echo "Captured dynamic hostfwd snapshot:"
      cat "${HOSTFWD_SNAPSHOT_FILE}"
    fi
  fi
}

restore_dynamic_hostfwd_snapshot() {
  if [[ ! -s "${HOSTFWD_SNAPSHOT_FILE}" ]]; then
    return 0
  fi

  echo "Replaying dynamic QEMU hostfwd snapshot..."

  while read -r protocol port; do
    if [[ -z "${protocol}" || -z "${port}" ]]; then
      continue
    fi

    QEMU_HMP_SOCKET="${MONITOR_DIR}/hmp.sock" "${ROOT_DIR}/scripts/replay-qemu-hostfwd.sh" "${protocol}" "${port}"
  done < "${HOSTFWD_SNAPSHOT_FILE}"
}

if ! docker network inspect "${ROS7_NETWORK_NAME}" >/dev/null 2>&1; then
  echo "Creating Docker network ${ROS7_NETWORK_NAME} (${ROS7_NETWORK_SUBNET})..."
  docker network create \
    --driver bridge \
    --subnet "${ROS7_NETWORK_SUBNET}" \
    --gateway "${ROS7_NETWORK_GATEWAY}" \
    "${ROS7_NETWORK_NAME}" >/dev/null
fi

if id -g "${MONITOR_USER}" >/dev/null 2>&1; then
  export ROS7_MONITOR_GID="${ROS7_MONITOR_GID:-$(id -g "${MONITOR_USER}")}"
else
  export ROS7_MONITOR_GID="${ROS7_MONITOR_GID:-0}"
fi

export ROS7_MONITOR_MODE="${ROS7_MONITOR_MODE:-660}"

if docker inspect "${ROS7_CONTAINER_NAME}" >/dev/null 2>&1; then
  existing_project="$(docker inspect -f '{{ index .Config.Labels "com.docker.compose.project" }}' "${ROS7_CONTAINER_NAME}" 2>/dev/null || true)"

  capture_dynamic_hostfwd_snapshot

  if [[ -z "${existing_project}" || "${existing_project}" != "${COMPOSE_PROJECT}" ]]; then
    echo "Removing existing container ${ROS7_CONTAINER_NAME} (compose project: ${existing_project:-none}) to allow managed recreate..."
    docker rm -f "${ROS7_CONTAINER_NAME}" >/dev/null
  fi
fi

docker compose -f "${COMPOSE_FILE}" up -d --force-recreate ros7

echo "Waiting for QEMU monitor sockets..."
for _ in $(seq 1 20); do
  if [[ -S "${MONITOR_DIR}/hmp.sock" && -S "${MONITOR_DIR}/qmp.sock" ]]; then
    echo "QEMU monitor is ready:"
    ls -l "${MONITOR_DIR}"
    echo "ROS7_MONITOR_GID=${ROS7_MONITOR_GID}"

    restore_dynamic_hostfwd_snapshot

    if [[ "${RESTORE_HOSTFWD_AFTER_RECREATE:-1}" == "1" ]]; then
      echo "Replaying dynamic QEMU hostfwd entries from MikReMan..."
      if ! env QEMU_HMP_SOCKET="${MONITOR_DIR}/hmp.sock" "${ROOT_DIR}/scripts/restore-qemu-hostfwd-from-app.sh"; then
        echo "Warning: failed to restore dynamic QEMU hostfwd entries." >&2
      fi
    fi

    exit 0
  fi

  sleep 1
done

echo "QEMU monitor socket did not appear in time." >&2
exit 1
