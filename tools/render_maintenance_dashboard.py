#!/usr/bin/env python3
from __future__ import annotations

import argparse
import json
import csv
from collections import Counter
from datetime import datetime
from pathlib import Path
from typing import Dict, List


REPO_ROOT = Path(__file__).resolve().parents[1]
DEFAULT_RUNLOG = REPO_ROOT / ".m" / "reclass" / "maintenance-runlog.jsonl"
DEFAULT_MD = REPO_ROOT / ".m" / "reclass" / "maintenance-dashboard.md"
DEFAULT_CSV = REPO_ROOT / ".m" / "reclass" / "maintenance-dashboard.csv"


def parse_args() -> argparse.Namespace:
    parser = argparse.ArgumentParser(description="Render markdown dashboard from maintenance-runlog.jsonl")
    parser.add_argument("--runlog-path", default=str(DEFAULT_RUNLOG), help="Input runlog jsonl path")
    parser.add_argument("--out-md", default=str(DEFAULT_MD), help="Output markdown path")
    parser.add_argument("--out-csv", default=str(DEFAULT_CSV), help="Output csv path")
    parser.add_argument("--limit", type=int, default=30, help="Max latest records in detailed table")
    return parser.parse_args()


def load_records(runlog_path: Path) -> List[Dict]:
    if not runlog_path.exists():
        return []
    records: List[Dict] = []
    for line in runlog_path.read_text(encoding="utf-8", errors="ignore").splitlines():
        text = line.strip()
        if not text:
            continue
        try:
            rec = json.loads(text)
            rec["_dt"] = datetime.strptime(rec.get("time", ""), "%Y-%m-%d %H:%M:%S")
            records.append(rec)
        except Exception:
            continue
    records.sort(key=lambda r: r["_dt"])
    return records


def render_markdown(records: List[Dict], runlog_path: Path, limit: int) -> str:
    now = datetime.now().strftime("%Y-%m-%d %H:%M:%S")
    lines = [
        "# Maintenance Dashboard",
        "",
        f"- Generated at: {now}",
        f"- Source runlog: `{runlog_path}`",
        f"- Valid records: **{len(records)}**",
    ]

    if not records:
        lines.extend(["", "_No records found._", ""])
        return "\n".join(lines)

    latest = records[-1]
    age_seconds = int((datetime.now() - latest["_dt"]).total_seconds())
    anomaly_records = [r for r in records if int((datetime.now() - r["_dt"]).total_seconds()) < 0]
    status_counter = Counter((r.get("status") or "UNKNOWN").upper() for r in records)
    severity_counter = Counter((r.get("severity") or "UNKNOWN").upper() for r in records)

    lines.extend(
        [
            "",
            "## Latest run",
            f"- Time: `{latest.get('time')}`",
            f"- Status: **{latest.get('status')}**",
            f"- Age (seconds): `{age_seconds}`",
            f"- Section: `{latest.get('section')}`",
            f"- Coverage: `{latest.get('withLv3')}/{latest.get('libraryTotal')}`",
            f"- Critical: `{json.dumps(latest.get('criticalIssues', {}), ensure_ascii=False)}`",
            "",
            "## Status distribution",
            "",
            "| Status | Count |",
            "|---|---:|",
        ]
    )
    for key, val in sorted(status_counter.items(), key=lambda kv: (-kv[1], kv[0])):
        lines.append(f"| {key} | {val} |")

    lines.extend(
        [
            "",
            "## Severity distribution",
            "",
            "| Severity | Count |",
            "|---|---:|",
        ]
    )
    for key, val in sorted(severity_counter.items(), key=lambda kv: (-kv[1], kv[0])):
        lines.append(f"| {key} | {val} |")

    severity_missing = sum(1 for r in records if not (r.get("severity") or "").strip())
    if severity_missing:
        lines.append("")
        lines.append(f"- Records thiếu trường `severity`: **{severity_missing}**")

    lines.extend(
        [
            "",
            "## Time anomalies",
            f"- Records with future timestamp (`age_seconds < 0`): **{len(anomaly_records)}**",
        ]
    )
    if anomaly_records:
        lines.extend(["", "| Time | Status | Age seconds | Section |", "|---|---|---:|---|"])
        for rec in reversed(anomaly_records[-min(limit, len(anomaly_records)):]):
            age = int((datetime.now() - rec["_dt"]).total_seconds())
            lines.append(f"| {rec.get('time')} | {rec.get('status')} | {age} | {rec.get('section')} |")

    lines.extend(
        [
            "",
            f"## Latest {min(limit, len(records))} records",
            "",
            "| Time | Status | Section | Coverage | Critical issues | Json artifact |",
            "|---|---|---|---|---|---|",
        ]
    )

    for rec in reversed(records[-limit:]):
        critical = json.dumps(rec.get("criticalIssues", {}), ensure_ascii=False).replace("|", "/")
        artifacts = rec.get("artifacts", {}) or {}
        json_art = str(artifacts.get("json", "")).replace("|", "/")
        coverage = f"{rec.get('withLv3', '-')}/{rec.get('libraryTotal', '-')}"
        lines.append(
            f"| {rec.get('time')} | {rec.get('status')} | {rec.get('section')} | {coverage} | `{critical}` | `{json_art}` |"
        )

    lines.append("")
    return "\n".join(lines)


