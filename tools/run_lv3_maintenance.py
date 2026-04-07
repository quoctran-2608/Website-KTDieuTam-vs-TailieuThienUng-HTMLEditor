#!/usr/bin/env python3
from __future__ import annotations

import argparse
import json
import subprocess
import sys
from collections import Counter, defaultdict
from datetime import datetime, timedelta
from pathlib import Path
from typing import Dict, List


REPO_ROOT = Path(__file__).resolve().parents[1]
WORK_ROOT = REPO_ROOT.parent
DEFAULT_BUILDER = WORK_ROOT / ".m" / "build_sample_sections.py"
DEFAULT_ARTICLES = REPO_ROOT / "data" / "articles.json"
DEFAULT_RUNLOG = REPO_ROOT / ".m" / "reclass" / "maintenance-runlog.jsonl"


def parse_args() -> argparse.Namespace:
    parser = argparse.ArgumentParser(
        description="Run maintenance QA snapshot for Lv3 taxonomy (optionally rebuild first)."
    )
    parser.add_argument("--mode", choices=["sample", "full"], default="full", help="Build mode when rebuild is enabled.")
    parser.add_argument("--skip-build", action="store_true", help="Skip rebuild and only run QA on existing data/articles.json.")
    parser.add_argument("--tag", default=datetime.now().strftime("%Y%m%d-%H%M%S"), help="Tag suffix for output artifacts.")
    parser.add_argument("--out-dir", default=str(REPO_ROOT / ".m" / "reclass"), help="Directory for logs/snapshots.")
    parser.add_argument("--section", default="thu-vien", help="Section key to audit (default: thu-vien).")
    parser.add_argument("--top-limit", type=int, default=30, help="Top lv3 labels to include in summary.")
    parser.add_argument("--sample-limit", type=int, default=20, help="Max sample rows per issue bucket.")
    parser.add_argument("--builder-path", default=str(DEFAULT_BUILDER), help="Path to build_sample_sections.py.")
    parser.add_argument("--articles-path", default=str(DEFAULT_ARTICLES), help="Path to built articles.json.")
    parser.add_argument("--append-runlog", action="store_true", help="Append run record to maintenance-runlog.jsonl.")
    parser.add_argument("--runlog-path", default=str(DEFAULT_RUNLOG), help="Runlog jsonl path for --append-runlog.")
    parser.add_argument(
        "--runlog-retention-days",
        type=int,
        default=90,
        help="Keep runlog entries not older than N days (only with --append-runlog).",
    )
    parser.add_argument(
        "--runlog-max-lines",
        type=int,
        default=5000,
        help="Cap runlog size to latest N lines after retention pruning.",
    )
    parser.add_argument(
        "--skip-runlog-rotate",
        action="store_true",
        help="Skip rotation/pruning step for runlog.",
    )
    return parser.parse_args()


def run_build(builder_path: Path, mode: str, log_path: Path) -> Dict:
    cmd = [sys.executable, str(builder_path), "--mode", mode]
    proc = subprocess.run(cmd, cwd=str(REPO_ROOT), capture_output=True, text=True)
    output = proc.stdout + ("\n" + proc.stderr if proc.stderr else "")
    log_path.write_text(output, encoding="utf-8")
    return {
        "cmd": " ".join(cmd),
        "returncode": proc.returncode,
        "logPath": str(log_path),
    }


