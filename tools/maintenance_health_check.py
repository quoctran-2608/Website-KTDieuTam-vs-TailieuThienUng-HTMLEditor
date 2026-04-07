#!/usr/bin/env python3
from __future__ import annotations

import argparse
import json
from datetime import datetime, timedelta
from pathlib import Path
from typing import Dict, List


REPO_ROOT = Path(__file__).resolve().parents[1]
DEFAULT_RUNLOG = REPO_ROOT / ".m" / "reclass" / "maintenance-runlog.jsonl"


def parse_args() -> argparse.Namespace:
    parser = argparse.ArgumentParser(description="Check maintenance runlog freshness and PASS health.")
    parser.add_argument("--runlog-path", default=str(DEFAULT_RUNLOG), help="Path to maintenance-runlog.jsonl")
    parser.add_argument("--max-age-hours", type=int, default=24, help="Alert if latest run older than this threshold.")
    parser.add_argument("--require-latest-pass", action="store_true", help="Fail if latest run status is not PASS.")
    parser.add_argument(
        "--require-zero-critical",
        action="store_true",
        help="Fail if latest criticalIssues has any value > 0, even when latest status is PASS.",
    )
    parser.add_argument(
        "--min-coverage-ratio",
        type=float,
        default=None,
        help="Fail if latest run coverage ratio (withLv3/libraryTotal) is below this value.",
    )
    parser.add_argument(
        "--max-future-skew-seconds",
        type=int,
        default=300,
        help="Fail if latest run timestamp is in the future by more than this threshold.",
    )
    parser.add_argument(
        "--max-negative-age-seconds",
        type=int,
        default=None,
        help=(
            "Alias for future skew guard. "
            "If set, fail when latest_age_seconds < -max_negative_age_seconds."
        ),
    )
    parser.add_argument(
        "--alert-prefix",
        default="MAINTENANCE_ALERT",
        help="Prefix text for one-line alert template.",
    )
    parser.add_argument(
        "--emit-pass-alert",
        action="store_true",
        help="Also emit one-line alert template when status PASS.",
    )
    return parser.parse_args()


def parse_line(line: str) -> Dict | None:
    text = line.strip()
    if not text:
        return None
    try:
        rec = json.loads(text)
        ts = datetime.strptime(rec.get("time", ""), "%Y-%m-%d %H:%M:%S")
        rec["_dt"] = ts
        return rec
    except Exception:
        return None


def emit_alert_line(
    *,
    prefix: str,
    status: str,
    severity: str,
    reason: str,
    runlog_path: Path,
    latest_time: str,
    latest_status: str,
    latest_age_seconds: int,
    latest_future_skew_seconds: int,
    latest_coverage_ratio: float,
    latest_critical_total: int,
) -> None:
    # One-line template for grep/alerting systems.
    print(
        "alert="
        + "|".join(
            [
                prefix,
                f"status={status}",
                f"severity={severity}",
                f"reason={reason}",
                f"runlog={runlog_path}",
                f"latest_time={latest_time}",
                f"latest_status={latest_status}",
                f"latest_age_seconds={latest_age_seconds}",
                f"latest_future_skew_seconds={latest_future_skew_seconds}",
                f"latest_coverage_ratio={latest_coverage_ratio:.6f}",
                f"latest_critical_total={latest_critical_total}",
            ]
        )
    )


def classify_severity(
    *,
    status: str,
    latest_age_seconds: int,
    future_skew_seconds: int,
    invalid_lines: int,
    latest_critical_total: int,
    max_age_hours: int,
) -> str:
    normalized = (status or "").upper()
    if normalized == "FAIL":
        return "CRIT"
    if future_skew_seconds > 0:
        return "WARN"
    if invalid_lines > 0:
        return "WARN"
    if latest_critical_total > 0:
        return "WARN"

    warn_age_seconds = int(max(0, max_age_hours) * 3600 * 0.8)
    if warn_age_seconds > 0 and latest_age_seconds >= warn_age_seconds:
        return "WARN"
    return "INFO"


