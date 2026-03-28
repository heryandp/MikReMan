#!/usr/bin/env bash
set -euo pipefail

if [[ "${EUID}" -ne 0 ]]; then
  echo "Run as root." >&2
  exit 1
fi

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
REPO_DIR="${REPO_DIR:-$(cd "${SCRIPT_DIR}/.." && pwd)}"
UNIT_NAME="${UNIT_NAME:-mikreman-host-iptables.service}"
UNIT_PATH="/etc/systemd/system/${UNIT_NAME}"

PORT_START="${PORT_START:-16000}"
PORT_END="${PORT_END:-20000}"
ROS7_IP="${ROS7_IP:-172.20.0.10}"

cat > "${UNIT_PATH}" <<EOF
[Unit]
Description=Restore MikReMan host iptables forwards for ros7
After=network-online.target docker.service
Wants=network-online.target docker.service

[Service]
Type=oneshot
Environment=PORT_START=${PORT_START}
Environment=PORT_END=${PORT_END}
Environment=ROS7_IP=${ROS7_IP}
ExecStart=/bin/bash ${REPO_DIR}/scripts/setup-host-iptables.sh
RemainAfterExit=yes

[Install]
WantedBy=multi-user.target
EOF

systemctl daemon-reload
systemctl enable --now "${UNIT_NAME}"

echo "Installed ${UNIT_NAME}"
systemctl status "${UNIT_NAME}" --no-pager || true
