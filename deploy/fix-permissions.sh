#!/usr/bin/env bash

set -euo pipefail

REPO_ROOT="${1:-/var/www/html/bot-mst}"
APP_USER="${APP_USER:-www-data}"
APP_GROUP="${APP_GROUP:-www-data}"

CORE_DIR="${REPO_ROOT}/core"
WORKER_DIR="${REPO_ROOT}/worker"

if [[ ! -d "${CORE_DIR}" ]]; then
  echo "Core directory not found: ${CORE_DIR}" >&2
  exit 1
fi

ensure_dir() {
  local target="$1"
  install -d -m 2775 -o "${APP_USER}" -g "${APP_GROUP}" "${target}"
}

apply_acl_if_available() {
  local target="$1"

  if ! command -v setfacl >/dev/null 2>&1; then
    return
  fi

  setfacl -R -m "u:${APP_USER}:rwx" -m "g:${APP_GROUP}:rwx" "${target}"
  setfacl -R -d -m "u:${APP_USER}:rwx" -m "g:${APP_GROUP}:rwx" "${target}"
}

ensure_dir "${CORE_DIR}/storage"
ensure_dir "${CORE_DIR}/storage/logs"
ensure_dir "${CORE_DIR}/bootstrap/cache"

touch \
  "${CORE_DIR}/storage/logs/laravel.log" \
  "${CORE_DIR}/storage/logs/operations.log" \
  "${CORE_DIR}/storage/logs/worker-remote.log"

chown -R "${APP_USER}:${APP_GROUP}" \
  "${CORE_DIR}/storage" \
  "${CORE_DIR}/bootstrap/cache"

chmod -R ug+rwX "${CORE_DIR}/storage" "${CORE_DIR}/bootstrap/cache"
find "${CORE_DIR}/storage" "${CORE_DIR}/bootstrap/cache" -type d -exec chmod 2775 {} \;

apply_acl_if_available "${CORE_DIR}/storage"
apply_acl_if_available "${CORE_DIR}/bootstrap/cache"

if [[ -d "${WORKER_DIR}" ]]; then
  ensure_dir "${WORKER_DIR}/.playwright"
  chown -R "${APP_USER}:${APP_GROUP}" "${WORKER_DIR}/.playwright"
  chmod -R ug+rwX "${WORKER_DIR}/.playwright"
  find "${WORKER_DIR}/.playwright" -type d -exec chmod 2775 {} \;
  apply_acl_if_available "${WORKER_DIR}/.playwright"
fi

echo "Permissions normalized for ${REPO_ROOT}"
