#!/usr/bin/env bash
set -euo pipefail

ROOT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"

"${ROOT_DIR}/scripts/init-ros7-qcow.sh"
"${ROOT_DIR}/scripts/recreate-ros7.sh"

if [[ "${EUID}" -eq 0 ]]; then
  "${ROOT_DIR}/scripts/setup-host-iptables.sh"
else
  echo "Host iptables setup requires root. Run manually:"
  echo "  sudo ${ROOT_DIR}/scripts/setup-host-iptables.sh"
fi

docker compose -f "${ROOT_DIR}/docker-compose.yml" up -d mikreman

cat <<EOF

Bootstrap complete.

- MikReMan: http://127.0.0.1:${MIKREMAN_HTTP_PORT:-8080}
- QEMU HMP socket in the app: /opt/ros7-monitor/hmp.sock
- Host monitor dir: ${ROOT_DIR}/runtime/ros7-monitor
- ROS7 monitor GID: ${ROS7_MONITOR_GID:-auto}

Next steps in the admin page:
- enable QEMU Dynamic Host Forward
- set QEMU HMP Socket to /opt/ros7-monitor/hmp.sock
- set socat Binary to /usr/bin/socat
EOF
