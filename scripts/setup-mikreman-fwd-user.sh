#!/usr/bin/env bash
set -euo pipefail

USER_NAME="${MIKREMAN_FWD_USER:-mikreman-fwd}"
USER_HOME="/home/${USER_NAME}"
SSH_DIR="${USER_HOME}/.ssh"
AUTHORIZED_KEYS="${SSH_DIR}/authorized_keys"
SOCAT_BINARY="${SOCAT_BINARY:-/usr/bin/socat}"

usage() {
  cat <<EOF
Usage:
  sudo ./scripts/setup-mikreman-fwd-user.sh --pubkey-file /path/to/key.pub
  sudo ./scripts/setup-mikreman-fwd-user.sh --pubkey "ssh-ed25519 AAAA... comment"

Optional env:
  MIKREMAN_FWD_USER   Default: mikreman-fwd
  SOCAT_BINARY        Default: /usr/bin/socat
EOF
}

if [[ "${EUID}" -ne 0 ]]; then
  echo "This script must run as root." >&2
  exit 1
fi

PUBKEY=""

while [[ $# -gt 0 ]]; do
  case "$1" in
    --pubkey-file)
      shift
      [[ $# -gt 0 ]] || { echo "--pubkey-file requires a path" >&2; exit 1; }
      [[ -f "$1" ]] || { echo "Public key file not found: $1" >&2; exit 1; }
      PUBKEY="$(tr -d '\r' < "$1")"
      shift
      ;;
    --pubkey)
      shift
      [[ $# -gt 0 ]] || { echo "--pubkey requires a value" >&2; exit 1; }
      PUBKEY="$(printf '%s' "$1" | tr -d '\r')"
      shift
      ;;
    -h|--help)
      usage
      exit 0
      ;;
    *)
      echo "Unknown argument: $1" >&2
      usage >&2
      exit 1
      ;;
  esac
done

if [[ -z "${PUBKEY}" ]]; then
  echo "A public key is required." >&2
  usage >&2
  exit 1
fi

if [[ "${PUBKEY}" != ssh-* ]]; then
  echo "Public key does not look valid. Expected line starting with ssh-." >&2
  exit 1
fi

if ! id "${USER_NAME}" >/dev/null 2>&1; then
  useradd -m -s /bin/bash "${USER_NAME}"
fi

mkdir -p "${SSH_DIR}"
chmod 700 "${SSH_DIR}"
touch "${AUTHORIZED_KEYS}"

if ! grep -Fqx "${PUBKEY}" "${AUTHORIZED_KEYS}"; then
  printf '%s\n' "${PUBKEY}" >> "${AUTHORIZED_KEYS}"
fi

chmod 600 "${AUTHORIZED_KEYS}"
chown -R "${USER_NAME}:${USER_NAME}" "${SSH_DIR}"

if command -v "${SOCAT_BINARY}" >/dev/null 2>&1; then
  SOCAT_STATUS="found"
else
  SOCAT_STATUS="missing"
fi

USER_GID="$(id -g "${USER_NAME}")"

cat <<EOF
Setup complete.

User:
  ${USER_NAME}

SSH directory:
  ${SSH_DIR}

Authorized keys:
  ${AUTHORIZED_KEYS}

socat:
  ${SOCAT_BINARY} (${SOCAT_STATUS})

ROS7_MONITOR_GID:
  ${USER_GID}

Next:
  ROS7_MONITOR_USER=${USER_NAME} ./scripts/recreate-ros7.sh
EOF
