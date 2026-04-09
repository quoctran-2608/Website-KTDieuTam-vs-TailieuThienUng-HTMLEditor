#!/usr/bin/env python3
from __future__ import annotations

import argparse
import json
import subprocess
import sys
from datetime import datetime, timedelta, timezone
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
DEFAULT_AUDIT_REPORT = ROOT / "docs" / "nghien-cuu-tuyen-dung" / "bao-cao-audit-moderation-events.md"
DEFAULT_DASHBOARD_MD = ROOT / "docs" / "nghien-cuu-tuyen-dung" / "dashboard-moderation-events.md"
DEFAULT_DASHBOARD_JSON = ROOT / "docs" / "nghien-cuu-tuyen-dung" / "dashboard-moderation-events.json"
DEFAULT_RUNLOG = ROOT / ".m" / "reclass" / "moderation-ops-runlog.jsonl"


def parse_args() -> argparse.Namespace:
    parser = argparse.ArgumentParser(
        description="One-shot moderation ops: audit log + render dashboard + trả trạng thái PASS/ALERT"
    )
    parser.add_argument("--day", default="", help="Lọc ngày UTC theo YYYY-MM-DD")
    parser.add_argument("--actor", default="", help="Lọc actor")
    parser.add_argument("--limit", type=int, default=30, help="Giới hạn event mới nhất cho audit/dashboard")
    parser.add_argument("--fail-on-no-events", action="store_true", help="Bật ALERT nếu không có event sau lọc")
    parser.add_argument("--audit-report", default=str(DEFAULT_AUDIT_REPORT), help="Đường dẫn audit report markdown")
    parser.add_argument("--dashboard-md", default=str(DEFAULT_DASHBOARD_MD), help="Đường dẫn dashboard markdown")
    parser.add_argument("--dashboard-json", default=str(DEFAULT_DASHBOARD_JSON), help="Đường dẫn dashboard json")
    parser.add_argument("--append-runlog", action="store_true", help="Append kết quả run vào runlog jsonl")
    parser.add_argument("--runlog-path", default=str(DEFAULT_RUNLOG), help="Đường dẫn runlog jsonl")
    parser.add_argument("--runlog-retention-days", type=int, default=90, help="Giữ runlog trong N ngày")
    parser.add_argument("--runlog-max-lines", type=int, default=5000, help="Giữ tối đa N dòng runlog")
    parser.add_argument("--skip-runlog-rotate", action="store_true", help="Bỏ qua bước rotate/prune runlog")
    return parser.parse_args()


def run_command(cmd: list[str]) -> subprocess.CompletedProcess[str]:
    return subprocess.run(cmd, cwd=ROOT, capture_output=True, text=True)


def parse_audit_stats(stdout: str) -> dict:
    # audit tool in cuối stdout một JSON object
    lines = [line.strip() for line in stdout.splitlines() if line.strip()]
    for line in reversed(lines):
        if line.startswith("{") and line.endswith("}"):
            return json.loads(line)
    return {"events_total": 0, "events_filtered": 0, "issues": 0}


def parse_iso_utc(value: str) -> datetime:
    return datetime.fromisoformat(value.replace("Z", "+00:00"))


def append_runlog_entry(runlog_path: Path, payload: dict) -> None:
    runlog_path.parent.mkdir(parents=True, exist_ok=True)
    with runlog_path.open("a", encoding="utf-8") as handle:
        handle.write(json.dumps(payload, ensure_ascii=False) + "\n")