def main() -> int:
    args = parse_args()
    runlog_path = Path(args.runlog_path)
    if not runlog_path.exists():
        print(f"status=FAIL")
        print("severity=CRIT")
        print(f"reason=runlog_not_found")
        print(f"runlog={runlog_path}")
        emit_alert_line(
            prefix=args.alert_prefix,
            status="FAIL",
            severity="CRIT",
            reason="runlog_not_found",
            runlog_path=runlog_path,
            latest_time="",
            latest_status="",
            latest_age_seconds=0,
            latest_future_skew_seconds=0,
            latest_coverage_ratio=0.0,
            latest_critical_total=0,
        )
        return 2

    lines = runlog_path.read_text(encoding="utf-8", errors="ignore").splitlines()
    records: List[Dict] = []
    invalid_count = 0
    for line in lines:
        rec = parse_line(line)
        if rec is None:
            invalid_count += 1
            continue
        records.append(rec)

    if not records:
        print("status=FAIL")
        print("severity=CRIT")
        print("reason=no_valid_records")
        print(f"runlog={runlog_path}")
        print(f"invalid_lines={invalid_count}")
        emit_alert_line(
            prefix=args.alert_prefix,
            status="FAIL",
            severity="CRIT",
            reason="no_valid_records",
            runlog_path=runlog_path,
            latest_time="",
            latest_status="",
            latest_age_seconds=0,
            latest_future_skew_seconds=0,
            latest_coverage_ratio=0.0,
            latest_critical_total=0,
        )
        return 2

    records.sort(key=lambda r: r["_dt"])
    latest = records[-1]
    now = datetime.now()
    age = now - latest["_dt"]
    stale = age > timedelta(hours=max(0, args.max_age_hours))
    future_skew_seconds = max(0, int((latest["_dt"] - now).total_seconds()))
    future_skew_threshold = (
        max(0, int(args.max_negative_age_seconds))
        if args.max_negative_age_seconds is not None
        else max(0, args.max_future_skew_seconds)
    )
    future_skew_exceeded = future_skew_seconds > future_skew_threshold
    latest_pass = (latest.get("status") or "").upper() == "PASS"
    latest_with = int(latest.get("withLv3") or 0)
    latest_total = int(latest.get("libraryTotal") or 0)
    latest_coverage_ratio = (latest_with / latest_total) if latest_total > 0 else 0.0
    latest_critical = latest.get("criticalIssues", {}) or {}
    latest_critical_total = 0
    for value in latest_critical.values():
        try:
            latest_critical_total += int(value)
        except Exception:
            # If value is malformed, treat it as a critical anomaly.
            latest_critical_total += 1
    coverage_below_threshold = (
        args.min_coverage_ratio is not None and latest_coverage_ratio < float(args.min_coverage_ratio)
    )

    if future_skew_exceeded:
        status = "FAIL"
        reason = "future_timestamp_skew"
        code = 2
    elif stale:
        status = "FAIL"
        reason = "stale_latest_run"
        code = 2
    elif args.require_latest_pass and not latest_pass:
        status = "FAIL"
        reason = "latest_not_pass"
        code = 2
    elif coverage_below_threshold:
        status = "FAIL"
        reason = "coverage_below_threshold"
        code = 2
    elif args.require_zero_critical and latest_critical_total > 0:
        status = "FAIL"
        reason = "critical_issues_detected"
        code = 2
    else:
        status = "PASS"
        reason = "ok"
        code = 0

    latest_age_seconds = int(age.total_seconds())
    severity = classify_severity(
        status=status,
        latest_age_seconds=latest_age_seconds,
        future_skew_seconds=future_skew_seconds,
        invalid_lines=invalid_count,
        latest_critical_total=latest_critical_total,
        max_age_hours=int(args.max_age_hours),
    )

    print(f"status={status}")
    print(f"severity={severity}")
    print(f"reason={reason}")
    print(f"runlog={runlog_path}")
    print(f"records={len(records)}")
    print(f"invalid_lines={invalid_count}")
    print(f"latest_time={latest.get('time')}")
    print(f"latest_status={latest.get('status')}")
    print(f"latest_age_seconds={latest_age_seconds}")
    print(f"latest_future_skew_seconds={future_skew_seconds}")
    print(f"max_future_skew_seconds={future_skew_threshold}")
    if args.max_negative_age_seconds is not None:
        print(f"max_negative_age_seconds={max(0, int(args.max_negative_age_seconds))}")
    print(f"latest_coverage_ratio={latest_coverage_ratio:.6f}")
    if args.min_coverage_ratio is not None:
        print(f"required_min_coverage_ratio={float(args.min_coverage_ratio):.6f}")
    print(f"latest_critical={json.dumps(latest_critical, ensure_ascii=False)}")
    print(f"latest_critical_total={latest_critical_total}")

    if status == "FAIL" or args.emit_pass_alert:
        emit_alert_line(
            prefix=args.alert_prefix,
            status=status,
            severity=severity,
            reason=reason,
            runlog_path=runlog_path,
            latest_time=str(latest.get("time", "")),
            latest_status=str(latest.get("status", "")),
            latest_age_seconds=latest_age_seconds,
            latest_future_skew_seconds=future_skew_seconds,
            latest_coverage_ratio=latest_coverage_ratio,
            latest_critical_total=latest_critical_total,
        )
    return code


if __name__ == "__main__":
    raise SystemExit(main())
