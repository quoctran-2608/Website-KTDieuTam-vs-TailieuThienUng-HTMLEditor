#!/usr/bin/env bash
set -euo pipefail

# Cron-friendly wrapper:
# - runs one-shot moderation ops
# - appends runlog
# - keeps stdout as one JSON payload

ROOT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
cd "$ROOT_DIR"

python3 tools/run_moderation_ops.py \
  --limit 30 \
  --append-runlog \
  --runlog-retention-days 90 \
  --runlog-max-lines 5000