def rotate_runlog(runlog_path: Path, retention_days: int, max_lines: int) -> dict:
    if not runlog_path.exists():
        return {
            "existed": False,
            "inputLines": 0,
            "outputLines": 0,
            "removedByAge": 0,
            "removedByCap": 0,
            "invalidLinesKept": 0,
        }

    raw_lines = runlog_path.read_text(encoding="utf-8", errors="ignore").splitlines()
    if not raw_lines:
        return {
            "existed": True,
            "inputLines": 0,
            "outputLines": 0,
            "removedByAge": 0,
            "removedByCap": 0,
            "invalidLinesKept": 0,
        }

    now = datetime.now(timezone.utc)
    cutoff = now - timedelta(days=max(0, retention_days))
    kept: list[str] = []
    removed_by_age = 0
    invalid_kept = 0

    for line in raw_lines:
        text = line.strip()
        if not text:
            continue
        try:
            rec = json.loads(text)
            ts = str(rec.get("generatedAtUtc", "")).strip()
            dt = parse_iso_utc(ts)
            if dt >= cutoff:
                kept.append(text)
            else:
                removed_by_age += 1
        except Exception:
            # Giữ lại line lỗi parse để tránh mất trace.
            kept.append(text)
            invalid_kept += 1

    removed_by_cap = 0
    if len(kept) > max(1, max_lines):
        removed_by_cap = len(kept) - max(1, max_lines)
        kept = kept[-max(1, max_lines) :]

    output = "\n".join(kept) + ("\n" if kept else "")
    runlog_path.write_text(output, encoding="utf-8")
    return {
        "existed": True,
        "inputLines": len(raw_lines),
        "outputLines": len(kept),
        "removedByAge": removed_by_age,
        "removedByCap": removed_by_cap,
        "invalidLinesKept": invalid_kept,
    }


def main() -> int:
    args = parse_args()
    limit = max(1, args.limit)
    audit_report = Path(args.audit_report)
    dashboard_md = Path(args.dashboard_md)
    dashboard_json = Path(args.dashboard_json)
    runlog_path = Path(args.runlog_path)

    audit_cmd = [sys.executable, "tools/audit_moderation_events.py", "--limit", str(limit)]
    render_cmd = [
        sys.executable,
        "tools/render_moderation_dashboard.py",
        "--limit",
        str(limit),
        "--out-md",
        str(dashboard_md),
        "--out-json",
        str(dashboard_json),
    ]
    if args.day:
        audit_cmd.extend(["--day", args.day])
        render_cmd.extend(["--day", args.day])
    if args.actor:
        audit_cmd.extend(["--actor", args.actor])
        render_cmd.extend(["--actor", args.actor])

    audit_proc = run_command(audit_cmd)
    render_proc = run_command(render_cmd)

    audit_stats = parse_audit_stats(audit_proc.stdout)
    dashboard_summary: dict = {}
    if dashboard_json.exists():
        dashboard_summary = json.loads(dashboard_json.read_text(encoding="utf-8"))

    reasons: list[str] = []
    if audit_proc.returncode != 0:
        reasons.append("audit_command_failed")
    if render_proc.returncode != 0:
        reasons.append("render_command_failed")
    if int(audit_stats.get("issues", 0)) > 0:
        reasons.append("audit_issues_detected")
    if int(dashboard_summary.get("parseErrors", 0)) > 0:
        reasons.append("dashboard_parse_errors")
    if int(dashboard_summary.get("failedCount", 0)) > 0:
        reasons.append("failed_events_detected")
    if args.fail_on_no_events and int(dashboard_summary.get("eventsFiltered", 0)) == 0:
        reasons.append("no_events_after_filter")

    status = "PASS" if not reasons else "ALERT"
    payload = {
        "generatedAtUtc": datetime.now(timezone.utc).replace(microsecond=0).isoformat().replace("+00:00", "Z"),
        "status": status,
        "reasons": reasons,
        "filters": {
            "day": args.day,
            "actor": args.actor,
            "limit": limit,
        },
        "audit": {
            "command": " ".join(audit_cmd),
            "returncode": audit_proc.returncode,
            "stats": audit_stats,
            "reportPath": str(audit_report),
        },
        "dashboard": {
            "command": " ".join(render_cmd),
            "returncode": render_proc.returncode,
            "summary": dashboard_summary,
            "markdownPath": str(dashboard_md),
            "jsonPath": str(dashboard_json),
        },
    }

    if args.append_runlog:
        append_runlog_entry(runlog_path, payload)
        payload["runlog"] = {
            "path": str(runlog_path),
            "appended": True,
        }
        if not args.skip_runlog_rotate:
            payload["runlog"]["rotation"] = rotate_runlog(
                runlog_path=runlog_path,
                retention_days=args.runlog_retention_days,
                max_lines=args.runlog_max_lines,
            )
    else:
        payload["runlog"] = {"path": str(runlog_path), "appended": False}

    print(json.dumps(payload, ensure_ascii=False, indent=2))

    if status == "PASS":
        return 0
    return 2


if __name__ == "__main__":
    raise SystemExit(main())
