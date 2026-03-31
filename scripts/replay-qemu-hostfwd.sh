#!/usr/bin/env bash
set -euo pipefail

SOCKET_PATH="${QEMU_HMP_SOCKET:-/opt/ros7-monitor/hmp.sock}"
SOCAT_BIN="${SOCAT_BIN:-$(command -v socat || true)}"
PROTOCOL="${1:-tcp}"

usage() {
  cat <<EOF
Usage:
  $0 <tcp|udp> <port> [port...]

Example:
  $0 tcp 19099 17602 17688
EOF
}

if [[ -z "${SOCAT_BIN}" ]]; then
  echo "socat not found" >&2
  exit 1
fi

if [[ ! -S "${SOCKET_PATH}" ]]; then
  echo "QEMU HMP socket not found: ${SOCKET_PATH}" >&2
  exit 1
fi

if [[ "${PROTOCOL}" != "tcp" && "${PROTOCOL}" != "udp" ]]; then
  usage
  exit 1
fi

shift || true

if [[ "$#" -lt 1 ]]; then
  usage
  exit 1
fi

for port in "$@"; do
  if [[ ! "${port}" =~ ^[0-9]+$ ]]; then
    echo "Skipping invalid port: ${port}" >&2
    continue
  fi

  printf 'hostfwd_add %s::%s-:%s\n' "${PROTOCOL}" "${port}" "${port}" | "${SOCAT_BIN}" - "UNIX-CONNECT:${SOCKET_PATH}" >/dev/null
  echo "restored:${PROTOCOL}:${port}"
done
