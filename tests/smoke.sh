#!/usr/bin/env bash
# v2 port: tests/smoke.sh (clean URLs)
# Dev server: php -S 127.0.0.1:8000 -t public public/router.php
# API smoke butuh server berjalan: BASE_URL=http://127.0.0.1:8000 bash tests/api_smoke.sh
set -euo pipefail

echo "[1/4] PHP lint"
find . -type f -name "*.php" -not -path "./vendor/*" -print0 | xargs -0 -n1 php -l >/dev/null

echo "[2/4] Helper tests"
php tests/helpers_test.php

echo "[3/4] Schema consistency tests"
php tests/schema_consistency_test.php

echo "[4/4] API contract checklist"
echo "- POST /api/push with invalid method should return 405"
echo "- POST /api/push with invalid token should return 403"
echo "- GET /api/status should return JSON array"
echo "- GET /api/health should return 200 (or 503 when degraded)"
echo "Smoke tests completed."
