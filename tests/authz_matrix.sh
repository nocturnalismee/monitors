#!/usr/bin/env bash
# v2 port: tests/authz_matrix.sh (clean URLs)
# Dev server: php -S 127.0.0.1:8000 -t public public/router.php
# Usage: BASE_URL=http://127.0.0.1:8000 bash tests/authz_matrix.sh
set -euo pipefail

BASE_URL="${BASE_URL:-http://127.0.0.1:8000}"
ADMIN_USER="${ADMIN_USER:-admin}"
ADMIN_PASS="${ADMIN_PASS:-admin123}"
VIEWER_USER="${VIEWER_USER:-}"
VIEWER_PASS="${VIEWER_PASS:-admin123}"
if [[ -z "${VIEWER_USER}" ]]; then
  VIEWER_USER="support"
fi

COOKIE_ADMIN="$(mktemp)"
COOKIE_VIEWER="$(mktemp)"
trap 'rm -f "$COOKIE_ADMIN" "$COOKIE_VIEWER"' EXIT

extract_csrf() {
  sed -n 's/.*name="_csrf_token" value="\([^"]*\)".*/\1/p' | head -n1
}

login_user() {
  local username="$1"
  local password="$2"
  local cookie_file="$3"

  local login_html
  login_html="$(curl -sS -c "$cookie_file" "${BASE_URL}/login")"
  local csrf
  csrf="$(printf '%s' "$login_html" | extract_csrf)"
  if [[ -z "$csrf" ]]; then
    echo "Failed to extract CSRF token from login page"
    return 1
  fi

  local code
  code="$(curl -sS -o /dev/null -w "%{http_code}" -c "$cookie_file" -b "$cookie_file" \
    -X POST "${BASE_URL}/login" \
    -H "Content-Type: application/x-www-form-urlencoded" \
    --data-urlencode "_csrf_token=$csrf" \
    --data-urlencode "username=$username" \
    --data-urlencode "password=$password")"

  if [[ "$code" != "302" && "$code" != "303" ]]; then
    echo "Login failed for user '$username' (HTTP $code)"
    return 1
  fi
}

assert_admin_page_ok() {
  local path="$1"
  local code
  code="$(curl -sS -o /dev/null -w "%{http_code}" -b "$COOKIE_ADMIN" "${BASE_URL}${path}")"
  if [[ "$code" != "200" ]]; then
    echo "Admin expected 200 on ${path}, got ${code}"
    exit 1
  fi
}

assert_viewer_denied() {
  local path="$1"
  local headers
  headers="$(curl -sS -D - -o /dev/null -b "$COOKIE_VIEWER" "${BASE_URL}${path}")"
  local code
  code="$(printf '%s' "$headers" | awk 'toupper($1) ~ /^HTTP\// {print $2; exit}')"
  local location
  location="$(printf '%s' "$headers" | awk 'tolower($1)=="location:" {print $2}' | tr -d '\r' | head -n1)"

  if [[ "$code" != "302" && "$code" != "303" ]]; then
    echo "Viewer expected redirect on ${path}, got HTTP ${code}"
    exit 1
  fi

  if [[ "$location" != *"/dashboard"* ]]; then
    echo "Viewer expected redirect to dashboard on ${path}, got location '${location}'"
    exit 1
  fi
}

echo "Running AuthZ matrix on ${BASE_URL}"
login_user "$ADMIN_USER" "$ADMIN_PASS" "$COOKIE_ADMIN"

assert_admin_page_ok "/servers/add"
assert_admin_page_ok "/servers/1/edit"
assert_admin_page_ok "/settings"
assert_admin_page_ok "/export"

if [[ -n "$VIEWER_USER" && -n "$VIEWER_PASS" ]]; then
  login_user "$VIEWER_USER" "$VIEWER_PASS" "$COOKIE_VIEWER"
  assert_viewer_denied "/servers/add"
  assert_viewer_denied "/servers/1/edit"
  assert_viewer_denied "/settings"
  assert_viewer_denied "/export"
else
  echo "Viewer credentials are not set; viewer AuthZ checks skipped."
  echo "Set VIEWER_USER and VIEWER_PASS to validate non-admin restrictions."
fi

echo "authz_matrix passed"