def build_snapshot(articles_path: Path, section: str, top_limit: int, sample_limit: int) -> Dict:
    articles = json.loads(articles_path.read_text(encoding="utf-8"))
    lib = [a for a in articles if a.get("section") == section]

    missing = [a for a in lib if not (a.get("topicLv3Key") or "").strip()]

    key_to_labels = defaultdict(set)
    label_to_keys = defaultdict(set)
    for a in lib:
        key = (a.get("topicLv3Key") or "").strip()
        label = (a.get("topicLv3Label") or "").strip()
        if key:
            key_to_labels[key].add(label)
        if label:
            label_to_keys[label].add(key)

    multi_labels = {k: sorted(v) for k, v in key_to_labels.items() if len(v) > 1}
    multi_keys = {k: sorted(v) for k, v in label_to_keys.items() if len(v) > 1}

    href_counter = Counter(a.get("href") for a in lib)
    duplicate_hrefs = {href: count for href, count in href_counter.items() if count > 1}

    node_coverage = defaultdict(lambda: [0, 0])
    for a in lib:
        node_key = (a.get("topicLv1Label") or "", a.get("topicLv2Label") or "")
        node_coverage[node_key][0] += 1
        if (a.get("topicLv3Key") or "").strip():
            node_coverage[node_key][1] += 1

    node_gaps: List[Dict] = []
    for (lv1, lv2), (total, with_lv3) in node_coverage.items():
        gap = total - with_lv3
        if gap > 0:
            node_gaps.append(
                {
                    "topicLv1Label": lv1,
                    "topicLv2Label": lv2,
                    "total": total,
                    "withLv3": with_lv3,
                    "missing": gap,
                }
            )
    node_gaps.sort(key=lambda x: x["missing"], reverse=True)

    lv3_counter = Counter((a.get("topicLv3Label") or "").strip() for a in lib)
    lv3_counter.pop("", None)

    critical = {
        "missingLv3": len(missing),
        "nodeGaps": len(node_gaps),
        "duplicateHref": len(duplicate_hrefs),
        "lv3KeyMultiLabel": len(multi_labels),
        "lv3LabelMultiKey": len(multi_keys),
    }
    critical_total = 0
    for value in critical.values():
        try:
            critical_total += int(value)
        except Exception:
            critical_total += 1
    passed = all(v == 0 for v in critical.values())
    severity = "CRIT" if not passed else "INFO"

    snapshot = {
        "generatedAt": datetime.now().strftime("%Y-%m-%d %H:%M:%S"),
        "section": section,
        "libraryTotal": len(lib),
        "withLv3": sum(1 for a in lib if (a.get("topicLv3Key") or "").strip()),
        "missingLv3Count": len(missing),
        "nodeGapCount": len(node_gaps),
        "duplicateHrefCount": len(duplicate_hrefs),
        "lv3KeyMultiLabelCount": len(multi_labels),
        "lv3LabelMultiKeyCount": len(multi_keys),
        "criticalIssues": critical,
        "criticalTotal": critical_total,
        "status": "PASS" if passed else "FAIL",
        "severity": severity,
        "topLv3Labels": lv3_counter.most_common(top_limit),
        "samples": {
            "missingLv3": [{"href": a.get("href"), "title": a.get("title")} for a in missing[:sample_limit]],
            "nodeGaps": node_gaps[:sample_limit],
            "duplicateHref": [
                {"href": href, "count": count}
                for href, count in sorted(duplicate_hrefs.items(), key=lambda kv: kv[1], reverse=True)[:sample_limit]
            ],
            "lv3KeyMultiLabel": [{"key": k, "labels": v} for k, v in sorted(multi_labels.items())[:sample_limit]],
            "lv3LabelMultiKey": [{"label": k, "keys": v} for k, v in sorted(multi_keys.items())[:sample_limit]],
        },
    }
    return snapshot


def snapshot_to_markdown(snapshot: Dict, build_meta: Dict | None) -> str:
    lines = [
        "# Lv3 Maintenance QA Snapshot",
        "",
        f"- Time: {snapshot['generatedAt']}",
        f"- Section: {snapshot['section']}",
        f"- Total: {snapshot['libraryTotal']}",
        f"- With Lv3: {snapshot['withLv3']}",
        f"- Missing Lv3: {snapshot['missingLv3Count']}",
        f"- Node gaps: {snapshot['nodeGapCount']}",
        f"- Duplicate href: {snapshot['duplicateHrefCount']}",
        f"- lv3 key -> multi label: {snapshot['lv3KeyMultiLabelCount']}",
        f"- lv3 label -> multi key: {snapshot['lv3LabelMultiKeyCount']}",
        f"- Critical total: {snapshot['criticalTotal']}",
        f"- Status: **{snapshot['status']}**",
        f"- Severity: **{snapshot.get('severity', 'UNKNOWN')}**",
    ]

    if build_meta:
        lines.extend(
            [
                "",
                "## Build step",
                f"- Command: `{build_meta['cmd']}`",
                f"- Return code: `{build_meta['returncode']}`",
                f"- Log: `{build_meta['logPath']}`",
            ]
        )

    lines.extend(["", "## Top Lv3 labels"])
    if snapshot["topLv3Labels"]:
        for label, count in snapshot["topLv3Labels"]:
            lines.append(f"- {label}: {count}")
    else:
        lines.append("- (none)")

    lines.extend(["", "## Critical issues"])
    for key, value in snapshot["criticalIssues"].items():
        lines.append(f"- {key}: {value}")

    if snapshot["samples"]["missingLv3"]:
        lines.extend(["", "## Sample missing Lv3"])
        for item in snapshot["samples"]["missingLv3"]:
            lines.append(f"- `{item['href']}` — {item['title']}")

    if snapshot["samples"]["nodeGaps"]:
        lines.extend(["", "## Node gaps"])
        for item in snapshot["samples"]["nodeGaps"]:
            lines.append(
                f"- {item['topicLv1Label']} > {item['topicLv2Label']}: "
                f"{item['withLv3']}/{item['total']} (missing {item['missing']})"
            )

    return "\n".join(lines) + "\n"


