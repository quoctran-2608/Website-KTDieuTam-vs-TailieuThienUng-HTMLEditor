#!/usr/bin/env python3
from __future__ import annotations

import argparse
import json
from collections import Counter
from datetime import datetime
from pathlib import Path

from moderation_log import LOG_FILE, load_events

ROOT = Path(__file__).resolve().parents[1]
REPORT_FILE = ROOT / "docs" / "nghien-cuu-tuyen-dung" / "bao-cao-audit-moderation-events.md"


def parse_day(value: str) -> datetime:
    return datetime.strptime(value, "%Y-%m-%d")


def main() -> None:
    parser = argparse.ArgumentParser(description="Audit event log moderation tuyển dụng")
    parser.add_argument("--day", default="", help="Lọc theo ngày UTC dạng YYYY-MM-DD")
    parser.add_argument("--actor", default="", help="Lọc theo actor")
    parser.add_argument("--limit", type=int, default=20, help="Số event mới nhất đưa vào report")
    args = parser.parse_args()

    events = load_events()
    issues: list[str] = []
    filtered: list[dict] = []
    expected_event_type = {"brief-moderation", "job-status-change"}
    expected_result = {"success", "failed"}

    day_filter = parse_day(args.day) if args.day else None
    actor_filter = args.actor.strip().lower()

    for idx, event in enumerate(events, start=1):
        label = event.get("eventId") or f"line-{idx}"
        event_type = str(event.get("eventType", "")).strip()
        result = str(event.get("result", "")).strip()
        logged_at = str(event.get("loggedAt", "")).strip()
        actor = str(event.get("actor", "")).strip()

        if not event.get("eventId"):
            issues.append(f"`{label}` thiếu eventId")
        if event_type not in expected_event_type:
            issues.append(f"`{label}` eventType không hợp lệ: {event_type}")
        if result not in expected_result:
            issues.append(f"`{label}` result không hợp lệ: {result}")
        if not actor:
            issues.append(f"`{label}` thiếu actor")
        if not logged_at:
            issues.append(f"`{label}` thiếu loggedAt")
            continue
        try:
            logged_at_dt = datetime.fromisoformat(logged_at.replace("Z", "+00:00"))
        except Exception:
            issues.append(f"`{label}` loggedAt sai format: {logged_at}")
            continue

        if day_filter and logged_at_dt.date() != day_filter.date():
            continue
        if actor_filter and actor.lower() != actor_filter:
            continue
        filtered.append(event)

    filtered_sorted = sorted(filtered, key=lambda item: item.get("loggedAt", ""), reverse=True)
    limited = filtered_sorted[: max(args.limit, 1)]
    event_counter = Counter(item.get("eventType", "") for item in filtered)
    result_counter = Counter(item.get("result", "") for item in filtered)

    lines = [
        "# Báo cáo audit moderation events",
        "",
        f"- Log file: `{LOG_FILE.relative_to(ROOT)}`",
        f"- Tổng event trong file: {len(events)}",
        f"- Event sau lọc: {len(filtered)}",
        f"- Tổng issue cấu trúc: {len(issues)}",
        f"- Filter day: `{args.day or 'none'}`",
        f"- Filter actor: `{args.actor or 'none'}`",
        "",
        "## Tổng hợp theo eventType",
        "",
    ]
    if not event_counter:
        lines.append("- Không có event sau lọc.")
    else:
        for key, count in sorted(event_counter.items(), key=lambda item: item[0]):
            lines.append(f"- `{key}`: {count}")

    lines += [
        "",
        "## Tổng hợp theo result",
        "",
    ]
    if not result_counter:
        lines.append("- Không có event sau lọc.")
    else:
        for key, count in sorted(result_counter.items(), key=lambda item: item[0]):
            lines.append(f"- `{key}`: {count}")

    lines += [
        "",
        "## Event mới nhất",
        "",
        "| loggedAt | eventType | result | actor | action | target |",
        "|---|---|---|---|---|---|",
    ]
    if not limited:
        lines.append("| - | - | - | - | - | - |")
    else:
        for event in limited:
            target = event.get("requestId") or event.get("jobSlug") or event.get("jobSourcePath") or "-"
            lines.append(
                f"| {event.get('loggedAt', '')} | {event.get('eventType', '')} | {event.get('result', '')} | {event.get('actor', '')} | {event.get('action', '')} | {target} |"
            )

    lines += [
        "",
        "## Issue cấu trúc log",
        "",
    ]
    if not issues:
        lines.append("- Không phát hiện issue cấu trúc log.")
    else:
        for issue in issues:
            lines.append(f"- {issue}")

    REPORT_FILE.write_text("\n".join(lines) + "\n", encoding="utf-8")
    print(REPORT_FILE.relative_to(ROOT))
    print(json.dumps({"events_total": len(events), "events_filtered": len(filtered), "issues": len(issues)}, ensure_ascii=False))


if __name__ == "__main__":
    main()
