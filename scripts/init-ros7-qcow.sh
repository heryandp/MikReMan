#!/usr/bin/env bash
set -euo pipefail

ROOT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
RUNTIME_DIR="${ROOT_DIR}/runtime"
ROS7_DIR="${RUNTIME_DIR}/ros7"
MONITOR_DIR="${RUNTIME_DIR}/ros7-monitor"
IMAGE="${ROS7_IMAGE:-safrinnetwork/ros7:latest}"
QCOW_NAME="${ROS7_QCOW_NAME:-chr-7.15.3.qcow2}"
QCOW_PATH="${ROS7_DIR}/${QCOW_NAME}"

mkdir -p "${ROS7_DIR}" "${MONITOR_DIR}" "${ROOT_DIR}/config"

if [[ -f "${QCOW_PATH}" ]]; then
  echo "QCOW already exists: ${QCOW_PATH}"
  exit 0
fi

tmp_container="ros7-seed-$$"
trap 'docker rm -f "${tmp_container}" >/dev/null 2>&1 || true' EXIT

docker create --name "${tmp_container}" "${IMAGE}" >/dev/null
docker cp "${tmp_container}:/chr/${QCOW_NAME}" "${QCOW_PATH}"

echo "Extracted ${QCOW_NAME} to ${QCOW_PATH}"