def append_runlog_entry(runlog_path: Path, snapshot: Dict, build_meta: Dict | None, json_path: Path, md_path: Path) -> None:
    runlog_path.parent.mkdir(parents=True, exist_ok=True)
    record = {
        "time": snapshot["generatedAt"],
        "section": snapshot["section"],
        "status": snapshot["status"],
        "severity": snapshot.get("severity", "UNKNOWN"),
        "libraryTotal": snapshot["libraryTotal"],
        "withLv3": snapshot["withLv3"],
        "criticalIssues": snapshot["criticalIssues"],
        "criticalTotal": snapshot.get("criticalTotal", 0),
        "artifacts": {
            "json": str(json_path),
            "md": str(md_path),
            "buildLog": build_meta["logPath"] if build_meta else "",
        },
        "build": build_meta or {"skipped": True},
    }
    with runlog_path.open("a", encoding="utf-8") as fh:
        fh.write(json.dumps(record, ensure_ascii=False) + "\n")


def rotate_runlog(runlog_path: Path, retention_days: int, max_lines: int) -> Dict:
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

    now = datetime.now()
    cutoff = now - timedelta(days=max(0, retention_days))
    kept_lines: List[str] = []
    removed_by_age = 0
    invalid_lines_kept = 0

    for line in raw_lines:
        text = line.strip()
        if not text:
            continue
        try:
            rec = json.loads(text)
            ts = rec.get("time", "")
            dt = datetime.strptime(ts, "%Y-%m-%d %H:%M:%S")
            if dt >= cutoff:
                kept_lines.append(text)
            else:
                removed_by_age += 1
        except Exception:
            # Keep invalid lines to avoid data loss, but count for visibility.
            kept_lines.append(text)
            invalid_lines_kept += 1

    removed_by_cap = 0
    if max_lines > 0 and len(kept_lines) > max_lines:
        removed_by_cap = len(kept_lines) - max_lines
        kept_lines = kept_lines[-max_lines:]

    if len(kept_lines) != len(raw_lines):
        payload = ("\n".join(kept_lines) + "\n") if kept_lines else ""
        runlog_path.write_text(payload, encoding="utf-8")

    return {
        "existed": True,
        "inputLines": len(raw_lines),
        "outputLines": len(kept_lines),
        "removedByAge": removed_by_age,
        "removedByCap": removed_by_cap,
        "invalidLinesKept": invalid_lines_kept,
    }


def main() -> int:
    args = parse_args()

    out_dir = Path(args.out_dir)
    out_dir.mkdir(parents=True, exist_ok=True)
    builder_path = Path(args.builder_path)
    articles_path = Path(args.articles_path)

    if not builder_path.exists():
        raise FileNotFoundError(f"Builder not found: {builder_path}")
    if not articles_path.exists():
        raise FileNotFoundError(f"Articles file not found: {articles_path}")

    build_meta = None
    if not args.skip_build:
        build_log_path = out_dir / f"maintenance-build-{args.tag}.log"
        build_meta = run_build(builder_path=builder_path, mode=args.mode, log_path=build_log_path)
        if build_meta["returncode"] != 0:
            print(f"Build failed. See log: {build_meta['logPath']}")
            return build_meta["returncode"]

    snapshot = build_snapshot(
        articles_path=articles_path,
        section=args.section,
        top_limit=args.top_limit,
        sample_limit=args.sample_limit,
    )

    json_path = out_dir / f"maintenance-qa-{args.tag}.json"
    md_path = out_dir / f"maintenance-qa-{args.tag}.md"
    json_path.write_text(json.dumps(snapshot, ensure_ascii=False, indent=2), encoding="utf-8")
    md_path.write_text(snapshot_to_markdown(snapshot, build_meta), encoding="utf-8")

    if args.append_runlog:
        runlog_path = Path(args.runlog_path)
        append_runlog_entry(runlog_path=runlog_path, snapshot=snapshot, build_meta=build_meta, json_path=json_path, md_path=md_path)
        rotation_meta = None
        if not args.skip_runlog_rotate:
            rotation_meta = rotate_runlog(
                runlog_path=runlog_path,
                retention_days=args.runlog_retention_days,
                max_lines=args.runlog_max_lines,
            )
    else:
        runlog_path = Path(args.runlog_path)
        rotation_meta = None

    print(f"status={snapshot['status']}")
    print(f"severity={snapshot.get('severity', 'UNKNOWN')}")
    print(f"json={json_path}")
    print(f"md={md_path}")
    if build_meta:
        print(f"build_log={build_meta['logPath']}")
    if args.append_runlog:
        print(f"runlog={runlog_path}")
        if rotation_meta:
            print(
                "runlog_rotation="
                f"input:{rotation_meta['inputLines']},"
                f"output:{rotation_meta['outputLines']},"
                f"age_removed:{rotation_meta['removedByAge']},"
                f"cap_removed:{rotation_meta['removedByCap']},"
                f"invalid_kept:{rotation_meta['invalidLinesKept']}"
            )
    print(
        "critical="
        + ",".join(
            f"{key}:{value}"
            for key, value in snapshot["criticalIssues"].items()
        )
    )

    return 0 if snapshot["status"] == "PASS" else 2


if __name__ == "__main__":
    raise SystemExit(main())