def write_csv(records: List[Dict], out_csv: Path, limit: int) -> None:
    out_csv.parent.mkdir(parents=True, exist_ok=True)
    with out_csv.open("w", encoding="utf-8", newline="") as fh:
        writer = csv.writer(fh)
        writer.writerow(
            [
                "time",
                "status",
                "severity",
                "section",
                "withLv3",
                "libraryTotal",
                "coverageRatio",
                "ageSeconds",
                "futureTimestamp",
                "missingLv3",
                "nodeGaps",
                "duplicateHref",
                "lv3KeyMultiLabel",
                "lv3LabelMultiKey",
                "jsonArtifact",
                "mdArtifact",
                "buildLog",
            ]
        )
        for rec in reversed(records[-limit:]):
            critical = rec.get("criticalIssues", {}) or {}
            artifacts = rec.get("artifacts", {}) or {}
            with_lv3 = rec.get("withLv3") or 0
            total = rec.get("libraryTotal") or 0
            try:
                coverage_ratio = (float(with_lv3) / float(total)) if float(total) > 0 else 0.0
            except Exception:
                coverage_ratio = 0.0
            age_seconds = int((datetime.now() - rec.get("_dt", datetime.now())).total_seconds())
            writer.writerow(
                [
                    rec.get("time", ""),
                    rec.get("status", ""),
                    rec.get("severity", ""),
                    rec.get("section", ""),
                    with_lv3,
                    total,
                    f"{coverage_ratio:.6f}",
                    age_seconds,
                    "1" if age_seconds < 0 else "0",
                    critical.get("missingLv3", ""),
                    critical.get("nodeGaps", ""),
                    critical.get("duplicateHref", ""),
                    critical.get("lv3KeyMultiLabel", ""),
                    critical.get("lv3LabelMultiKey", ""),
                    artifacts.get("json", ""),
                    artifacts.get("md", ""),
                    artifacts.get("buildLog", ""),
                ]
            )


def main() -> int:
    args = parse_args()
    runlog_path = Path(args.runlog_path)
    out_md = Path(args.out_md)
    out_csv = Path(args.out_csv)

    records = load_records(runlog_path)
    md = render_markdown(records=records, runlog_path=runlog_path, limit=max(1, args.limit))
    out_md.parent.mkdir(parents=True, exist_ok=True)
    out_md.write_text(md, encoding="utf-8")
    write_csv(records=records, out_csv=out_csv, limit=max(1, args.limit))

    print(f"records={len(records)}")
    print(f"out_md={out_md}")
    print(f"out_csv={out_csv}")
    if records:
        print(f"latest_time={records[-1].get('time')}")
        print(f"latest_status={records[-1].get('status')}")
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
