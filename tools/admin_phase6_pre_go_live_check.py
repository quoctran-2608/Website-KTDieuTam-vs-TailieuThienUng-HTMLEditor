#!/usr/bin/env python3
"""
Admin Editor PHP pre-go-live quick checker (no PHP runtime required).

Goal:
- Run in 10-15 minutes before internal go-live.
- Validate critical files/artifacts and print compact checklist status.
"""

from __future__ import annotations

import argparse
import json
from dataclasses import dataclass
from pathlib import Path


@dataclass
class CheckResult:
    key: str
    ok: bool
    detail: str


def load_json(path: Path):
    return json.loads(path.read_text(encoding="utf-8"))


def check_exists(path: Path, key: str) -> CheckResult:
    return CheckResult(key=key, ok=path.exists(), detail=str(path))


def main() -> int:
    parser = argparse.ArgumentParser()
    parser.add_argument("--root", default=".", help="Project root")
    parser.add_argument(
        "--format", choices=("text", "json"), default="text", help="Output format"
    )
    args = parser.parse_args()

    root = Path(args.root).resolve()
    admin = root / "admin"
    storage = admin / "storage"
    docs = root / "docs" / "editor-php"

    required_files = [
        admin / "login.php",
        admin / "dashboard.php",
        admin / "articles.php",
        admin / "article.php",
        admin / "includes" / "article_parser.php",
        admin / "includes" / "article_draft.php",
        admin / "includes" / "article_publish.php",
        admin / "includes" / "healthcheck.php",
        admin / "assets" / "css" / "admin.css",
        root / "data" / "articles.json",
        docs / "phase6-hardening-runbook.md",
        docs / "phase-checklists.md",
    ]

    checks: list[CheckResult] = []
    for path in required_files:
        checks.append(check_exists(path, f"exists:{path.relative_to(root)}"))

    # Parse essential JSON artifacts when present
    parser_audit_path = storage / "parser-audit.json"
    drafts_path = storage / "article-drafts.json"
    publish_history_path = storage / "publish-history.json"
    backups_dir = storage / "backups"

    checks.append(
        CheckResult(
            key="storage:parser_audit_present_or_creatable",
            ok=parser_audit_path.exists() or storage.exists(),
            detail=str(parser_audit_path),
        )
    )
    checks.append(
        CheckResult(
            key="storage:drafts_present_or_creatable",
            ok=drafts_path.exists() or storage.exists(),
            detail=str(drafts_path),
        )
    )
    checks.append(
        CheckResult(
            key="storage:publish_history_present_or_creatable",
            ok=publish_history_path.exists() or storage.exists(),
            detail=str(publish_history_path),
        )
    )
    checks.append(
        CheckResult(
            key="storage:backups_present_or_creatable",
            ok=backups_dir.exists() or storage.exists(),
            detail=str(backups_dir),
        )
    )

    if parser_audit_path.exists():
        try:
            parser_audit = load_json(parser_audit_path)
            meta = parser_audit.get("meta", {})
            safe = int(meta.get("safe_count", 0))
            total = int(meta.get("total_count", 0))
            rate = float(meta.get("safe_rate_percent", 0))
            checks.append(
                CheckResult(
                    key="parser_audit:safe_rate>=98",
                    ok=(total > 0 and rate >= 98.0 and safe <= total),
                    detail=f"safe={safe}/{total}, rate={rate:.2f}%",
                )
            )
        except Exception as exc:
            checks.append(
                CheckResult(
                    key="parser_audit:json_valid",
                    ok=False,
                    detail=f"invalid json: {exc}",
                )
            )

    if drafts_path.exists():
        try:
            draft_payload = load_json(drafts_path)
            drafts = draft_payload.get("drafts", {})
            ok = isinstance(drafts, dict)
            count = len(drafts) if isinstance(drafts, dict) else -1
            checks.append(
                CheckResult(
                    key="drafts:shape_valid",
                    ok=ok,
                    detail=f"count={count}",
                )
            )
        except Exception as exc:
            checks.append(
                CheckResult(
                    key="drafts:json_valid",
                    ok=False,
                    detail=f"invalid json: {exc}",
                )
            )

    if publish_history_path.exists():
        try:
            history = load_json(publish_history_path)
            records = history.get("records", [])
            ok_shape = isinstance(records, list)
            checks.append(
                CheckResult(
                    key="publish_history:shape_valid",
                    ok=ok_shape,
                    detail=f"count={len(records) if ok_shape else -1}",
                )
            )

            trace_ok = True
            trace_detail = "no-record-yet"
            if ok_shape and records:
                latest = records[-1]
                if isinstance(latest, dict):
                    event = str(latest.get("event", ""))
                    trace_detail = f"latest_event={event}"
                    if event == "publish":
                        trace_ok = "backup_path" in latest and (
                            ("hash_before" in latest and "hash_after" in latest)
                            or "bytes_written" in latest
                        )
                    elif event == "rollback":
                        trace_ok = "restored_from" in latest
                else:
                    trace_ok = False
                    trace_detail = "latest-record-not-object"

            checks.append(
                CheckResult(
                    key="publish_history:trace_guardrail",
                    ok=trace_ok,
                    detail=trace_detail,
                )
            )
        except Exception as exc:
            checks.append(
                CheckResult(
                    key="publish_history:json_valid",
                    ok=False,
                    detail=f"invalid json: {exc}",
                )
            )

    # Checklist should indicate all phases completed
    checklist_path = docs / "phase-checklists.md"
    if checklist_path.exists():
        text = checklist_path.read_text(encoding="utf-8")
        phase6_done = "## Phase 6" in text and "- [x] smoke test end-to-end" in text
        checks.append(
            CheckResult(
                key="docs:phase6_checklist_done",
                ok=phase6_done,
                detail="phase6 checkboxes marked" if phase6_done else "phase6 checkboxes missing",
            )
        )

    failures = [c for c in checks if not c.ok]
    summary = {
        "total_checks": len(checks),
        "pass_checks": len(checks) - len(failures),
        "fail_checks": len(failures),
        "ok": len(failures) == 0,
    }

    if args.format == "json":
        payload = {
            "summary": summary,
            "checks": [c.__dict__ for c in checks],
        }
        print(json.dumps(payload, ensure_ascii=False, indent=2))
    else:
        for c in checks:
            marker = "OK" if c.ok else "FAIL"
            print(f"[{marker}] {c.key} :: {c.detail}")
        print(
            f"\nSummary: {summary['pass_checks']}/{summary['total_checks']} passed, "
            f"{summary['fail_checks']} failed"
        )
        print("Pre-go-live:", "READY" if summary["ok"] else "NOT READY")

    return 0 if summary["ok"] else 1


if __name__ == "__main__":
    raise SystemExit(main())
