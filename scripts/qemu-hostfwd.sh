#!/usr/bin/env bash
set -euo pipefail

ROOT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
SOCKET_PATH="${QEMU_HMP_SOCKET:-${ROOT_DIR}/runtime/ros7-monitor/hmp.sock}"
SOCAT_BIN="${SOCAT_BIN:-$(command -v socat || true)}"

usage() {
  cat <<EOF
Usage:
  $0 info
  $0 add <tcp|udp> <host_port> <guest_port>
  $0 remove <tcp|udp> <host_port>
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

action="${1:-}"
case "${action}" in
  info)
    printf 'info usernet\n' | "${SOCAT_BIN}" - "UNIX-CONNECT:${SOCKET_PATH}"
    ;;
  add)
    protocol="${2:-}"
    host_port="${3:-}"
    guest_port="${4:-}"
    [[ -n "${protocol}" && -n "${host_port}" && -n "${guest_port}" ]] || { usage; exit 1; }
    printf 'hostfwd_add %s::%s-:%s\n' "${protocol}" "${host_port}" "${guest_port}" | "${SOCAT_BIN}" - "UNIX-CONNECT:${SOCKET_PATH}"
    ;;
  remove)
    protocol="${2:-}"
    host_port="${3:-}"
    [[ -n "${protocol}" && -n "${host_port}" ]] || { usage; exit 1; }
    printf 'hostfwd_remove %s::%s\n' "${protocol}" "${host_port}" | "${SOCAT_BIN}" - "UNIX-CONNECT:${SOCKET_PATH}"
    ;;
  *)
    usage
    exit 1
    ;;
esac
