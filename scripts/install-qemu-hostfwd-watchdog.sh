#!/usr/bin/env bash
set -euo pipefail

if [[ "${EUID}" -ne 0 ]]; then
  echo "Run as root." >&2
  exit 1
fi

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
REPO_DIR="${REPO_DIR:-$(cd "${SCRIPT_DIR}/.." && pwd)}"
UNIT_NAME="${UNIT_NAME:-mikreman-qemu-hostfwd-watchdog.service}"
TIMER_NAME="${TIMER_NAME:-mikreman-qemu-hostfwd-watchdog.timer}"
UNIT_PATH="/etc/systemd/system/${UNIT_NAME}"
TIMER_PATH="/etc/systemd/system/${TIMER_NAME}"
SOCKET_PATH="${QEMU_HMP_SOCKET:-/opt/mikreman/runtime/ros7-monitor/hmp.sock}"

cat > "${UNIT_PATH}" <<EOF
[Unit]
Description=Restore MikReMan QEMU hostfwd entries
After=network-online.target docker.service
Wants=network-online.target docker.service

[Service]
Type=oneshot
Environment=QEMU_HMP_SOCKET=${SOCKET_PATH}
ExecStart=/bin/bash ${REPO_DIR}/scripts/restore-qemu-hostfwd-from-app.sh

[Install]
WantedBy=multi-user.target
EOF

cat > "${TIMER_PATH}" <<EOF
[Unit]
Description=Periodic MikReMan QEMU hostfwd restore watchdog

[Timer]
OnBootSec=45s
OnUnitActiveSec=60s
Persistent=true
Unit=${UNIT_NAME}

[Install]
WantedBy=timers.target
EOF

systemctl daemon-reload
systemctl enable --now "${UNIT_NAME}" "${TIMER_NAME}"

echo "Installed ${UNIT_NAME} and ${TIMER_NAME}"
systemctl status "${UNIT_NAME}" --no-pager || true
systemctl status "${TIMER_NAME}" --no-pager || true
