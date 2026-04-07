#!/usr/bin/env bash
set -euo pipefail

# Cron-friendly wrapper:
# - runs full maintenance
# - appends runlog entry
# - keeps stdout concise for cron logs

ROOT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
cd "$ROOT_DIR"

TAG="$(date +%Y%m%d-%H%M%S)"

python3 tools/run_lv3_maintenance.py \
  --mode full \
  --tag "daily-${TAG}" \
  --append-runlog \
  --runlog-retention-days 90 \
  --runlog-max-lines 5000

python3 tools/maintenance_health_check.py \
  --max-age-hours 24 \
  --max-future-skew-seconds 300 \
  --require-latest-pass \
  --require-zero-critical \
  --min-coverage-ratio 1.0 \
  --emit-pass-alert

python3 tools/render_maintenance_dashboard.py \
  --out-md ".m/reclass/maintenance-dashboard.md" \
  --out-csv ".m/reclass/maintenance-dashboard.csv"
