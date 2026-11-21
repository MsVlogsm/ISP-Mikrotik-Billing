#!/usr/bin/env bash
# Read-only production release-layout gate. Run as root from the production runner.
set -Eeuo pipefail

RELEASE_ROOT="/var/www/isp-billing-releases"
CURRENT="$RELEASE_ROOT/current"
SHARED_ROOT="/var/www/isp-billing-shared"
MODE_FILE="/etc/xlink-billing/deploy-mode"
DEPLOY_HELPER="/usr/local/sbin/xlink-billing-deploy"
EXPECTED_NGINX_ROOT="$CURRENT/public"

fail() {
    printf 'ERROR: %s\n' "$*" >&2
    exit 1
}

[[ $EUID -eq 0 ]] || fail "Run this preflight as root (sudo -n bash ops/verify-production-release-layout.sh)."

[[ -L "$CURRENT" ]] || fail "$CURRENT must be a symlink to the active immutable release."
ACTIVE_RELEASE="$(readlink -f -- "$CURRENT")"
[[ -n "$ACTIVE_RELEASE" && -d "$ACTIVE_RELEASE" ]] || fail "The current release symlink is broken."
case "$ACTIVE_RELEASE/" in
    "$RELEASE_ROOT"/*) ;;
    *) fail "The current release resolves outside $RELEASE_ROOT: $ACTIVE_RELEASE" ;;
esac
[[ -f "$ACTIVE_RELEASE/artisan" && -f "$ACTIVE_RELEASE/composer.lock" ]] || fail "The active target is not a complete Laravel release."
[[ -f "$ACTIVE_RELEASE/public/index.php" ]] || fail "Active release public/index.php is missing."

[[ -d "$SHARED_ROOT" ]] || fail "Shared runtime directory is missing: $SHARED_ROOT"
[[ -f "$SHARED_ROOT/.env" ]] || fail "Shared .env is missing."
[[ -d "$SHARED_ROOT/storage" ]] || fail "Shared storage directory is missing."
[[ -L "$ACTIVE_RELEASE/.env" ]] || fail "Active release .env must be linked to the shared runtime directory."
[[ -L "$ACTIVE_RELEASE/storage" ]] || fail "Active release storage must be linked to the shared runtime directory."
[[ "$(readlink -f -- "$ACTIVE_RELEASE/.env")" == "$SHARED_ROOT/.env" ]] || fail "Active release .env does not resolve to $SHARED_ROOT/.env."
[[ "$(readlink -f -- "$ACTIVE_RELEASE/storage")" == "$SHARED_ROOT/storage" ]] || fail "Active release storage does not resolve to $SHARED_ROOT/storage."

[[ -f "$MODE_FILE" ]] || fail "Release-aware root-helper marker is missing: $MODE_FILE"
[[ "$(cat "$MODE_FILE")" == "release-v1" ]] || fail "Unsupported deployment helper mode marker."
[[ "$(stat -c '%U' "$MODE_FILE")" == "root" ]] || fail "Deployment helper marker must be root-owned."
[[ -x "$DEPLOY_HELPER" ]] || fail "Root deployment helper is missing or not executable."
[[ "$(stat -c '%U' "$DEPLOY_HELPER")" == "root" ]] || fail "Root deployment helper must be root-owned."
HELPER_MODE="$(stat -c '%a' "$DEPLOY_HELPER")"
(( (8#$HELPER_MODE & 0022) == 0 )) || fail "Root deployment helper must not be group/world writable."

NGINX_DUMP="$(nginx -T 2>&1)" || fail "nginx -T failed; refusing deployment."
python3 - "$NGINX_DUMP" "$EXPECTED_NGINX_ROOT" <<'PY'
import re
import sys

config, expected_root = sys.argv[1], sys.argv[2]
blocks = re.findall(r"(?ms)^server\s*\{(.*?)^\}", config)
matches = []
for block in blocks:
    names = re.findall(r"(?m)^\s*server_name\s+([^;]+);", block)
    listens = re.findall(r"(?m)^\s*listen\s+([^;]+);", block)
    roots = re.findall(r"(?m)^\s*root\s+([^;]+);", block)
    if not any("bill.xlinkbd.net" in names_line.split() for names_line in names):
        continue
    if not any(re.match(r"\s*443(?:\s|$)", item) for item in listens):
        continue
    if roots and roots[-1] == expected_root and "/run/php/php8.5-fpm.sock" in block:
        matches.append(block)

if not matches:
    print(
        "ERROR: HTTPS bill.xlinkbd.net vhost must serve "
        f"{expected_root} through /run/php/php8.5-fpm.sock.",
        file=sys.stderr,
    )
    sys.exit(1)
PY

printf 'PASS: release layout verified\n'
printf 'Active release: %s\n' "$ACTIVE_RELEASE"
printf 'Nginx root: %s\n' "$EXPECTED_NGINX_ROOT"
printf 'Shared runtime: %s\n' "$SHARED_ROOT"
printf 'Deploy helper: root-owned, non-writable by group/others, release-v1\n'
