#!/usr/bin/env sh
set -eu

MONITOR_DIR="${ROS7_MONITOR_DIR:-/run/qemu}"
DISK_PATH="${ROS7_DISK_PATH:-/chr/chr-7.15.3.qcow2}"
MONITOR_GID="${ROS7_MONITOR_GID:-0}"
MONITOR_MODE="${ROS7_MONITOR_MODE:-660}"

QEMU_ARGS="
  -m 256M
  -smp 1
  -hda ${DISK_PATH}
  -nographic
  -monitor unix:${MONITOR_DIR}/hmp.sock,server,nowait
  -qmp unix:${MONITOR_DIR}/qmp.sock,server,wait=off
  -nic user,hostfwd=tcp::8291-:8291,hostfwd=tcp::80-:80,hostfwd=tcp::443-:443,hostfwd=tcp::22-:22,hostfwd=tcp::23-:23,hostfwd=tcp::21-:21,hostfwd=udp::53-:53,hostfwd=tcp::53-:53,hostfwd=udp::123-:123,hostfwd=tcp::8728-:8728,hostfwd=tcp::8729-:8729,hostfwd=tcp::2210-:2210,hostfwd=tcp::179-:179,hostfwd=tcp::8292-:8292,hostfwd=udp::1194-:1194,hostfwd=tcp::1194-:1194,hostfwd=udp::13231-:13231,hostfwd=udp::1701-:1701,hostfwd=tcp::1723-:1723,hostfwd=udp::500-:500,hostfwd=udp::4500-:4500,hostfwd=tcp::50-:50,hostfwd=tcp::51-:51,hostfwd=udp::1812-:1812,hostfwd=udp::1813-:1813
"

mkdir -p "${MONITOR_DIR}"
rm -f "${MONITOR_DIR}/hmp.sock" "${MONITOR_DIR}/qmp.sock"

qemu-system-x86_64 ${QEMU_ARGS} &
QEMU_PID=$!

for _ in $(seq 1 30); do
  if [ -S "${MONITOR_DIR}/hmp.sock" ] && [ -S "${MONITOR_DIR}/qmp.sock" ]; then
    chmod "${MONITOR_MODE}" "${MONITOR_DIR}/hmp.sock" "${MONITOR_DIR}/qmp.sock" || true
    if [ "${MONITOR_GID}" != "0" ]; then
      chgrp "${MONITOR_GID}" "${MONITOR_DIR}/hmp.sock" "${MONITOR_DIR}/qmp.sock" || true
    fi
    break
  fi
  sleep 1
done

wait "${QEMU_PID}"
