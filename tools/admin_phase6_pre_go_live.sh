#!/usr/bin/env bash
set -euo pipefail

ROOT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
cd "$ROOT_DIR"

echo "[1/4] Static pre-go-live checker (Python)"
python3 tools/admin_phase6_pre_go_live_check.py --format text

echo
echo "[2/4] Site readiness audit (output captured)"
python3 tools/site_html_readiness_audit.py > /tmp/admin-phase6-site-readiness.txt
echo "Saved readiness report: /tmp/admin-phase6-site-readiness.txt"
echo "Top lines:"
sed -n '1,30p' /tmp/admin-phase6-site-readiness.txt

echo
echo "[3/4] Suggested PHP healthcheck (manual if PHP exists)"
if command -v php >/dev/null 2>&1; then
  php admin/includes/healthcheck.php
else
  echo "php CLI not found in this environment. Skip runtime healthcheck."
fi

echo
echo "[4/4] Done."
echo "If all checks above are OK, internal go-live is ready."
