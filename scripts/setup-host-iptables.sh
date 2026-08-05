#!/usr/bin/env bash
set -euo pipefail

PORT_START="${PORT_START:-16000}"
PORT_END="${PORT_END:-20000}"
ROS7_IP="${ROS7_IP:-172.20.0.10}"
LOG_FILE="${LOG_FILE:-/var/log/mikreman-host-iptables.log}"
PORT_RANGE="${PORT_START}:${PORT_END}"

if [[ "${EUID}" -ne 0 ]]; then
  echo "Run as root." >&2
  exit 1
fi

mkdir -p "$(dirname "${LOG_FILE}")"
touch "${LOG_FILE}"
exec > >(tee -a "${LOG_FILE}") 2>&1

echo "[host-iptables] applying rules at $(date -Is)"

sysctl -w net.ipv4.ip_forward=1 >/dev/null

iptables -t nat -C PREROUTING -p tcp --dport "${PORT_RANGE}" -j DNAT --to-destination "${ROS7_IP}" 2>/dev/null \
  || iptables -t nat -A PREROUTING -p tcp --dport "${PORT_RANGE}" -j DNAT --to-destination "${ROS7_IP}"

iptables -t nat -C PREROUTING -p udp --dport "${PORT_RANGE}" -j DNAT --to-destination "${ROS7_IP}" 2>/dev/null \
  || iptables -t nat -A PREROUTING -p udp --dport "${PORT_RANGE}" -j DNAT --to-destination "${ROS7_IP}"

iptables -C DOCKER-USER -m conntrack --ctstate RELATED,ESTABLISHED -j ACCEPT 2>/dev/null \
  || iptables -I DOCKER-USER 1 -m conntrack --ctstate RELATED,ESTABLISHED -j ACCEPT

iptables -C DOCKER-USER -p tcp -d "${ROS7_IP}" --dport "${PORT_RANGE}" -j ACCEPT 2>/dev/null \
  || iptables -I DOCKER-USER 2 -p tcp -d "${ROS7_IP}" --dport "${PORT_RANGE}" -j ACCEPT

iptables -C DOCKER-USER -p udp -d "${ROS7_IP}" --dport "${PORT_RANGE}" -j ACCEPT 2>/dev/null \
  || iptables -I DOCKER-USER 3 -p udp -d "${ROS7_IP}" --dport "${PORT_RANGE}" -j ACCEPT

# Docker 28+/29 per-bridge chains end with a terminal DROP in the DOCKER filter chain.
# Ensure the published range is ACCEPTed at the top of DOCKER before that DROP.
if command -v docker >/dev/null 2>&1; then
  CHR_BRIDGE="$(docker network inspect chr_net --format 'br-{{.Id | printf "%.12s"}}' 2>/dev/null || true)"
  if [[ -n "${CHR_BRIDGE}" ]]; then
    for proto in tcp udp; do
      iptables -C DOCKER -d "${ROS7_IP}/32" ! -i "${CHR_BRIDGE}" -o "${CHR_BRIDGE}" -p "${proto}" -m "${proto}" --dport "${PORT_RANGE}" -j ACCEPT 2>/dev/null \
        || iptables -I DOCKER 1 -d "${ROS7_IP}/32" ! -i "${CHR_BRIDGE}" -o "${CHR_BRIDGE}" -p "${proto}" -m "${proto}" --dport "${PORT_RANGE}" -j ACCEPT
    done
  fi
fi

echo "iptables rules ready for ${ROS7_IP} on ${PORT_RANGE}"
iptables -t nat -L PREROUTING -n -v | grep "${PORT_RANGE}" || true
iptables -L DOCKER-USER -n -v | grep "${ROS7_IP}" || true
